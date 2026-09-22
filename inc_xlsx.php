<?php
/**
 * Doc file .xlsx (sheet dau tien) thanh mang cac dong, KHONG can extension ZipArchive.
 *
 * Vi sao khong dung ZipArchive: hosting nay khong co ext-zip (da kiem chung bang
 * class_exists('ZipArchive') === false). File .xlsx thuc chat la 1 file ZIP chua cac file XML
 * ben trong, nen tu viet 1 bo doc ZIP toi gian (chi doc, khong ghi) dua tren gzinflate() co san
 * trong moi ban PHP (thuoc zlib, hau nhu luon co san khong nhu ext-zip).
 *
 * Chi ho tro nen non toi thieu can cho file Excel xuat ra tu Excel/Google Sheets/LibreOffice
 * (khong ho tro file .xlsx co ma hoa mat khau, macro phuc tap...).
 */

/**
 * Doc toan bo cac entry trong 1 file ZIP thanh mang ['ten file trong zip' => noi dung da giai nen].
 * Doc truc tiep tu "End of Central Directory" record o cuoi file - cach lam viec chuan cua moi
 * bo doc ZIP toi gian, khong can duyet tuan tu tung local file header.
 */
function xlsxReadZipEntries(string $filePath): array
{
    $data = file_get_contents($filePath);
    if ($data === false) {
        throw new RuntimeException('Không đọc được file');
    }
    $len = strlen($data);

    // Tim "End of Central Directory" (EOCD), signature 0x06054b50, o gan cuoi file (co the co
    // truong "comment" dai toi da 65535 byte sau EOCD nen phai do nguoc tu cuoi).
    $eocdPos = strrpos($data, "\x50\x4b\x05\x06");
    if ($eocdPos === false) {
        throw new RuntimeException('File không đúng định dạng .xlsx (không tìm thấy cấu trúc ZIP)');
    }

    $cdOffset = unpack('V', substr($data, $eocdPos + 16, 4))[1];
    $cdEntryCount = unpack('v', substr($data, $eocdPos + 10, 2))[1];

    $entries = [];
    $pos = $cdOffset;
    for ($i = 0; $i < $cdEntryCount; $i++) {
        if (substr($data, $pos, 4) !== "\x50\x4b\x01\x02") {
            break; // cau truc khong nhu mong doi, dung lai thay vi doc sai du lieu
        }
        $compMethod = unpack('v', substr($data, $pos + 10, 2))[1];
        $compSize = unpack('V', substr($data, $pos + 20, 4))[1];
        $nameLen = unpack('v', substr($data, $pos + 28, 2))[1];
        $extraLen = unpack('v', substr($data, $pos + 30, 2))[1];
        $commentLen = unpack('v', substr($data, $pos + 32, 2))[1];
        $localHeaderOffset = unpack('V', substr($data, $pos + 42, 4))[1];
        $name = substr($data, $pos + 46, $nameLen);

        // Doc local file header de biet chinh xac vi tri du lieu that su bat dau (ten file/extra
        // o local header co the dai khac local header trong central directory).
        $localNameLen = unpack('v', substr($data, $localHeaderOffset + 26, 2))[1];
        $localExtraLen = unpack('v', substr($data, $localHeaderOffset + 28, 2))[1];
        $dataStart = $localHeaderOffset + 30 + $localNameLen + $localExtraLen;
        $rawContent = substr($data, $dataStart, $compSize);

        if ($compMethod === 8) {
            $content = @gzinflate($rawContent);
        } elseif ($compMethod === 0) {
            $content = $rawContent;
        } else {
            $content = false; // nen khong ho tro (hiem gap trong file .xlsx thuc te)
        }
        if ($content !== false) {
            $entries[$name] = $content;
        }

        $pos += 46 + $nameLen + $extraLen + $commentLen;
    }

    return $entries;
}

/** Chuyen chi so cot dang "A", "B", ... "AA" thanh so thu tu (0-based). */
function xlsxColumnToIndex(string $col): int
{
    $index = 0;
    foreach (str_split($col) as $char) {
        $index = $index * 26 + (ord($char) - ord('A') + 1);
    }
    return $index - 1;
}

/**
 * Doc sheet dau tien cua file .xlsx thanh mang 2 chieu (moi dong la 1 mang gia tri dang string),
 * tu dong tra shared strings. Cac o trong duoc dien '' de giu dung vi tri cot.
 */
function readXlsxRows(string $filePath): array
{
    $entries = xlsxReadZipEntries($filePath);

    if (!isset($entries['xl/worksheets/sheet1.xml'])) {
        throw new RuntimeException('Không tìm thấy sheet nào trong file .xlsx');
    }

    // Bang chuoi dung chung (shared strings) - Excel luu chuoi lap lai (vd ten cot) 1 lan duy
    // nhat o day, cac o chi tham chieu so thu tu vao bang nay thay vi ghi lai toan bo chuoi.
    $sharedStrings = [];
    if (isset($entries['xl/sharedStrings.xml'])) {
        $xml = @simplexml_load_string($entries['xl/sharedStrings.xml']);
        if ($xml !== false) {
            foreach ($xml->si as $si) {
                // <si> co the chua truc tiep <t> hoac nhieu <r><t> (rich text nhieu dinh dang) -
                // gop het lai thanh 1 chuoi phang, du dung cho muc dich nhap lieu.
                if (isset($si->t)) {
                    $sharedStrings[] = (string) $si->t;
                } else {
                    $parts = [];
                    foreach ($si->r as $r) {
                        $parts[] = (string) $r->t;
                    }
                    $sharedStrings[] = implode('', $parts);
                }
            }
        }
    }

    $sheetXml = @simplexml_load_string($entries['xl/worksheets/sheet1.xml']);
    if ($sheetXml === false) {
        throw new RuntimeException('Không đọc được nội dung sheet trong file .xlsx');
    }

    $rows = [];
    foreach ($sheetXml->sheetData->row as $rowEl) {
        $rowValues = [];
        $maxCol = -1;
        $cellValues = [];
        foreach ($rowEl->c as $cellEl) {
            $ref = (string) $cellEl['r']; // vd "C5" - chu la cot, so la dong
            $colLetters = preg_replace('/[0-9]+/', '', $ref);
            $colIndex = xlsxColumnToIndex($colLetters);
            $type = (string) $cellEl['t'];

            if ($type === 's') {
                $idx = (int) $cellEl->v;
                $value = $sharedStrings[$idx] ?? '';
            } elseif ($type === 'inlineStr') {
                $value = (string) $cellEl->is->t;
            } else {
                $value = (string) $cellEl->v;
            }

            $cellValues[$colIndex] = $value;
            $maxCol = max($maxCol, $colIndex);
        }
        for ($c = 0; $c <= $maxCol; $c++) {
            $rowValues[] = $cellValues[$c] ?? '';
        }
        $rows[] = $rowValues;
    }

    return $rows;
}

/**
 * Doc file nhap lieu (CSV hoac XLSX, tu dong nhan biet theo duoi file) thanh mang 2 chieu dong
 * dang chuoi - dung chung cho moi man hinh "Nhap file" (san pham/khach hang/ton kho...) de khong
 * phai viet lai logic doc file o tung noi.
 */
function readImportRows(string $tmpPath, string $originalFilename): array
{
    $ext = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));

    if ($ext === 'xlsx') {
        return readXlsxRows($tmpPath);
    }

    // Mac dinh coi la CSV (kem truong hop khong nhan dien duoc duoi file).
    $handle = fopen($tmpPath, 'r');
    if (!$handle) {
        throw new RuntimeException('Không đọc được file');
    }
    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF") {
        rewind($handle);
    }
    $rows = [];
    while (($row = fgetcsv($handle)) !== false) {
        $rows[] = $row;
    }
    fclose($handle);
    return $rows;
}
