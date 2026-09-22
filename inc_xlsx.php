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
 * Doc file .xlsx nguoi dung tai len thanh mang 2 chieu dong dang chuoi - dung chung cho moi man
 * hinh "Nhap file" (san pham/khach hang/ton kho...) de khong phai viet lai logic doc file o tung
 * noi. CHI nhan .xlsx (khong con nhan CSV) - thong nhat 1 dinh dang duy nhat, tranh nguoi dung
 * nham lan giua 2 dinh dang khi Excel de xuat "Save As CSV" hay lam vo dau tieng Viet neu chon
 * sai encoding.
 */
function readImportRows(string $tmpPath, string $originalFilename): array
{
    $ext = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
    if ($ext !== 'xlsx') {
        throw new RuntimeException('Chỉ nhận file Excel (.xlsx). Vui lòng chọn đúng định dạng file.');
    }
    return readXlsxRows($tmpPath);
}

// ============================================================================
// GHI file .xlsx (dung cho cac man hinh "Xuat file") - cung KHONG dung ZipArchive, tu dung ZIP
// bang gzdeflate() (nguoc lai voi gzinflate() dung o phan doc phia tren).
// ============================================================================

/**
 * Kiem tra 1 chuoi co nen ghi vao Excel duoi dang SO THAT hay khong. Khac voi is_numeric() don
 * thuan: co CHU Y loai tru cac chuoi so bat dau bang "0" theo sau la chu so khac (vd so dien
 * thoai "0901234567", ma vach "0123456789012") - so thuc khong bao gio viet voi so 0 dau vo
 * nghia, nen day chac chan la du lieu dang TEXT can giu nguyen dinh dang (mat so 0 dau la loi
 * xuat file rat pho bien va de bi bo sot).
 */
function xlsxIsPureNumber(string $value): bool
{
    if (!is_numeric($value)) {
        return false;
    }
    return !preg_match('/^-?0[0-9]/', $value);
}

/** Escape 1 gia tri de nhung an toan vao XML (dung cho ten sheet, noi dung o...). */
function xlsxEscapeXml(string $value): string
{
    return str_replace(
        ['&', '<', '>', '"', "'"],
        ['&amp;', '&lt;', '&gt;', '&quot;', '&apos;'],
        $value
    );
}

/**
 * Dong goi danh sach file (['duong dan trong zip' => noi dung]) thanh 1 file ZIP hop le (dang
 * byte thô) - tu viet local file header + central directory + EOCD theo dung chuan ZIP, khong
 * dung ZipArchive (khong co tren hosting nay). Nen bang gzdeflate() (DEFLATE thuan, method=8).
 */
function xlsxBuildZip(array $files): string
{
    $localParts = [];
    $centralParts = [];
    $offset = 0;

    foreach ($files as $name => $content) {
        $crc = crc32($content);
        $compressed = gzdeflate($content, 6);
        $uncompressedSize = strlen($content);
        $compressedSize = strlen($compressed);
        $nameLen = strlen($name);

        $localHeader = "\x50\x4b\x03\x04" // signature
            . pack('v', 20)   // version needed
            . pack('v', 0)    // flags
            . pack('v', 8)    // method = DEFLATE
            . pack('V', 0)    // mod time+date (khong quan trong, khong anh huong doc file)
            . pack('V', $crc)
            . pack('V', $compressedSize)
            . pack('V', $uncompressedSize)
            . pack('v', $nameLen)
            . pack('v', 0)    // extra length
            . $name;

        $localParts[] = $localHeader . $compressed;

        $centralParts[] = "\x50\x4b\x01\x02"
            . pack('v', 20)   // version made by
            . pack('v', 20)   // version needed
            . pack('v', 0)    // flags
            . pack('v', 8)    // method
            . pack('V', 0)    // mod time+date
            . pack('V', $crc)
            . pack('V', $compressedSize)
            . pack('V', $uncompressedSize)
            . pack('v', $nameLen)
            . pack('v', 0)    // extra length
            . pack('v', 0)    // comment length
            . pack('v', 0)    // disk number
            . pack('v', 0)    // internal attrs
            . pack('V', 0)    // external attrs
            . pack('V', $offset) // offset cua local header
            . $name;

        $offset += strlen($localHeader) + $compressedSize;
    }

    $centralDir = implode('', $centralParts);
    $centralDirOffset = $offset;
    $centralDirSize = strlen($centralDir);
    $count = count($files);

    $eocd = "\x50\x4b\x05\x06"
        . pack('v', 0)          // disk number
        . pack('v', 0)          // disk voi central directory
        . pack('v', $count)     // so entry tren disk nay
        . pack('v', $count)     // tong so entry
        . pack('V', $centralDirSize)
        . pack('V', $centralDirOffset)
        . pack('v', 0);         // comment length

    return implode('', $localParts) . $centralDir . $eocd;
}

/**
 * Tao noi dung file .xlsx (1 sheet) tu mang 2 chieu $rows (dong dau tien la tieu de). Gia tri so
 * (is_numeric) duoc ghi dang so that trong Excel, con lai ghi dang chuoi (inlineStr - khong can
 * bang sharedStrings rieng, don gian hoa dang ke bo ghi). Tra ve chuoi byte, dung ghi thang ra
 * file hoac echo ra trinh duyet voi header Content-Type dung.
 */
function buildXlsxContent(array $rows): string
{
    $sheetRows = '';
    foreach ($rows as $rowIndex => $row) {
        $r = $rowIndex + 1;
        $cells = '';
        foreach (array_values($row) as $colIndex => $value) {
            $colLetter = xlsxIndexToColumn($colIndex);
            $ref = $colLetter . $r;
            $value = (string) ($value ?? '');
            if ($value !== '' && xlsxIsPureNumber($value)) {
                $cells .= "<c r=\"{$ref}\"><v>" . xlsxEscapeXml($value) . '</v></c>';
            } else {
                $cells .= "<c r=\"{$ref}\" t=\"inlineStr\"><is><t xml:space=\"preserve\">" . xlsxEscapeXml($value) . '</t></is></c>';
            }
        }
        $sheetRows .= "<row r=\"{$r}\">{$cells}</row>";
    }

    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . "<sheetData>{$sheetRows}</sheetData>"
        . '</worksheet>';

    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';

    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '</Relationships>';

    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '</Types>';

    return xlsxBuildZip([
        '[Content_Types].xml' => $contentTypes,
        '_rels/.rels' => $rootRels,
        'xl/workbook.xml' => $workbookXml,
        'xl/_rels/workbook.xml.rels' => $workbookRels,
        'xl/worksheets/sheet1.xml' => $sheetXml,
    ]);
}

/** Chuyen so thu tu cot (0-based) thanh chu cai "A", "B", ... "AA" - nguoc lai voi xlsxColumnToIndex(). */
function xlsxIndexToColumn(int $index): string
{
    $col = '';
    $index++;
    while ($index > 0) {
        $rem = ($index - 1) % 26;
        $col = chr(ord('A') + $rem) . $col;
        $index = intdiv($index - 1, 26);
    }
    return $col;
}

/** Xuat mang 2 chieu $rows thanh file .xlsx tai xuong ngay (dat header roi echo, exit luon). */
function downloadXlsx(array $rows, string $filename): void
{
    $content = buildXlsxContent($rows);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($content));
    echo $content;
    exit;
}
