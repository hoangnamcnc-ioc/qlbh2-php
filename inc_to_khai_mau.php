<?php
/**
 * Bo mau to khai thue ho kinh doanh theo dung bo cuc mau chinh thuc (TT 18/2026, 50/2026, 89/2026/TT-BTC, ND 398/2026).
 * Moi mau la mang: code, ref, form_label, title, subtitle[], choices[], choices_first, period, extra[], period_lines[], header[], unit,
 * tables[{heading,before[],groups[[nhan,span]],columns[{label,code,num}],rows[{type,cells}],after[]}], footnotes[], notes[], pledge, agent, sign_note.
 * Kieu dong: row | group (1 o tron hang) | total | sum | span (o cuoi tran het cac cot con lai).
 */

function tkCol(string $label, string $code = '', bool $num = false): array
{
    return ['label' => $label, 'code' => $code, 'num' => $num];
}

function tkTicks(array $items): array
{
    return array_map(fn($label, $on) => ($on ? '☑ ' : '☐ ') . $label, array_keys($items), $items);
}

function tkRep(array $nnt, string $code, string $ref, string $title, array $o = []): array
{
    return $o + [
        'code' => $code, 'ref' => $ref, 'form_label' => 'Mẫu số: ' . $code, 'title' => $title, 'subtitle' => [], 'choices' => [], 'choices_first' => false,
        'period' => '', 'extra' => [], 'period_lines' => [],
        'header' => ['[04] Người nộp thuế: ' . $nnt['chu'] . ' (hộ kinh doanh: ' . $nnt['ten'] . ')', '[05] Mã số thuế: ' . ($nnt['mst'] ?: '……………'), 'Địa chỉ: ' . ($nnt['dia_chi'] ?: '……………')],
        'unit' => 'Đơn vị tiền: Đồng Việt Nam', 'tables' => [], 'footnotes' => [], 'notes' => [],
        'pledge' => 'Tôi cam đoan những nội dung kê khai trên là đúng và chịu trách nhiệm trước pháp luật về những nội dung đã khai./.',
        'agent' => 'tt18', 'sign_note' => '(Ký, ghi rõ họ tên/ Ký điện tử)', 'signer' => $nnt['chu'],
    ];
}

function tkBlank(string $heading, array $cols, array $rows, array $groups = [], array $before = []): array
{
    $n = count($cols);
    return ['heading' => $heading, 'before' => $before, 'groups' => $groups, 'after' => [],
        'columns' => array_map(fn($c) => tkCol($c[0], $c[1] ?? ''), $cols),
        'rows' => array_map(fn($r) => is_string($r) ? ['type' => 'group', 'cells' => [$r]] : ['type' => $r['type'] ?? 'row', 'cells' => array_pad($r['cells'] ?? $r, $n, '')], $rows)];
}

function tkNguoiNopThue(array $nnt, string $auth, string $agent): array
{
    return ['[04] Người nộp thuế: ' . $nnt['chu'] . ' (hộ kinh doanh: ' . $nnt['ten'] . ')', '[05] Mã số thuế: ' . ($nnt['mst'] ?: '……………'),
        "[$auth] Tổ chức/cá nhân khai, nộp thuế thay theo ủy quyền (nếu có): ……………", "[$auth.1] Mã số thuế: ……………", "[$auth.2] Văn bản ủy quyền (nếu có): Số ……… ngày … tháng … năm …",
        "[$agent] Tên đại lý thuế (nếu có): ……………", "[$agent.1] Mã số thuế: ……………"];
}

/** Phan B, C, D cua mau 01/CNKD (TT 50/2026): thue TTDB, tai nguyen - BVMT, ho tro thong tin nop thue; ho tu dien. */
function tkCnkdPhanBCD(): array
{
    return [
        tkBlank('B. KÊ KHAI THUẾ TIÊU THỤ ĐẶC BIỆT', [['STT', '(1)'], ['Hàng hóa, dịch vụ chịu thuế TTĐB', '(2)'], ['Mã chỉ tiêu', '(3)'], ['Đơn vị tính', '(4)'], ['Doanh thu tính thuế TTĐB', '(5)'], ['Thuế suất', '(6)'], ['Số thuế phải nộp', '(7)=(5)*(6)']], [
            ['cells' => ['I', 'Trụ sở kinh doanh:']], ['cells' => ['1', 'Hàng hóa, dịch vụ A', '[21a]']], ['cells' => ['2', 'Hàng hóa, dịch vụ B', '[21b]']],
            ['cells' => ['II', 'Mã địa điểm kinh doanh 1: …… Tên địa điểm kinh doanh 1: ……']],
            ['type' => 'total', 'cells' => ['', 'Tổng cộng:', '[22]']], ['cells' => ['', 'Số thuế được miễn', '[23]']], ['type' => 'total', 'cells' => ['', 'Số thuế còn phải nộp', '[24]']],
        ]),
        tkBlank('C. KÊ KHAI THUẾ TÀI NGUYÊN, BẢO VỆ MÔI TRƯỜNG, PHÍ BẢO VỆ MÔI TRƯỜNG', [['STT', '(1)'], ['Tài nguyên, hàng hóa, sản phẩm', '(2)'], ['Trụ sở kinh doanh/Mã địa điểm kinh doanh', '(3)'], ['Tên địa điểm kinh doanh', '(4)'], ['Mã chỉ tiêu', '(5)'], ['Đơn vị tính', '(6)'], ['Sản lượng/Số lượng', '(7)'], ['Giá tính thuế tài nguyên/mức thuế hoặc phí BVMT', '(8)'], ['Thuế suất/Hệ số K tính phí BVMT', '(9)'], ['Số thuế/phí phải nộp', '(10)']], [
            'I. Khai thuế tài nguyên', ['cells' => ['1', 'Tài nguyên C..', '', '', '[25a]', '', '', '', '', '(10)=(7)*(8)*(9)']], ['cells' => ['2', 'Tài nguyên D..', '', '', '[25b]']],
            ['type' => 'total', 'cells' => ['', 'Tổng cộng', '', '', '', '', '', '', '', '[26]']], ['cells' => ['', 'Số thuế được miễn', '', '', '', '', '', '', '', '[27]']], ['cells' => ['', 'Số thuế còn phải nộp', '', '', '', '', '', '', '', '[28]']],
            'II. Khai thuế bảo vệ môi trường', ['cells' => ['2.1', 'Hàng hóa E...', '', '', '[29a]', '', '', '', '', '(10)=(7)*(8)']], ['cells' => ['2.2', 'Hàng hóa G...', '', '', '[29b]']],
            ['type' => 'total', 'cells' => ['', 'Tổng cộng', '', '', '', '', '', '', '', '[30]']], ['cells' => ['', 'Số thuế được miễn', '', '', '', '', '', '', '', '[31]']], ['cells' => ['', 'Số thuế còn phải nộp', '', '', '', '', '', '', '', '[32]']],
            'III. Khai phí bảo vệ môi trường đối với khai thác khoáng sản', ['cells' => ['3.1', 'Khoáng sản H...', '', '', '[33a]', '', '', '', '', '(10)=(7)*(8)*(9)']], ['cells' => ['3.2', 'Khoáng sản K...', '', '', '[33b]']],
            ['type' => 'total', 'cells' => ['', 'Tổng cộng', '', '', '', '', '', '', '', '[34]']], ['cells' => ['', 'Số phí được miễn', '', '', '', '', '', '', '', '[35]']], ['cells' => ['', 'Số phí còn phải nộp', '', '', '', '', '', '', '', '[36]']],
        ]),
        tkBlank('D. HỖ TRỢ TỰ ĐỘNG THÔNG TIN NỘP THUẾ', [['STT', '[37]'], ['Mã địa điểm kinh doanh', '[38]'], ['Nội dung các khoản nộp NSNN', '[39]'], ['Số tiền', '[40]'], ['Chương', '[41]'], ['Tiểu mục', '[42]'], ['Địa bàn hành chính', '[43]'], ['Cơ quan thu', '[44]'], ['Cơ quan thuế', '[45]'], ['Hạn nộp thuế', '[46]']], [
            ['cells' => ['…']], ['type' => 'total', 'cells' => ['', '', 'Tổng cộng', '[47]']],
        ]),
    ];
}

/** Mau 01/CNKD cho mot quy. $f = ket qua tkQuy(). */
function tkMauCnkd(array $nnt, int $nam, int $quy, array $f, ?int $first, string $nhomThue): array
{
    $m = fn($v) => (int) round($v);
    $cols = [tkCol('STT', '[08]'), tkCol('Nhóm ngành nghề', '[09]'), tkCol('Mã chỉ tiêu', '[10]'), tkCol('Tổng doanh thu', '[11]', true), tkCol('Trong đó: doanh thu không chịu thuế GTGT', '[12]', true),
        tkCol('Trong đó: doanh thu chịu thuế suất 0%', '[13]', true), tkCol('Số thuế phải nộp', '[14]', true), tkCol('Doanh thu chịu thuế', '[15]', true),
        tkCol('Doanh thu được trừ để xác định doanh thu tính thuế', '[16]', true), tkCol('Số thuế phải nộp', '[17]', true)];
    $nhom = [['1.1', 'Phân phối, cung cấp hàng hóa', '(a)', true], ['1.2', 'Dịch vụ, xây dựng không bao thầu nguyên vật liệu', '(b)', false], ['1.3', 'Hoạt động cho thuê tài sản trừ bất động sản', '(c)', false],
        ['1.4', 'Sản xuất, vận tải, dịch vụ có gắn với hàng hóa, xây dựng có bao thầu nguyên vật liệu', '(d)', false],
        ['1.5', 'Hoạt động cung cấp sản phẩm nội dung thông tin số về giải trí, trò chơi điện tử, phim số, ảnh số, nhạc số, quảng cáo số', '(đ)', false], ['1.6', 'Hoạt động kinh doanh khác', '(e)', false]];
    $rows = [['type' => 'group', 'cells' => ['I. Hoạt động sản xuất, kinh doanh hàng hóa, cung cấp dịch vụ có địa điểm kinh doanh cố định (1. Trụ sở kinh doanh)']]];
    foreach ($nhom as [$stt, $ten, $ch, $co]) {
        $rows[] = ['type' => 'row', 'cells' => [$stt, $ten, $ch, $co ? $m($f['total']) : 0, $co ? $m($f['exempt']) : 0, 0, $co ? $m($f['vat']) : 0, $co ? $m($f['base']) : 0, $co ? $m($f['ded']) : 0, $co ? $m($f['pit']) : 0]];
    }
    $rows[] = ['type' => 'group', 'cells' => ['II. Hoạt động kinh doanh trên nền tảng thương mại điện tử, nền tảng số khác không có chức năng đặt hàng trực tuyến và chức năng thanh toán: hộ tự bổ sung nếu có']];
    $rows[] = ['type' => 'group', 'cells' => ['III. Hoạt động cấp hóa đơn điện tử có mã của cơ quan thuế theo từng lần phát sinh: không áp dụng cho kỳ khai theo quý']];
    $rows[] = ['type' => 'total', 'cells' => ['IV', 'Tổng cộng', '[18]', $m($f['total']), $m($f['exempt']), 0, $m($f['vat']), $m($f['base']), $m($f['ded']), $m($f['pit'])]];
    $rows[] = ['type' => 'sum', 'cells' => ['V', 'Số thuế được miễn', '[19]', '', '', '', 0, '', '', 0]];
    $rows[] = ['type' => 'total', 'cells' => ['VI', 'Số thuế còn phải nộp', '[20]', '', '', '', $m($f['vat']), '', '', $m($f['pit'])]];
    $notes = ['Bản điền sẵn từ đơn hàng đã ghi sổ (doanh thu thuần, đã trừ hàng trả lại) để đối chiếu và nhập vào ứng dụng kê khai của cơ quan thuế. Cửa hàng được xếp vào nhóm (a) phân phối, cung cấp hàng hóa: GTGT 1%, TNCN 0,5%.'];
    if (!$f['liable']) {
        $notes[] = $first === null
            ? 'Doanh thu lũy kế năm ' . $nam . ' chưa vượt 1 tỷ đồng: chưa phải nộp thuế GTGT, TNCN; hộ chỉ nộp thông báo doanh thu năm (Mẫu 01/TKN-CNKD).'
            : 'Quý ' . $quy . ' trước quý phát sinh nghĩa vụ (quý ' . $first . '): chưa phải khai thuế; mẫu này chỉ để tham khảo.';
    } elseif ($quy === $first) {
        $notes[] = 'Quý ' . $quy . ' là quý doanh thu lũy kế vượt 1 tỷ đồng: doanh thu tính thuế là doanh thu lũy kế từ đầu năm, các quý sau tính theo từng quý (NĐ 68/2026 Điều 8, 9 đã sửa bởi NĐ 141/2026).';
    }
    $notes[] = $nhomThue === '3'
        ? 'Hộ tính TNCN trên thu nhập: số thuế TNCN [17] là số tạm nộp theo quý = 0,5% × doanh thu tính thuế (NĐ 68/2026 Điều 10 khoản 2 điểm b); cột [16] không có số trừ. Quyết toán cả năm dùng Mẫu 02/CNKD-TNCN-QTT.'
        : 'Hộ tính TNCN theo % doanh thu: cột [16] là phần 1 tỷ đồng được trừ, tính ở quý đầu tiên phát sinh nghĩa vụ (NĐ 68/2026 Điều 4 khoản 3).';
    $notes[] = 'Chưa có dữ liệu trong phần mềm: doanh thu theo từng địa điểm kinh doanh, hoạt động trên sàn không có chức năng thanh toán, thuế tiêu thụ đặc biệt, tài nguyên, bảo vệ môi trường (mục B, C; dùng sổ S3a), số thuế được miễn, phần D. Hộ tự bổ sung khi nhập vào ứng dụng của cơ quan thuế.';
    $con = $f['liable'] && $nhomThue === '2' ? max(0.0, 1000000000 - $f['ded']) : 0.0;
    return tkRep($nnt, '01/CNKD', '(Kèm theo Thông tư số 50/2026/TT-BTC ngày 13/5/2026 của Bộ trưởng Bộ Tài chính)', 'TỜ KHAI THUẾ ĐỐI VỚI HỘ KINH DOANH, CÁ NHÂN KINH DOANH', [
        'subtitle' => ['(Áp dụng cho hộ kinh doanh, cá nhân kinh doanh có doanh thu năm trên 01 tỷ đồng)'],
        'choices' => tkTicks([
            'Hộ kinh doanh, cá nhân kinh doanh thuộc đối tượng nộp thuế TNCN trên doanh thu tính thuế' => $nhomThue !== '3',
            'Hộ kinh doanh, cá nhân kinh doanh thuộc đối tượng nộp thuế TNCN trên thu nhập tính thuế' => $nhomThue === '3',
            'Hộ kinh doanh, cá nhân kinh doanh chỉ có hoạt động kinh doanh trên nền tảng thương mại điện tử, nền tảng số khác không có chức năng đặt hàng trực tuyến và chức năng thanh toán' => false,
            'Hộ kinh doanh, cá nhân kinh doanh khai các loại thuế khác (thuế TTĐB, thuế tài nguyên, thuế/phí bảo vệ môi trường)' => false,
            'Trường hợp đề nghị cấp hóa đơn điện tử có mã của cơ quan thuế theo lần phát sinh' => false]),
        'choices_first' => true, 'period' => '[01] Kỳ tính thuế: [01b] Quý ' . $quy . ' năm ' . $nam, 'period_lines' => ['[02] Lần đầu: ☑   [03] Bổ sung lần thứ: ....'],
        'header' => tkNguoiNopThue($nnt, '06', '07'),
        'tables' => array_merge([['heading' => 'A. KÊ KHAI THUẾ GTGT, TNCN', 'before' => [], 'after' => [], 'columns' => $cols, 'rows' => $rows, 'groups' => [[['', 3], ['Thuế GTGT', 4], ['Thuế TNCN', 3]]]]], tkCnkdPhanBCD()),
        'footnotes' => array_values(array_filter([$con > 0 ? 'Bạn còn được tiếp tục trừ ' . number_format($con, 0, ',', '.') . ' đồng vào doanh thu tính thuế TNCN của kỳ tiếp theo' : null,
            'Ghi chú: TNCN: Thu nhập cá nhân; GTGT: Giá trị gia tăng; TTĐB: Tiêu thụ đặc biệt; BVMT: Bảo vệ môi trường.',
            '- Chỉ tiêu [01a]: dành cho hộ kinh doanh, cá nhân kinh doanh có doanh thu năm trên 50 tỷ đồng. Chỉ tiêu [01b]: dành cho hộ kinh doanh, cá nhân kinh doanh có doanh thu năm trên 01 tỷ đồng đến 50 tỷ đồng. Chỉ tiêu [01c]: dành cho hộ kinh doanh, cá nhân kinh doanh thực hiện khai và nộp thuế trước khi được cơ quan thuế cấp hóa đơn điện tử có mã của cơ quan thuế theo từng lần phát sinh.',
            '- Số thuế được miễn: là số tiền thuế phát sinh phải nộp được miễn theo mức quy định của Chính phủ.'])),
        'notes' => $notes, 'sign_note' => '(Ký, ghi rõ họ tên/ Ký điện tử)',
    ]);
}

/** Mau 02/CNKD-TNCN-QTT (TT 89/2026): ho tinh TNCN tren thu nhap. */
function tkMauQtt(array $nnt, int $nam, array $d): array
{
    $r = fn($v) => (int) round($v);
    $R = fn(string $s, string $nd, string $ma, $v, bool $tong = false) => ['type' => $tong ? 'total' : 'row', 'cells' => [$s, $nd, $ma, $v]];
    $c = $d['c'];
    $rows = [
        $R('1', 'Doanh thu bán hàng hoá và cung cấp dịch vụ [09] = [09a]+[09b]+[09c]', '[09]', $r($d['rev'])),
        $R('1.1', 'Doanh thu từ hoạt động sản xuất kinh doanh không bao gồm các hoạt động kinh doanh trên nền tảng TMĐT, nền tảng số', '[09a]', $r($d['rev'])),
        $R('1.2', 'Doanh thu từ hoạt động kinh doanh trên nền tảng TMĐT, nền tảng số đã được khấu trừ, nộp thay', '[09b]', 0),
        $R('1.3', 'Doanh thu từ hoạt động kinh doanh trên nền tảng TMĐT, nền tảng số không có chức năng thanh toán trực tiếp', '[09c]', 0),
        $R('2', 'Các khoản chi được trừ [10] = [10a]+[10b]+[10c]+[10d]+[10đ]+[10e]', '[10]', $r($d['chi'])),
        $R('2.1', 'Chi phí nguyên liệu, vật liệu, nhiên liệu, năng lượng, hàng hóa sử dụng vào sản xuất, kinh doanh', '[10a]', $r($c['a'])),
        $R('2.2', 'Chi phí tiền lương, tiền công, các khoản phụ cấp, bảo hiểm bắt buộc và các khoản chi trả cho người lao động có đóng bảo hiểm bắt buộc theo quy định', '[10b]', $r($c['b'])),
        $R('2.3', 'Chi phí khấu hao tài sản cố định phục vụ cho hoạt động sản xuất, kinh doanh', '[10c]', $r($c['c'])),
        $R('2.4', 'Chi phí dịch vụ mua ngoài như điện, nước, điện thoại, internet, vận chuyển, thuê tài sản, sửa chữa, bảo dưỡng', '[10d]', $r($c['d'])),
        $R('2.5', 'Chi phí trả lãi tiền vay vốn sản xuất, kinh doanh', '[10đ]', $r($c['dd'])),
        $R('2.6', 'Các khoản chi khác phục vụ trực tiếp hoạt động sản xuất, kinh doanh', '[10e]', $r($c['e'])),
        $R('3', 'Thu nhập tính thuế [11] = [09] − [10]', '[11]', $r($d['thu_nhap']), true),
        $R('4', 'Thuế suất (%)', '[12]', rtrim(rtrim(number_format($d['suat'] * 100, 2, ',', ''), '0'), ',')),
        $R('5', 'Số thuế TNCN phát sinh trong kỳ [13] = [11] x [12]', '[13]', $r($d['pit']), true),
        $R('6', 'Số thuế TNCN đã khấu trừ', '[14]', 0),
        $R('7', 'Số thuế TNCN đã tạm nộp trong năm', '[15]', $r($d['tam'])),
        $R('8', 'Số thuế TNCN được giảm do thiên tai, dịch bệnh, hỏa hoạn, tai nạn và bệnh hiểm nghèo theo Quyết định giảm thuế của cơ quan thuế', '[16]', 0),
        $R('9', 'Tổng số thuế TNCN còn phải nộp [17] = [13] − [14] − [15] − [16] ≥ 0', '[17]', $r($d['phai_nop']), true),
        $R('10', 'Số thuế TNCN được miễn từ 50.000 đồng trở xuống [18] = [17] ≤ 50.000', '[18]', $r($d['mien'])),
        $R('11', 'Số thuế TNCN còn phải nộp sau miễn số thuế từ 50.000 đồng trở xuống [19] = [17] − [18]', '[19]', $r($d['phai_nop'] - $d['mien']), true),
        $R('12', 'Tổng số thuế TNCN nộp thừa [20] = [13] − [14] − [15] − [16] < 0', '[20]', $r($d['thua'])),
        $R('12.1', 'Tổng số thuế đề nghị hoàn trả cho người nộp thuế [21] = [22] + [23]', '[21]', 0),
        $R('12.2', 'Số thuế đề nghị hoàn trả cho người nộp thuế', '[22]', 0),
        $R('12.3', 'Số thuế đề nghị bù trừ cho khoản phải nộp ngân sách nhà nước khác', '[23]', 0),
        $R('12.4', 'Tổng số thuế bù trừ cho các phát sinh của kỳ sau [24] = [20] − [21]', '[24]', $r($d['thua'])),
    ];
    $colsA = [tkCol('STT'), tkCol('Chỉ tiêu'), tkCol('Mã chỉ tiêu'), tkCol('Số tiền', '', true)];
    $colsB = [tkCol('STT', '[25]'), tkCol('Nội dung', '[26]'), tkCol('Số dư đầu kỳ', '[27]', true), tkCol('Nhập trong kỳ', '[28]', true), tkCol('Xuất trong kỳ', '[29]', true), tkCol('Tồn cuối kỳ', '[30]', true)];
    $rowsB = [['type' => 'row', 'cells' => ['1', 'Hàng tồn kho (giá trị theo sổ S2d)', $r($d['kho']['dau']), $r($d['kho']['nhap']), $r($d['kho']['xuat']), $r($d['kho']['cuoi'])]],
        ['type' => 'total', 'cells' => ['', 'Tổng cộng [31]–[34]', $r($d['kho']['dau']), $r($d['kho']['nhap']), $r($d['kho']['xuat']), $r($d['kho']['cuoi'])]]];
    $colsC = [tkCol('STT', '[35]'), tkCol('Nội dung các khoản nộp NSNN', '[36]'), tkCol('Số tiền', '[37]', true), tkCol('Chương', '[38]'), tkCol('Tiểu mục', '[39]'), tkCol('Địa bàn hành chính', '[40]'), tkCol('Cơ quan thu', '[41]'), tkCol('Cơ quan thuế', '[42]'), tkCol('Hạn nộp thuế', '[43]')];
    $rowsC = [['type' => 'row', 'cells' => ['1', 'Thuế TNCN còn phải nộp', $r($d['phai_nop'] - $d['mien']), '', '', '', '', '', '31/03/' . ($nam + 1)]],
        ['type' => 'total', 'cells' => ['', 'Tổng cộng [44]', $r($d['phai_nop'] - $d['mien']), '', '', '', '', '', '']]];
    $dcols = [['STT', '[46]'], ['Mã số thuế', '[47]'], ['Tên NNT', '[48]'], ['Mã định danh khoản phải nộp (ID) (nếu có)', '[49]'], ['Nội dung khoản nợ/phát sinh', '[50]'], ['Chương', '[51]'], ['Tiểu mục', '[52]'], ['Cơ quan thu', '[53]'], ['Địa bàn hành chính', '[54]'], ['Hạn nộp', '[55]'], ['Số tiền còn phải nộp', '[56]'], ['Số tiền còn phải nộp đề nghị bù trừ với số tiền nộp thừa', '[57]'], ['Số tiền còn phải nộp sau bù trừ', '[58]=[56]-[57]']];
    $notes = [
        'Bản điền sẵn từ đơn hàng, chi phí, khấu hao đã ghi sổ để đối chiếu và nhập vào ứng dụng kê khai của cơ quan thuế.',
        'Dành cho hộ tính TNCN trên thu nhập tính thuế (doanh thu − chi phí). Thuế suất [12] theo bậc doanh thu cả năm: 15% đến 3 tỷ, 17% trên 3 tỷ đến 50 tỷ, 20% trên 50 tỷ (Luật TNCN Điều 7 khoản 2).',
        'Số đã tạm nộp [15] là số phần mềm tính theo quý (0,5% × doanh thu tính thuế); nếu đã nộp thực tế khác, hãy sửa theo chứng từ nộp tiền. Số đã khấu trừ [14] là thuế do sàn thương mại điện tử đã khấu trừ, nộp thay (chưa có dữ liệu).',
        'Chi phí chỉ gồm khoản đã đánh dấu "được trừ" cùng giá vốn, khấu hao, phân bổ trả trước (đã loại khoản không được trừ, chưa phân loại). Khoản chi từ 5 triệu đồng trả bằng tiền mặt cần chứng từ thanh toán không dùng tiền mặt mới được trừ (NĐ 68/2026 Điều 6).',
        'Chưa có dữ liệu trong phần mềm: mục D (hoàn trả, bù trừ nộp thừa), giảm thuế do thiên tai [16], đề nghị hoàn trả [21]–[23], doanh thu sàn không có chức năng thanh toán [09c]. Hộ tự bổ sung.',
    ];
    if ($d['nhom'] !== '3') array_unshift($notes, 'Hộ đang chọn tính TNCN theo % doanh thu: mẫu này không áp dụng cho hộ; số liệu bên dưới chỉ để so sánh với cách tính trên thu nhập.');
    if ($d['chua_pl_so'] > 0) $notes[] = 'Còn ' . $d['chua_pl_so'] . ' khoản chi chưa phân loại (' . number_format($d['chua_pl_tien'], 0, ',', '.') . ' đ) chưa được tính vào chi phí: hãy phân loại ở trang Chi phí trước khi lập tờ khai.';
    return tkRep($nnt, '02/CNKD-TNCN-QTT', '(Kèm theo Thông tư số 89/2026/TT-BTC ngày 30 tháng 6 năm 2026 của Bộ trưởng Bộ Tài chính)', 'TỜ KHAI QUYẾT TOÁN THUẾ THU NHẬP CÁ NHÂN', [
        'subtitle' => ['(Áp dụng cho hộ kinh doanh, cá nhân kinh doanh thực hiện nộp thuế thu nhập cá nhân trên thu nhập tính thuế)'],
        'period' => '[01] Kỳ tính thuế: Năm ' . $nam, 'period_lines' => ['[02] Lần đầu: ☑   [03] Bổ sung lần thứ: ....', '☐ Giảm thuế do thiên tai, dịch bệnh, hỏa hoạn, tai nạn và bệnh hiểm nghèo'],
        'header' => ['[04] Người nộp thuế: ' . $nnt['chu'] . ' (hộ kinh doanh: ' . $nnt['ten'] . ')', '[05] Mã số thuế: ' . ($nnt['mst'] ?: '……………'),
            '[06] Địa chỉ trụ sở kinh doanh:', '[06.1] Số nhà, đường phố/xóm/ấp/thôn: ' . ($nnt['dia_chi'] ?: '……………'), '[06.2] Xã/phường/đặc khu: ……………', '[06.3] Tỉnh/TP: ……………',
            '[07] Tổ chức/cá nhân khai, nộp thuế thay theo ủy quyền (nếu có): ……………', '[07.1] Mã số thuế: ……………', '[07.2] Văn bản ủy quyền (nếu có): Số ……… ngày … tháng … năm …',
            '[08] Tên tổ chức, cá nhân cung cấp dịch vụ làm thủ tục về thuế (nếu có): ……………', '[08.1] Mã số thuế: ……………', '[08.2] Hợp đồng dịch vụ làm thủ tục về thuế: Số ……… ngày ………'],
        'tables' => [
            ['heading' => 'A. KÊ KHAI THÔNG TIN TÍNH THUẾ', 'before' => [], 'after' => [], 'groups' => [], 'columns' => $colsA, 'rows' => $rows],
            ['heading' => 'B. VẬT LIỆU, DỤNG CỤ, SẢN PHẨM, HÀNG HÓA', 'before' => [], 'after' => [], 'groups' => [], 'columns' => $colsB, 'rows' => $rowsB],
            ['heading' => 'C. HỖ TRỢ THÔNG TIN NỘP THUẾ', 'before' => [], 'after' => [], 'groups' => [], 'columns' => $colsC, 'rows' => $rowsC],
            tkBlank('D. ĐỀ NGHỊ XỬ LÝ KHOẢN NỘP THỪA', $dcols, [['cells' => ['']]], [[['', 1], ['Thông tin khoản nợ/khoản thu phát sinh', 10], ['', 2]]], [
                '[45] Thông tin hoàn trả: ……………', '[45.1] Tên chủ tài khoản: ……………', '[45.2] Số tài khoản: ……………   [45.3] Tại Ngân hàng/KBNN: ……………',
                'Thông tin người nộp thuế đề nghị bù trừ khoản nộp thừa với khoản nợ, khoản thu phát sinh hoặc đề nghị hoàn kiêm bù trừ thu ngân sách nhà nước:']),
        ],
        'footnotes' => ['Ghi chú: - TMĐT: thương mại điện tử; - ĐBHC: địa bàn hành chính'], 'notes' => $notes, 'agent' => 'tt89',
        'sign_note' => '(Chữ ký, ghi rõ họ tên; chức vụ và đóng dấu (nếu có)/Xác nhận điện tử/Ký điện tử)',
    ]);
}

/** Mau 01/TKN-CNKD (TT 89/2026): thong bao doanh thu / to khai thue nam. */
function tkMauTkn(array $nnt, int $nam, float $tong, float $rev, bool $liable, string $nhomThue, float $vat, float $pit, float $ded): array
{
    $m = fn($v) => (int) round($v);
    $heads = [2 => 'Tổng doanh thu', 3 => 'Doanh thu không chịu thuế GTGT', 4 => 'Doanh thu chịu thuế suất 0%', 5 => 'Số thuế GTGT phải nộp', 6 => 'Doanh thu chịu thuế TNCN',
        7 => 'Doanh thu được trừ để xác định doanh thu tính thuế', 8 => 'Số thuế TNCN phải nộp', 9 => 'GTGT người nộp thuế trực tiếp nộp', 10 => 'TNCN người nộp thuế trực tiếp nộp',
        11 => 'GTGT do tổ chức khấu trừ, nộp thay', 12 => 'TNCN do tổ chức khấu trừ, nộp thay', 13 => 'GTGT phải nộp thêm', 14 => 'TNCN phải nộp thêm', 15 => 'GTGT nộp thừa', 16 => 'TNCN nộp thừa'];
    $cols = [tkCol('STT'), tkCol('Chỉ tiêu'), tkCol('Mã chỉ tiêu'), tkCol('Nhóm ngành nghề (chỉ áp dụng với mục 1, 2, 5)', '(1)')];
    foreach ($heads as $i => $h) $cols[] = tkCol($h, '(' . $i . ')', true);
    $zero = array_fill(0, 15, 0);
    $v1 = [$m($rev), $liable ? 0 : $m($rev), 0, $m($vat), $liable ? $m($rev) : 0, $m($ded), $m($pit), 0, 0, 0, 0, $m($vat), $m($pit), 0, 0];
    $tn = fn(string $s, string $nd, string $ma, $v) => ['type' => 'span', 'cells' => [$s, $nd, $ma, $v]];
    $rows = [['type' => 'group', 'cells' => ['I  Hoạt động sản xuất, kinh doanh hàng hóa, cung cấp dịch vụ (trừ hoạt động cho thuê bất động sản)']],
        ['type' => 'row', 'cells' => array_merge(['1', 'Hoạt động sản xuất, kinh doanh hàng hóa, cung cấp dịch vụ có địa điểm kinh doanh cố định', '[08]', '(a)'], $v1)],
        ['type' => 'row', 'cells' => array_merge(['2', 'Hoạt động kinh doanh trên nền tảng thương mại điện tử, nền tảng số khác', '[09]', ''], $zero)],
        ['type' => 'row', 'cells' => array_merge(['3', 'Hoạt động đại lý xổ số, bảo hiểm, bán hàng đa cấp', '[10]', ''], $zero)],
        ['type' => 'row', 'cells' => array_merge(['4', 'Hoạt động môi giới', '[11]', ''], $zero)],
        ['type' => 'row', 'cells' => array_merge(['5', 'Hoạt động cá nhân hợp tác kinh doanh với tổ chức', '[12]', ''], $zero)],
        ['type' => 'total', 'cells' => array_merge(['6', 'Tổng cộng', '[13]', ''], $v1)],
        $tn('7', 'Số thuế TNCN được giảm do thiên tai, dịch bệnh, hỏa hoạn, tai nạn và bệnh hiểm nghèo theo Quyết định giảm thuế của cơ quan thuế', '[14]', 0),
        $tn('8', 'Số thuế TNCN còn phải nộp [15] = [13.13] − [14] ≥ 0', '[15]', $m($pit)),
        $tn('9', 'Số thuế TNCN được miễn từ 50.000 đồng trở xuống', '[16]', ($pit > 0 && $pit <= 50000) ? $m($pit) : 0),
        $tn('10', 'Số thuế TNCN còn phải nộp sau miễn số thuế từ 50.000 đồng trở xuống', '[17]', ($pit > 0 && $pit <= 50000) ? 0 : $m($pit)),
        $tn('11', 'Số thuế TNCN nộp thừa', '[18]', 0),
        ['type' => 'row', 'cells' => array_merge(['II', 'Hoạt động cho thuê bất động sản', '[19]', ''], $zero)],
        $tn('1', 'Số thuế TNCN từ hoạt động cho thuê bất động sản được giảm do thiên tai, dịch bệnh, hỏa hoạn, tai nạn và bệnh hiểm nghèo theo Quyết định giảm thuế của cơ quan thuế', '[20]', 0),
        $tn('2', 'Số thuế TNCN nộp thừa [21] = [19.15] + [20]', '[21]', 0)];
    $groups = [[['', 4], ['Thuế GTGT', 4], ['Thuế TNCN', 3], ['Số tiền người nộp thuế trực tiếp nộp vào NSNN', 2], ['Số tiền do tổ chức khấu trừ, nộp thay', 2], ['Số thuế phải nộp thêm', 2], ['Số thuế nộp thừa', 2]]];
    $E = [
        tkBlank('B. KÊ KHAI THUẾ TIÊU THỤ ĐẶC BIỆT', [['STT', '(1)'], ['Hàng hóa, dịch vụ chịu thuế TTĐB', '(2)'], ['Mã chỉ tiêu', '(3)'], ['Đơn vị tính', '(4)'], ['Doanh thu tính thuế TTĐB', '(5)'], ['Thuế suất', '(6)'], ['Số thuế phải nộp', '(7)=(5)*(6)']], [
            'I. Trụ sở kinh doanh:', ['1', 'Hàng hóa, dịch vụ A', '[22a]'], ['2', 'Hàng hóa, dịch vụ B', '[22b]'], 'II. Mã địa điểm kinh doanh 1: …… Tên địa điểm kinh doanh 1: ……',
            ['III', 'Tổng cộng:', '[23]', '', '[23.1]', '', '[23.2]'], ['IV', 'Số thuế được miễn từ 50.000 đồng trở xuống', '[24]', '[24]=[23.2] <=50.000'], ['V', 'Số thuế còn phải nộp', '[25]', '[25]=[23.2] - [24]']]),
        tkBlank('C. KÊ KHAI THUẾ TÀI NGUYÊN, BẢO VỆ MÔI TRƯỜNG, PHÍ BẢO VỆ MÔI TRƯỜNG', [['STT', '(1)'], ['Tài nguyên, hàng hóa, sản phẩm', '(2)'], ['Trụ sở kinh doanh/Mã địa điểm kinh doanh', '(3)'], ['Tên địa điểm kinh doanh', '(4)'], ['Mã chỉ tiêu', '(5)'], ['Đơn vị tính', '(6)'], ['Sản lượng/Số lượng', '(7)'], ['Giá tính thuế tài nguyên/mức thuế hoặc phí BVMT', '(8)'], ['Thuế suất/Hệ số K tính phí BVMT', '(9)'], ['Số thuế/phí phải nộp', '(10)']], [
            'I. Khai thuế tài nguyên', ['1', 'Tài nguyên C..', '', '', '[26a]', '', '', '', '', '(10)=(7)*(8)*(9)'], ['2', 'Tài nguyên D..', '', '', '[26b]'],
            ['', 'Tổng cộng', '', '', '', '', '', '', '', '[27]'], ['', 'Số thuế được miễn từ 50.000 đồng trở xuống [28]=[27]<=50.000', '', '', '', '', '', '', '', '[28]'], ['', 'Số thuế còn phải nộp [29]=[27]-[28]', '', '', '', '', '', '', '', '[29]'],
            'II. Khai thuế bảo vệ môi trường', ['2.1', 'Hàng hóa E...', '', '', '[30a]', '', '', '', '', '(10)=(7)*(8)'], ['2.2', 'Hàng hóa G...', '', '', '[30b]'],
            ['', 'Tổng cộng', '', '', '', '', '', '', '', '[31]'], ['', 'Số thuế được miễn từ 50.000 đồng trở xuống', '', '', '', '', '', '', '', '[32]'], ['', 'Số thuế còn phải nộp [33]=[31]-[32]', '', '', '', '', '', '', '', '[33]'],
            'III. Khai phí bảo vệ môi trường đối với khai thác khoáng sản', ['3.1', 'Khoáng sản X...', '', '', '[34a]', '', '', '', '', '(10)=(7)*(8)*(9)'], ['3.2', 'Khoáng sản Y...', '', '', '[34b]'],
            ['', 'Tổng cộng', '', '', '', '', '', '', '', '[35]'], ['', 'Số phí được miễn từ 50.000 đồng trở xuống [36]=[35]<=50.000', '', '', '', '', '', '', '', '[36]'], ['', 'Số phí còn phải nộp [37]=[35]-[36]', '', '', '', '', '', '', '', '[37]']]),
        tkBlank('D. HỖ TRỢ THÔNG TIN NỘP THUẾ', [['STT', '[38]'], ['Mã địa điểm kinh doanh', '[39]'], ['Nội dung các khoản nộp NSNN', '[40]'], ['Số tiền', '[41]'], ['Chương', '[42]'], ['Tiểu mục', '[43]'], ['Địa bàn hành chính', '[44]'], ['Cơ quan thu', '[45]'], ['Cơ quan thuế', '[46]'], ['Hạn nộp thuế', '[47]']], [['…'], ['', '', 'Tổng cộng', '[48]']]),
        tkBlank('E. ĐỀ NGHỊ XỬ LÝ KHOẢN NỘP THỪA', [['STT', '[52]'], ['Mã số thuế', '[53]'], ['Tên NNT', '[54]'], ['Mã định danh khoản phải nộp (ID) (nếu có)', '[55]'], ['Nội dung khoản nợ/phát sinh', '[56]'], ['Chương', '[57]'], ['Tiểu mục', '[58]'], ['Cơ quan thu', '[59]'], ['Địa bàn hành chính', '[60]'], ['Hạn nộp', '[61]'], ['Số tiền còn phải nộp', '[62]'], ['Số tiền còn phải nộp đề nghị bù trừ với số tiền nộp thừa', '[63]'], ['Số tiền còn phải nộp sau bù trừ', '[64]=[62]-[63]']], [['']], [], [
            '[49] Đề nghị hoàn trả: ……………', '[49.1] Số thuế GTGT: ……………', '[49.2] Số thuế TNCN từ hoạt động sản xuất kinh doanh (trừ hoạt động cho thuê nhà): ……………', '[49.3] Số thuế TNCN từ hoạt động cho thuê nhà: ……………', '[49.4] Tổng cộng: ……………',
            '[50] Số bù trừ cho các phát sinh của kỳ sau: ……………', '[50.1] Số thuế GTGT: ……………', '[50.2] Số thuế TNCN từ hoạt động sản xuất kinh doanh (trừ hoạt động cho thuê nhà): ……………', '[50.3] Số thuế TNCN từ hoạt động cho thuê nhà: ……………', '[50.4] Tổng cộng: ……………',
            '[51] Thông tin hoàn trả: ……………', '[51.1] Tên chủ tài khoản: ……………', '[51.2] Tài khoản số: ……………   [51.3] Tại Ngân hàng/KBNN: ……………',
            'Thông tin người nộp thuế đề nghị bù trừ khoản nộp thừa với khoản nợ, khoản thu phát sinh hoặc đề nghị hoàn kiêm bù trừ thu ngân sách nhà nước:']),
    ];
    $notes = [$tong <= 1000000000
        ? 'Doanh thu năm từ 1 tỷ đồng trở xuống: không chịu thuế GTGT, không nộp thuế TNCN; chỉ thông báo doanh thu (cột doanh thu không chịu thuế GTGT). Nộp trước 31/01/' . ($nam + 1) . '.'
        : 'Doanh thu năm vượt 1 tỷ đồng: có nghĩa vụ thuế GTGT, TNCN và phải khai theo quý (Mẫu 01/CNKD); bảng dưới chỉ để đối chiếu số cả năm.',
        'Bản điền sẵn từ đơn hàng đã ghi sổ để đối chiếu và nhập vào ứng dụng kê khai của cơ quan thuế. Dòng 2–5, giảm thuế thiên tai, thuế tiêu thụ đặc biệt, tài nguyên, bảo vệ môi trường và các cột số đã nộp chưa có dữ liệu trong phần mềm nên để trống/0; hộ phát sinh các khoản này tự bổ sung.'];
    return tkRep($nnt, '01/TKN-CNKD', '(Kèm theo Thông tư số 89/2026/TT-BTC ngày 30 tháng 6 năm 2026 của Bộ trưởng Bộ Tài chính)', 'THÔNG BÁO DOANH THU/TỜ KHAI THUẾ NĂM', [
        'subtitle' => ['(Áp dụng đối với hộ kinh doanh, cá nhân kinh doanh có doanh thu năm từ 01 tỷ đồng trở xuống; hộ kinh doanh, cá nhân kinh doanh nộp thuế TNCN theo phương pháp thuế suất nhân với doanh thu tính thuế đề nghị hoàn thuế; cá nhân hợp tác kinh doanh với tổ chức; cá nhân làm đại lý bán đúng giá đối với hoạt động bảo hiểm, xổ số, bán hàng đa cấp; cá nhân thực hiện hoạt động môi giới thuộc trường hợp phải nộp thuế mà chưa được tổ chức khấu trừ thuế, khai thuế thay, nộp thuế thay trong năm)'],
        'choices' => tkTicks([
            'Hộ kinh doanh, cá nhân kinh doanh có doanh thu năm từ 01 tỷ đồng trở xuống' => $tong <= 1000000000,
            'Hộ kinh doanh, cá nhân kinh doanh mới ra kinh doanh có doanh thu năm từ 01 tỷ đồng trở xuống' => false,
            'Hộ kinh doanh, cá nhân kinh doanh nộp thuế TNCN theo phương pháp thuế suất nhân với doanh thu tính thuế đề nghị hoàn thuế' => false,
            'Cá nhân hợp tác kinh doanh với tổ chức; cá nhân làm đại lý bán đúng giá đối với hoạt động bảo hiểm, xổ số, bán hàng đa cấp; cá nhân thực hiện hoạt động môi giới thuộc trường hợp phải nộp thuế mà chưa được tổ chức khấu trừ thuế, khai thuế thay, nộp thuế thay trong năm' => false,
            'Cho phép điều chỉnh, bổ sung các tờ khai Mẫu số 01/CNKD đã kê khai theo Thông tư số 40/2021/TT-BTC, Thông tư số 18/2026/TT-BTC; tờ khai Mẫu số 02/CNKD-TMĐT đã kê khai theo Nghị định số 117/2025/NĐ-CP' => false,
            'Giảm thuế do thiên tai, dịch bệnh, hỏa hoạn, tai nạn và bệnh hiểm nghèo' => false]),
        'choices_first' => true, 'period' => '[01] Kỳ tính thuế: [01a] Năm ' . $nam, 'period_lines' => ['[01b] 6 tháng đầu năm.....   [01c] 6 tháng cuối năm....', '[02] Lần đầu: ☑   [03] Bổ sung lần thứ: ....'],
        'header' => ['[04] Người nộp thuế: ' . $nnt['chu'] . ' (hộ kinh doanh: ' . $nnt['ten'] . ')', '[05] Mã số thuế: ' . ($nnt['mst'] ?: '……………'),
            '[06] Tổ chức/cá nhân kê khai, nộp thuế thay theo ủy quyền (nếu có): ……………', '[06.1] Mã số thuế: ……………', '[06.2] Văn bản uỷ quyền (nếu có): Số ……… ngày … tháng … năm …',
            '[07] Tên tổ chức, cá nhân cung cấp dịch vụ làm thủ tục về thuế (nếu có): ……………', '[07.1] Mã số thuế: ……………', '[07.2] Hợp đồng dịch vụ làm thủ tục về thuế: Số ……… ngày ………'],
        'tables' => array_merge([['heading' => 'A. XÁC ĐỊNH NGHĨA VỤ THUẾ GTGT, TNCN', 'before' => [], 'after' => [], 'groups' => $groups, 'columns' => $cols, 'rows' => $rows]], $E),
        'footnotes' => ['Ghi chú:', '- Đối với trường hợp hộ kinh doanh, cá nhân kinh doanh có doanh thu năm từ 01 tỷ đồng trở xuống thì chỉ thực hiện thông báo doanh thu; không thực hiện khai số thuế GTGT, thuế TNCN phải nộp.',
            '- Chỉ tiêu (1) Nhóm ngành nghề tại mục A khai như sau: (1) Phân phối, cung cấp hàng hóa, (2) Dịch vụ, xây dựng không bao thầu nguyên vật liệu, (3) Hoạt động cho thuê tài sản trừ bất động sản, (4) Sản xuất, vận tải, dịch vụ có gắn với hàng hóa, xây dựng có bao thầu nguyên vật liệu, (5) Hoạt động cung cấp sản phẩm nội dung thông tin số về giải trí, trò chơi điện tử, phim số, ảnh số, nhạc số, quảng cáo số, (6) Hoạt động kinh doanh khác. Trường hợp cá nhân có nhiều ngành nghề thì kê khai mỗi ngành nghề trên 01 dòng.',
            '- Chỉ tiêu [3.1], [3.2]...tại khoản III, mục C: bao gồm khoáng sản và đất đá bóc, đất đá thải (nếu có).',
            '- Hệ số K tính phí bảo vệ môi trường đối với khai thác khoáng sản thực hiện theo quy định tại Nghị định số 27/2023/NĐ-CP và các văn bản sửa đổi bổ sung (nếu có)'],
        'notes' => $notes, 'agent' => 'tt89', 'sign_note' => '(Chữ ký, ghi rõ họ tên; chức vụ và đóng dấu (nếu có)/Xác nhận điện tử/Ký điện tử)',
    ]);
}

/** Mau so 01 kem ND 398/2026: giam 30% thue TNCN theo NQ 43/2026/QH16. $dong = [[so thue phai nop, ten dia diem]]. */
function tkMauGiam(array $nnt, int $nam, string $ky, array $dong, float $cut, float $doanhThuNam): array
{
    $out = [];
    $sum = [0, 0];
    foreach ($dong as $i => [$p, $code, $ten]) {
        $g = (int) round($p * $cut);
        $out[] = ['type' => 'row', 'cells' => [(string) ($i + 1), $code, $ten, 'Hoạt động sản xuất, kinh doanh hàng hóa, cung cấp dịch vụ có địa điểm kinh doanh cố định', 'Phân phối, cung cấp hàng hóa', (int) round($p), $g]];
        $sum = [$sum[0] + (int) round($p), $sum[1] + $g];
    }
    if (!$out) $out[] = ['type' => 'row', 'cells' => ['1', '', '', '', '', '', '']];
    $out[] = ['type' => 'total', 'cells' => ['', '', 'Tổng cộng [13], [14]', '', '', $sum[0], $sum[1]]];
    $cols = [tkCol('STT', '[06]'), tkCol('Mã địa điểm kinh doanh', '[07]'), tkCol('Tên địa điểm kinh doanh', '[08]'), tkCol('Hoạt động sản xuất kinh doanh', '[09]'), tkCol('Nhóm ngành nghề', '[10]'), tkCol('Số thuế TNCN phải nộp', '[11]', true), tkCol('Số thuế TNCN được giảm', '[12]', true)];
    return tkRep($nnt, 'Mẫu số 01', '(Kèm theo Nghị định số 398/2026/NĐ-CP ngày 29 tháng 10 năm 2026 của Chính phủ)', 'GIẢM THUẾ THU NHẬP CÁ NHÂN THEO NGHỊ QUYẾT SỐ 43/2026/QH16', [
        'form_label' => 'Mẫu số 01', 'subtitle' => ['(Kèm theo Tờ khai thuế TNCN của hộ kinh doanh, cá nhân kinh doanh)'], 'period' => 'Kỳ tính thuế: ' . $ky,
        'header' => ['[01] Người nộp thuế: ' . $nnt['chu'], '[02] Mã số thuế: ' . ($nnt['mst'] ?: '……………'), '[03] Tên tổ chức, cá nhân cung cấp dịch vụ làm thủ tục về thuế (nếu có): ……………', '[04] Mã số thuế: ……………', '[05] Hợp đồng dịch vụ làm thủ tục về thuế: Số ……… Ngày ………'],
        'tables' => [['heading' => '', 'before' => [], 'after' => [], 'columns' => $cols, 'rows' => $out, 'groups' => [[['', 1], ['Địa điểm kinh doanh', 2], ['', 4]]]]],
        'footnotes' => ['Ghi chú: - Chỉ tiêu [09]: bao gồm các hoạt động sau: Hoạt động sản xuất, kinh doanh hàng hóa, cung cấp dịch vụ có địa điểm kinh doanh cố định; hoạt động kinh doanh trên nền tảng thương mại điện tử, nền tảng số khác; hoạt động cho thuê bất động sản; hoạt động đại lý xổ số, bảo hiểm, bán hàng đa cấp; hoạt động môi giới; hoạt động cá nhân hợp tác kinh doanh với tổ chức.',
            '- Chỉ tiêu [10]: bao gồm các nhóm ngành nghề: Hoạt động đại lý xổ số; hoạt động đại lý bảo hiểm; hoạt động đại lý bán hàng đa cấp; hoạt động môi giới; hoạt động cho thuê bất động sản; phân phối, cung cấp hàng hóa; dịch vụ, xây dựng không bao thầu nguyên vật liệu; hoạt động cho thuê tài sản trừ bất động sản; sản xuất, vận tải, dịch vụ có gắn với hàng hóa, xây dựng có bao thầu nguyên vật liệu; hoạt động cung cấp sản phẩm nội dung thông tin số về giải trí, trò chơi điện tử, phim số, ảnh số, nhạc số, quảng cáo số; hoạt động kinh doanh khác.'],
        'notes' => ['Nộp kèm tờ khai thuế TNCN của kỳ (NĐ 398/2026/NĐ-CP Điều 2 khoản 4 điểm a). Số thuế TNCN phải nộp lấy đúng từ tờ khai cùng kỳ của phần mềm.',
            'Được giảm 30% số thuế TNCN phải nộp của kỳ tính thuế năm 2026, 2027 nếu doanh thu năm không quá 10 tỷ đồng, không phân biệt cách tính thuế (NĐ 398/2026/NĐ-CP Điều 2).',
            $cut > 0 ? 'Doanh thu năm ' . $nam . ' đến nay ' . number_format($doanhThuNam, 0, ',', '.') . ' đ: thuộc diện được giảm. Nếu cả năm vượt 10 tỷ đồng thì phải nộp lại số đã giảm theo Mẫu số 03 kèm tờ khai của kỳ vượt.'
                : (in_array($nam, [2026, 2027], true) ? 'Doanh thu năm ' . $nam . ' đã vượt 10 tỷ đồng: không thuộc diện được giảm; nếu đã kê khai giảm ở kỳ trước thì phải nộp lại theo Mẫu số 03.' : 'Mức giảm chỉ áp dụng cho kỳ tính thuế năm 2026 và 2027.')],
        'agent' => 'tt89', 'pledge' => 'Tôi cam đoan những nội dung kê khai trên là đúng và chịu trách nhiệm trước pháp luật về những nội dung đã khai.',
        'sign_note' => '(Chữ ký, ghi rõ họ tên; chức vụ và đóng dấu (nếu có)/ Xác nhận điện tử/Ký điện tử)', 'unit' => '',
    ]);
}

/** Mau 02/TNDN: bang ke thu mua hang hoa, dich vu khong co hoa don. */
function tkMauThuMua(array $nnt, string $tu, string $den, array $rows, float $tong): array
{
    $cells = [];
    foreach ($rows as $r) $cells[] = ['type' => 'row', 'cells' => $r];
    $cells[] = ['type' => 'total', 'cells' => ['', 'Tổng giá trị hàng hóa, dịch vụ mua vào', '', '', '', '', '', '', (int) round($tong), '']];
    $cols = [tkCol('Ngày tháng năm mua hàng', '(1)'), tkCol('Tên người bán', '(2)'), tkCol('Địa chỉ', '(3)'), tkCol('Số căn cước', '(4)'), tkCol('Số điện thoại (nếu có)', '(5)'), tkCol('Tên hàng hóa, dịch vụ', '(6)'),
        tkCol('Số lượng, trọng lượng', '(7)'), tkCol('Đơn giá', '(8)', true), tkCol('Tổng giá thanh toán', '(9)', true), tkCol('Ghi chú (số phiếu nhập)', '(10)')];
    return tkRep($nnt, '02/TNDN', '(Ban hành kèm theo Thông tư số 20/2026/TT-BTC của Bộ trưởng Bộ Tài chính)', 'BẢNG KÊ THU MUA HÀNG HÓA, DỊCH VỤ KHÔNG CÓ HÓA ĐƠN', [
        'period' => 'Từ ngày ' . date('d/m/Y', strtotime($tu)) . ' đến ngày ' . date('d/m/Y', strtotime($den)),
        'header' => ['- Tên hộ kinh doanh: ' . $nnt['ten'], '- Mã số thuế: ' . ($nnt['mst'] ?: '……………'), '- Địa chỉ: ' . ($nnt['dia_chi'] ?: '……………'), '- Số điện thoại: ' . $nnt['sdt']],
        'tables' => [['heading' => '', 'before' => [], 'after' => [], 'groups' => [], 'columns' => $cols, 'rows' => $cells]],
        'notes' => ['Chỉ gồm các phiếu nhập hàng không có số hóa đơn đầu vào và nhà cung cấp chưa có mã số thuế. Phần mềm chưa lưu số căn cước của người bán nên cột (4) để trống, hãy ghi tay hoặc xin giấy tờ khi mua.',
            'Với hộ tính TNCN trên thu nhập, khoản mua không có hóa đơn chỉ được trừ khi có đủ chứng từ theo quy định (NĐ 68/2026 Điều 6); khoản thanh toán từ 5 triệu đồng trở lên cần chứng từ thanh toán không dùng tiền mặt.'],
        'pledge' => 'Tôi cam đoan những nội dung kê khai trên là đúng và chịu trách nhiệm trước pháp luật về những nội dung đã khai./.',
    ]);
}

/** Mau 01/TB-DDKD. $locs = danh sach chi nhanh (name, address, code). */
function tkMauDdkd(array $nnt, string $lo, array $locs): array
{
    $types = ['establish' => 'Thành lập địa điểm kinh doanh', 'change' => 'Thay đổi thông tin', 'suspend' => 'Tạm ngừng kinh doanh', 'resume' => 'Khôi phục tạm ngừng kinh doanh trước thời hạn đã thông báo', 'close' => 'Chấm dứt hoạt động'];
    $mk = function (array $heads, callable $row) use ($locs) {
        return ['before' => [], 'after' => [], 'groups' => [], 'columns' => array_map(fn($h, $i) => tkCol($h, '(' . ($i + 1) . ')'), $heads, array_keys($heads)),
            'rows' => !$locs ? [['type' => 'row', 'cells' => array_fill(0, count($heads), '')]] : array_map(fn($l) => ['type' => 'row', 'cells' => $row($l)], $locs)];
    };
    $t = match ($lo) {
        'establish' => ['heading' => '<1. Thành lập địa điểm kinh doanh>'] + $mk(['Tên địa điểm kinh doanh', 'Hình thức kinh doanh thương mại điện tử', 'Ngày bắt đầu hoạt động', 'Ngành nghề kinh doanh chính', 'Số nhà, đường phố (thôn/xóm)', 'Xã/Phường/Đặc khu', 'Tỉnh/thành phố', 'Mã định danh địa điểm (nếu có)', 'Mã trang trại (nếu có)'], fn($l) => [$l['name'], '', '', '', (string) $l['address'], '', '', '', '']),
        'change' => ['heading' => '<2. Thay đổi thông tin>'] + $mk(['Mã địa điểm kinh doanh', 'Tên địa điểm kinh doanh', 'Chuyển thành trụ sở chính (tích "x")', 'Ngày bắt đầu hoạt động', 'Ngành nghề kinh doanh chính', 'Số nhà, đường phố (thôn/xóm)', 'Xã/Phường/Đặc khu', 'Tỉnh/thành phố', 'Mã định danh địa điểm (nếu có)', 'Mã trang trại (nếu có)'], fn($l) => ['', $l['name'], '', '', '', (string) $l['address'], '', '', '', '']),
        'suspend' => ['heading' => '<3. Tạm ngừng kinh doanh>'] + $mk(['Mã địa điểm kinh doanh', 'Tên địa điểm kinh doanh', 'Từ ngày tháng năm', 'Đến ngày tháng năm', 'Lý do tạm ngừng'], fn($l) => ['', $l['name'], '', '', '']),
        'resume' => ['heading' => '<4. Khôi phục tạm ngừng kinh doanh trước thời hạn đã thông báo>'] + $mk(['Mã địa điểm kinh doanh', 'Tên địa điểm kinh doanh', 'Thời gian đã thông báo tạm ngừng: từ ngày', 'Đến ngày', 'Tiếp tục kinh doanh trước thời hạn kể từ ngày', 'Lý do tiếp tục kinh doanh'], fn($l) => ['', $l['name'], '', '', '', '']),
        default => ['heading' => '<5. Chấm dứt hoạt động>'] + $mk(['Mã địa điểm kinh doanh', 'Tên địa điểm kinh doanh', 'Ngày tháng năm chấm dứt hoạt động', 'Lý do chấm dứt hoạt động'], fn($l) => ['', $l['name'], '', '']),
    };
    return tkRep($nnt, '01/TB-ĐĐKD', '(Kèm theo Thông tư số 18/2026/TT-BTC ngày 05/3/2026 của Bộ trưởng Bộ Tài chính)', "THÔNG BÁO\nVề việc thành lập/thay đổi thông tin/tạm ngừng/chấm dứt hoạt động đối với địa điểm kinh doanh", [
        'header' => ['Kính gửi: (Cơ quan thuế quản lý trực tiếp NNT) ……………', '1. Người nộp thuế: ' . $nnt['chu'] . ' (hộ kinh doanh: ' . $nnt['ten'] . ')', '2. Mã số thuế: ' . ($nnt['mst'] ?: '……………'),
            '3. Thông tin đại lý thuế (nếu có):', '3a. Tên: ……………………………', '3b. Mã số thuế: ……………………………'],
        'choices' => array_merge(['Thông báo về việc thành lập/thay đổi thông tin/tạm ngừng/khôi phục tạm ngừng kinh doanh trước thời hạn/chấm dứt hoạt động địa điểm kinh doanh như sau:'],
            array_map(fn($k, $l) => ($k === $lo ? '☑ ' : '☐ ') . $l, array_keys($types), $types)),
        'tables' => [$t], 'unit' => '',
        'footnotes' => array_merge($lo === 'establish' ? ['Ghi chú: (2): Tích nếu địa điểm kinh doanh chỉ có hoạt động kinh doanh trên nền tảng thương mại điện tử, nền tảng số khác.'] : [], ['Ghi chú: Tên địa điểm kinh doanh: tên cửa hàng, thương hiệu (nếu có)']),
        'notes' => ['Bản điền sẵn từ danh sách chi nhánh. Các thông tin phần mềm chưa lưu (ngày bắt đầu, ngành nghề chính, xã/phường, tỉnh/thành phố, lý do) hộ tự điền.',
            'Thời hạn thông báo: 10 ngày làm việc kể từ ngày địa điểm đi vào hoạt động, thay đổi thông tin, tạm ngừng hoặc chấm dứt (NĐ 68/2026 Điều 8 khoản 4 điểm đ). Gửi cơ quan thuế quản lý trực tiếp trụ sở của hộ.'],
        'pledge' => 'Tôi cam đoan những nội dung kê khai trên là đúng và chịu trách nhiệm trước pháp luật về những nội dung đã khai./.',
    ]);
}

/** Mau 01/BK-STK. $acc = [[ngan hang, so TK, chu TK]]; $locs: dia diem dau tien. */
function tkMauStk(array $nnt, array $acc, string $diaDiem): array
{
    $rows = [];
    foreach ($acc as $i => [$bank, $no, $chu]) $rows[] = ['type' => 'row', 'cells' => [(string) ($i + 1), $diaDiem, '', $no, $chu !== '' ? $chu : $nnt['chu'], $bank, 'Khai lần đầu']];
    if (!$rows) $rows[] = ['type' => 'row', 'cells' => ['1', '', '', '', '', '', '']];
    $cols = [tkCol('STT', '[03]'), tkCol('Tên địa điểm kinh doanh', '[04]'), tkCol('Mã địa điểm kinh doanh', '[05]'), tkCol('Số tài khoản ngân hàng/Số hiệu ví điện tử', '[06]'), tkCol('Tên chủ tài khoản', '[07]'),
        tkCol('Mở tại tổ chức cung ứng dịch vụ thanh toán/Tổ chức cung ứng dịch vụ trung gian thanh toán', '[08]'), tkCol('Thông tin thay đổi/ Khai lần đầu', '[09]')];
    return tkRep($nnt, '01/BK-STK', '(Kèm theo Thông tư số 18/2026/TT-BTC ngày 05/3/2026 của Bộ trưởng Bộ Tài chính)', 'THÔNG BÁO SỐ TÀI KHOẢN/SỐ HIỆU VÍ ĐIỆN TỬ', [
        'header' => ['[01] Người nộp thuế: ' . $nnt['ten'], '[02] Mã số thuế: ' . ($nnt['mst'] ?: '……………'), 'Thông báo về số tài khoản ngân hàng/số hiệu ví điện tử liên quan đến sản xuất, kinh doanh của hộ kinh doanh, cá nhân kinh doanh như sau:'],
        'tables' => [['heading' => '', 'before' => [], 'after' => [], 'groups' => [], 'columns' => $cols, 'rows' => $rows]], 'unit' => '',
        'notes' => ['Danh sách tài khoản nhập ở khung phía trên (mỗi dòng: ngân hàng hoặc ví | số tài khoản | tên chủ tài khoản). Cột [09] điền sẵn "Khai lần đầu"; khi đổi thông tin hoặc đóng tài khoản, hãy sửa lại cho đúng trên bản nộp.'],
        'pledge' => 'Tôi cam kết những nội dung kê khai là đúng và chịu trách nhiệm trước pháp luật về những nội dung đã khai./.']);
}

/** Mau 01/BK-HTK. */
function tkMauHtk(array $nnt, string $ngay, array $hang, array $tsan): array
{
    $m = fn($v) => (int) round($v);
    $qs = fn($v) => rtrim(rtrim(number_format($v, 3, ',', '.'), '0'), ',');
    $gq = array_sum(array_column($hang, 'qty')); $gv = array_sum(array_column($hang, 'value'));
    $tq = array_sum(array_column($tsan, 'qty')); $tv = array_sum(array_column($tsan, 'value'));
    $rows = [['type' => 'group', 'cells' => ['I  HÀNG HÓA, SẢN PHẨM TỒN KHO']]];
    foreach ($hang as $i => $g) $rows[] = ['type' => 'row', 'cells' => [(string) ($i + 1), $g['name'], $g['unit'], $qs($g['qty']), $m($g['value'])]];
    $rows[] = ['type' => 'total', 'cells' => ['', 'Tổng cộng', '', '[08] ' . $qs($gq), '[09] ' . number_format($m($gv), 0, ',', '.')]];
    $rows[] = ['type' => 'group', 'cells' => ['II  MÁY MÓC, THIẾT BỊ']];
    foreach ($tsan as $i => $g) $rows[] = ['type' => 'row', 'cells' => [(string) ($i + 1), $g['name'], $g['unit'], $qs($g['qty']), $m($g['value'])]];
    $rows[] = ['type' => 'total', 'cells' => ['', 'Tổng cộng', '', '[10] ' . $qs($tq), '[11] ' . number_format($m($tv), 0, ',', '.')]];
    $rows[] = ['type' => 'total', 'cells' => ['', 'Tổng cộng', '', '[12] ' . $qs($gq + $tq), '[13] ' . number_format($m($gv + $tv), 0, ',', '.')]];
    $cols = [tkCol('STT', '[03]'), tkCol('Hàng tồn kho, máy móc, thiết bị', '[04]'), tkCol('Đơn vị tính của hàng tồn kho, máy móc, thiết bị', '[05]'), tkCol('Số lượng', '[06]', true), tkCol('Giá trị', '[07]', true)];
    return tkRep($nnt, '01/BK-HTK', '(Kèm theo Thông tư số 18/2026/TT-BTC ngày 05/3/2026 của Bộ trưởng Bộ Tài chính)', "BẢNG KÊ HÀNG TỒN KHO, MÁY MÓC, THIẾT BỊ\nCỦA HỘ KINH DOANH, CÁ NHÂN KINH DOANH", [
        'subtitle' => ['(Dành cho hộ kinh doanh có doanh thu năm 2025 từ 3 tỷ đồng trở lên hoặc từ năm 2026 chọn tính thuế TNCN trên thu nhập: lập tại ngày 31/12/2025, giữ tại hộ và gửi 1 bản cho cơ quan thuế)'],
        'header' => ['[01] Tên người nộp thuế: ' . $nnt['ten'], '[02] Mã số thuế: ' . ($nnt['mst'] ?: '……………'), 'Tồn kho tại ngày ' . date('d/m/Y', strtotime($ngay))],
        'tables' => [['heading' => '', 'before' => [], 'after' => [], 'groups' => [], 'columns' => $cols, 'rows' => $rows]],
        'notes' => ['Hàng tồn kho lấy theo số tồn hiện tại của kho và giá vốn; máy móc, thiết bị là nguyên giá tài sản cố định chưa thanh lý. Để có số liệu tại đúng ngày 31/12, hãy xuất bảng này vào ngày đó hoặc kiểm kê thực tế và sửa lại số lượng.'],
        'agent' => 'tt18', 'sign_note' => '(Chữ ký, ghi rõ họ tên; chức vụ và đóng dấu (nếu có)/ Ký điện tử)']);
}

function tkSoOut($v, bool $num): string
{
    if ($num) return is_numeric($v) ? number_format((float) $v, 0, ',', '.') : (string) $v;
    return (string) $v;
}

/** Ve mau to khai (HTML). */
function tkRender(array $r): void
{
    $E = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    ?>
<style>@media print{.noprint{display:none!important}}</style>
<div class="tk-form" style="font-family:'Times New Roman',serif;font-size:14px;color:#000;background:#fff;padding:18px 22px;line-height:1.5;">
  <div style="text-align:right;"><b><?= $E($r['form_label']) ?></b><br><small><i><?= $E($r['ref']) ?></i></small></div>
  <div style="text-align:center;"><b>CỘNG HÒA XÃ HỘI CHỦ NGHĨA VIỆT NAM</b><br><u>Độc lập - Tự do - Hạnh phúc</u></div>
  <div style="text-align:center;font-weight:700;font-size:17px;margin:10px 0 4px;"><?= nl2br($E($r['title'])) ?></div>
  <?php foreach ($r['subtitle'] as $x): ?><div style="text-align:center;"><i><?= $E($x) ?></i></div><?php endforeach; ?>
  <?php if ($r['choices_first']): ?><div style="margin:6px 0;"><?php foreach ($r['choices'] as $c): ?><div><?= $E($c) ?></div><?php endforeach; ?></div><?php endif; ?>
  <?php foreach ($r['extra'] as $x): ?><div style="text-align:center;"><?= $E($x) ?></div><?php endforeach; ?>
  <?php if ($r['period'] !== ''): ?><div style="text-align:center;"><b><?= $E($r['period']) ?></b></div><?php endif; ?>
  <?php foreach ($r['period_lines'] as $x): ?><div style="text-align:center;"><?= $E($x) ?></div><?php endforeach; ?>
  <div style="margin:8px 0;"><?php foreach ($r['header'] as $h): ?><div><?= $E($h) ?></div><?php endforeach; ?></div>
  <?php if (!$r['choices_first']): foreach ($r['choices'] as $c): ?><div><?= $E($c) ?></div><?php endforeach; endif; ?>
  <?php if ($r['unit'] !== ''): ?><div style="text-align:right;font-style:italic;"><?= $E($r['unit']) ?></div><?php endif; ?>
  <?php foreach ($r['tables'] as $t): $nc = count($t['columns']); ?>
    <?php if (!empty($t['heading'])): ?><div style="font-weight:700;margin:12px 0 4px;"><?= $E($t['heading']) ?></div><?php endif; ?>
    <?php foreach ($t['before'] ?? [] as $x): ?><div><?= $E($x) ?></div><?php endforeach; ?>
    <div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:12.5px;">
      <?php foreach ($t['groups'] ?? [] as $g): ?><tr><?php foreach ($g as [$gl, $sp]): ?><th colspan="<?= (int) $sp ?>" style="border:1px solid #000;padding:3px 4px;text-align:center;"><?= $E($gl) ?></th><?php endforeach; ?></tr><?php endforeach; ?>
      <tr><?php foreach ($t['columns'] as $c): ?><th style="border:1px solid #000;padding:3px 4px;text-align:center;"><?= $E($c['label']) ?></th><?php endforeach; ?></tr>
      <tr><?php foreach ($t['columns'] as $c): ?><th style="border:1px solid #000;padding:2px 4px;text-align:center;font-weight:400;font-style:italic;font-size:11px;"><?= $E($c['code']) ?></th><?php endforeach; ?></tr>
      <?php foreach ($t['rows'] as $row): ?>
        <?php if ($row['type'] === 'group'): ?><tr><td colspan="<?= $nc ?>" style="border:1px solid #000;padding:3px 5px;font-weight:700;"><?= $E($row['cells'][0]) ?></td></tr>
        <?php else: $bold = in_array($row['type'], ['total'], true); $cells = $row['cells']; $last = count($cells) - 1; ?>
          <tr<?= $bold ? ' style="font-weight:700;"' : '' ?>>
            <?php foreach ($cells as $i => $v): $num = $t['columns'][$i]['num'] ?? false; $span = ($row['type'] === 'span' && $i === $last) ? ' colspan="' . ($nc - $last) . '"' : ''; ?>
              <td<?= $span ?> style="border:1px solid #000;padding:3px 5px;<?= ($num || $span) && is_numeric($v) ? 'text-align:right;' : '' ?>"><?= $E(tkSoOut($v, (bool) ($num || $span))) ?></td>
            <?php endforeach; ?>
          </tr>
        <?php endif; ?>
      <?php endforeach; ?>
    </table></div>
    <?php foreach ($t['after'] ?? [] as $x): ?><div><?= $E($x) ?></div><?php endforeach; ?>
  <?php endforeach; ?>
  <?php foreach ($r['footnotes'] as $n): ?><div style="font-size:12.5px;"><i><?= $E($n) ?></i></div><?php endforeach; ?>
  <?php
  $ag = $r['agent'] === 'tt89'
      ? ['NGƯỜI TRỰC TIẾP THỰC HIỆN<br>DỊCH VỤ LÀM THỦ TỤC VỀ THUẾ', 'Họ và tên: ................................', 'Chứng chỉ nghiệp vụ chuyên môn về thuế số: .........']
      : ['NHÂN VIÊN ĐẠI LÝ THUẾ', 'Họ và tên: ................................', 'Chứng chỉ hành nghề số: .........'];
  ?>
  <p style="margin:12px 0 6px;"><?= $E($r['pledge']) ?></p>
  <table style="width:100%;border:0;border-collapse:collapse;"><tr>
    <td style="width:45%;vertical-align:top;border:0;"><b><?= $ag[0] ?></b><br><?= $E($ag[1]) ?><br><?= $E($ag[2]) ?></td>
    <td style="text-align:center;vertical-align:top;border:0;">………., ngày ... tháng ... năm ......<br><b>NGƯỜI NỘP THUẾ hoặc<br>ĐẠI DIỆN HỢP PHÁP CỦA NGƯỜI NỘP THUẾ</b><br><i><?= $E($r['sign_note']) ?></i><br><br><br><br><?= $E($r['signer']) ?></td>
  </tr></table>
  <?php foreach ($r['notes'] as $n): ?><p class="noprint" style="font-family:Arial,sans-serif;font-size:12px;color:#64748b;margin:6px 0 0;">* <?= $E($n) ?></p><?php endforeach; ?>
</div>
    <?php
}

/** Chuyen mau thanh cac dong cho file Excel. */
function tkExcelRows(array $r): array
{
    $x = [[$r['form_label']], [$r['ref']], ['CỘNG HÒA XÃ HỘI CHỦ NGHĨA VIỆT NAM'], ['Độc lập - Tự do - Hạnh phúc'], [str_replace("\n", ' - ', $r['title'])]];
    foreach ($r['subtitle'] as $s) $x[] = [$s];
    if ($r['choices_first']) foreach ($r['choices'] as $c) $x[] = [$c];
    foreach ($r['extra'] as $s) $x[] = [$s];
    if ($r['period'] !== '') $x[] = [$r['period']];
    foreach ($r['period_lines'] as $s) $x[] = [$s];
    foreach ($r['header'] as $h) $x[] = [$h];
    if (!$r['choices_first']) foreach ($r['choices'] as $c) $x[] = [$c];
    if ($r['unit'] !== '') $x[] = [$r['unit']];
    foreach ($r['tables'] as $t) {
        $x[] = [''];
        if (!empty($t['heading'])) $x[] = [$t['heading']];
        foreach ($t['before'] ?? [] as $b) $x[] = [$b];
        foreach ($t['groups'] ?? [] as $g) { $line = []; foreach ($g as [$gl, $sp]) { $line[] = $gl; for ($i = 1; $i < $sp; $i++) $line[] = ''; } $x[] = $line; }
        $x[] = array_column($t['columns'], 'label');
        $x[] = array_column($t['columns'], 'code');
        foreach ($t['rows'] as $row) $x[] = $row['type'] === 'group' ? [$row['cells'][0]] : array_map(fn($v) => is_numeric($v) ? (string) $v : (string) $v, $row['cells']);
        foreach ($t['after'] ?? [] as $b) $x[] = [$b];
    }
    $x[] = [''];
    foreach ($r['footnotes'] as $n) $x[] = [$n];
    $x[] = [$r['pledge']];
    $x[] = ['Người nộp thuế hoặc đại diện hợp pháp: ' . $r['signer']];
    return $x;
}
