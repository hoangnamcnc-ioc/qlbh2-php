<?php
/**
 * To khai thue ho kinh doanh dien san tu so lieu da ghi (theo bo mau cua KT-HKD):
 *  - 01/CNKD (to khai quy, TT 50/2026/TT-BTC), 02/CNKD-TNCN-QTT (quyet toan TNCN nam, ho tinh tren thu nhap),
 *  - Mau 01 giam 30% thue TNCN theo NQ 43/2026/QH16 (ND 398/2026), bang ke thu mua khong hoa don 02/TNDN,
 *  - 01/TB-DDKD (thong bao dia diem kinh doanh), 01/TKN-CNKD (thong bao doanh thu nam), tong hop thue nam.
 * Chi la ban doi chieu de nhap vao ung dung ke khai cua co quan thue, khong phai ban nop.
 */
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
require_once __DIR__ . '/inc_xlsx.php';
$currentUser = requireRole('ADMIN', 'MANAGER');

$pdo = db();
$tenantId = currentTenantId();
const TK_NGUONG = 1000000000;
const TK_NGUONG_GIAM = 10000000000;

$nam = (string) ($_GET['nam'] ?? date('Y'));
if (!preg_match('/^\d{4}$/', $nam)) $nam = date('Y');
$nam = (int) $nam;
$quy = (int) ($_GET['quy'] ?? ceil((int) date('n') / 3));
if ($quy < 1 || $quy > 4) $quy = 1;
$mau = (string) ($_GET['mau'] ?? 'tonghop');
$dsMau = [
    'tonghop' => 'Tổng hợp thuế năm', '01cnkd' => '01/CNKD (quý)', '02qtt' => '02/CNKD-TNCN-QTT (quyết toán)',
    'giam43' => 'Giảm 30% TNCN (NQ 43)', '01tkn' => '01/TKN-CNKD (doanh thu năm)', 'thumua' => 'Bảng kê thu mua không hóa đơn',
    'ddkd' => '01/TB-ĐĐKD (địa điểm)', 'bkstk' => '01/BK-STK (số tài khoản)', 'bkhtk' => '01/BK-HTK (tồn kho, máy móc)',
    'sansang' => 'Sẵn sàng kê khai', 'dubao' => 'Dự báo ngưỡng doanh thu',
];
$tabNhan = ['sansang' => 'Sẵn sàng kê khai', 'tonghop' => 'Tổng hợp theo quý', '01tkn' => 'Thông báo doanh thu', 'dubao' => 'Dự báo ngưỡng',
    '01cnkd' => 'Tờ khai quý 01/CNKD', '02qtt' => 'Quyết toán TNCN', 'thumua' => 'Bảng kê thu mua không hóa đơn', 'giam43' => 'Giảm 30% TNCN (NĐ 398)',
    'ddkd' => 'Thông báo địa điểm KD', 'bkstk' => 'Thông báo số tài khoản', 'bkhtk' => 'Bảng kê tồn kho, máy móc'];
if (!isset($dsMau[$mau])) $mau = 'tonghop';
$nhomThue = getSetting('accounting_tax_group', '2') === '3' ? '3' : '2';   // 2: TNCN theo % doanh thu, 3: theo thu nhập

// Luu thong tin nguoi nop thue (dung cho dau to khai)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'info') {
    checkCsrf();
    setSetting('store_tax_code', mb_substr(post('tax_code'), 0, 20));
    setSetting('store_owner', mb_substr(post('owner'), 0, 100));
    setSetting('store_address', mb_substr(post('address'), 0, 255));
    logActivity('TAX_INFO', 'cap nhat thong tin nguoi nop thue');
    redirect('to_khai_thue.php?mau=' . urlencode($mau) . '&nam=' . $nam . '&quy=' . $quy);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bank') {
    checkCsrf();
    setSetting('tax_bank_accounts', mb_substr(trim((string) ($_POST['accounts'] ?? '')), 0, 2000));
    logActivity('TAX_BANK', 'cap nhat so tai khoan thong bao thue');
    redirect('to_khai_thue.php?mau=bkstk&nam=' . $nam);
}
$nnt = [
    'ten' => getSetting('store_name', ''), 'mst' => getSetting('store_tax_code', ''), 'chu' => getSetting('store_owner', ''),
    'dia_chi' => getSetting('store_address', ''), 'sdt' => getSetting('store_phone', ''),
];

/** Doanh thu thuan theo quy (tru hang tra lai theo quy phat sinh tra hang). */
function tkDoanhThuQuy(PDO $pdo, int $tenantId, int $nam): array
{
    $q = array_fill(1, 4, 0.0);
    $s = $pdo->prepare("SELECT QUARTER(o.created_at) q, COALESCE(SUM(o.total_amount),0) v FROM orders o JOIN branches b ON b.id=o.branch_id
        WHERE YEAR(o.created_at)=? AND o.status NOT IN ('CANCELLED','DRAFT') AND b.tenant_id=? GROUP BY QUARTER(o.created_at)");
    $s->execute([$nam, $tenantId]);
    foreach ($s->fetchAll() as $r) $q[(int) $r['q']] += (float) $r['v'];
    $s = $pdo->prepare("SELECT QUARTER(r.created_at) q, COALESCE(SUM(r.refund_amount),0) v FROM order_returns r JOIN orders o ON o.id=r.order_id JOIN branches b ON b.id=o.branch_id
        WHERE YEAR(r.created_at)=? AND b.tenant_id=? GROUP BY QUARTER(r.created_at)");
    $s->execute([$nam, $tenantId]);
    foreach ($s->fetchAll() as $r) $q[(int) $r['q']] = max(0.0, $q[(int) $r['q']] - (float) $r['v']);
    return $q;
}

/** Quy dau tien doanh thu luy ke vuot 1 ty; null neu chua vuot. */
function tkQuyDauVuot(array $rev): ?int
{
    $cum = 0.0;
    foreach ([1, 2, 3, 4] as $k) {
        $cum += $rev[$k];
        if ($cum > TK_NGUONG) return $k;
    }
    return null;
}

/**
 * So lieu 01/CNKD cua mot quy (nhom nganh phan phoi hang hoa: GTGT 1%, TNCN 0,5%).
 * Ho tinh TNCN theo % doanh thu: [16] la phan 1 ty duoc tru o quy dau tien phat sinh nghia vu.
 */
function tkQuy(array $rev, int $quy, string $nhomThue): array
{
    $first = tkQuyDauVuot($rev);
    $liable = $first !== null && $quy >= $first;
    $r = $rev[$quy];
    $cum = array_sum(array_slice($rev, 0, $quy, true));
    $total = $liable ? ($quy === $first ? $cum : $r) : $r;
    $exempt = $liable ? 0.0 : $r;
    $vat = $liable ? round(($total - $exempt) * 0.01) : 0.0;
    $base = $total - $exempt;
    $ded = ($nhomThue === '2' && $liable && $quy === $first) ? min((float) TK_NGUONG, $cum) : 0.0;
    $pit = $liable ? round(max(0.0, $base - $ded) * 0.005) : 0.0;
    return ['first' => $first, 'liable' => $liable, 'total' => $total, 'exempt' => $exempt, 'vat' => $vat, 'base' => $base, 'ded' => $ded, 'pit' => $pit];
}

function tkSo(float $v): string { return number_format(round($v), 0, ',', '.'); }
function tkTieuDe(array $nnt, string $ma, string $ref, string $tieuDe, string $ky): array
{
    return ['ma' => $ma, 'ref' => $ref, 'tieu_de' => $tieuDe, 'ky' => $ky, 'dau' => [
        'Người nộp thuế: ' . $nnt['chu'] . ' (hộ kinh doanh: ' . $nnt['ten'] . ')', 'Mã số thuế: ' . ($nnt['mst'] ?: '……………'),
        'Địa chỉ: ' . ($nnt['dia_chi'] ?: '……………') . ($nnt['sdt'] ? '   Điện thoại: ' . $nnt['sdt'] : ''),
    ], 'bang' => [], 'ghi_chu' => []];
}

$rev = tkDoanhThuQuy($pdo, $tenantId, $nam);
$tongNam = array_sum($rev);
$first = tkQuyDauVuot($rev);
$form = null;

if ($mau === '01cnkd') {
    $f = tkQuy($rev, $quy, $nhomThue);
    $form = tkTieuDe($nnt, '01/CNKD', '(Kèm theo Thông tư số 50/2026/TT-BTC ngày 13/5/2026 của Bộ trưởng Bộ Tài chính)', 'TỜ KHAI THUẾ ĐỐI VỚI HỘ KINH DOANH, CÁ NHÂN KINH DOANH', '[01] Kỳ tính thuế: Quý ' . $quy . ' năm ' . $nam . '   [02] Lần đầu: ☑   [03] Bổ sung lần thứ: ....');
    $cols = ['STT [08]', 'Nhóm ngành nghề [09]', 'Mã chỉ tiêu [10]', 'Tổng doanh thu [11]', 'Không chịu thuế GTGT [12]', 'Chịu thuế suất 0% [13]', 'Thuế GTGT phải nộp [14]', 'Doanh thu chịu thuế TNCN [15]', 'Doanh thu được trừ [16]', 'Thuế TNCN phải nộp [17]'];
    $nhom = [['1.1', 'Phân phối, cung cấp hàng hóa', '(a)', true], ['1.2', 'Dịch vụ, xây dựng không bao thầu nguyên vật liệu', '(b)', false], ['1.3', 'Cho thuê tài sản trừ bất động sản', '(c)', false],
        ['1.4', 'Sản xuất, vận tải, dịch vụ gắn với hàng hóa, xây dựng bao thầu nguyên vật liệu', '(d)', false], ['1.5', 'Cung cấp sản phẩm nội dung thông tin số', '(đ)', false], ['1.6', 'Hoạt động kinh doanh khác', '(e)', false]];
    $rows = [['nhom', 'I. Hoạt động sản xuất, kinh doanh hàng hóa, cung cấp dịch vụ có địa điểm kinh doanh cố định (1. Trụ sở kinh doanh)']];
    foreach ($nhom as [$stt, $ten, $ch, $co]) {
        $rows[] = ['dong', $stt, $ten, $ch, $co ? tkSo($f['total']) : '0', $co ? tkSo($f['exempt']) : '0', '0', $co ? tkSo($f['vat']) : '0', $co ? tkSo($f['base']) : '0', $co ? tkSo($f['ded']) : '0', $co ? tkSo($f['pit']) : '0'];
    }
    $rows[] = ['nhom', 'II. Hoạt động kinh doanh trên nền tảng thương mại điện tử không có chức năng thanh toán: hộ tự bổ sung nếu có'];
    $rows[] = ['tong', 'IV', 'Tổng cộng', '[18]', tkSo($f['total']), tkSo($f['exempt']), '0', tkSo($f['vat']), tkSo($f['base']), tkSo($f['ded']), tkSo($f['pit'])];
    $rows[] = ['dong', 'V', 'Số thuế được miễn', '[19]', '', '', '', '0', '', '', '0'];
    $rows[] = ['tong', 'VI', 'Số thuế còn phải nộp', '[20]', '', '', '', tkSo($f['vat']), '', '', tkSo($f['pit'])];
    $form['bang'][] = ['tieu_de' => 'A. KÊ KHAI THUẾ GTGT, TNCN', 'cot' => $cols, 'dong' => $rows];
    $form['ghi_chu'][] = 'Bản điền sẵn từ đơn hàng đã ghi sổ (doanh thu thuần, đã trừ hàng trả lại), để đối chiếu và nhập vào ứng dụng kê khai của cơ quan thuế; không phải bản nộp. Cửa hàng được xếp vào nhóm (a) phân phối, cung cấp hàng hóa: GTGT 1%, TNCN 0,5%.';
    if (!$f['liable']) {
        $form['ghi_chu'][] = $first === null
            ? 'Doanh thu lũy kế năm ' . $nam . ' chưa vượt 1 tỷ đồng: chưa phải nộp thuế GTGT, TNCN; toàn bộ doanh thu ghi ở cột "không chịu thuế GTGT". Hộ chỉ cần nộp thông báo doanh thu năm (Mẫu 01/TKN-CNKD).'
            : 'Quý ' . $quy . ' trước quý phát sinh nghĩa vụ (quý ' . $first . '): chưa phải khai thuế; mẫu này chỉ để tham khảo.';
    } elseif ($quy === $first) {
        $form['ghi_chu'][] = 'Quý ' . $quy . ' là quý doanh thu lũy kế vượt 1 tỷ đồng: doanh thu tính thuế là doanh thu lũy kế từ đầu năm, các quý sau tính theo từng quý (NĐ 68/2026 Điều 8, 9 đã sửa bởi NĐ 141/2026).';
    }
    $form['ghi_chu'][] = $nhomThue === '3'
        ? 'Hộ tính TNCN trên thu nhập: số thuế TNCN [17] là số tạm nộp theo quý = 0,5% × doanh thu tính thuế; cột [16] không có số trừ. Quyết toán cả năm dùng Mẫu 02/CNKD-TNCN-QTT.'
        : 'Hộ tính TNCN theo % doanh thu: cột [16] là phần 1 tỷ đồng được trừ ở quý đầu tiên phát sinh nghĩa vụ (NĐ 68/2026 Điều 4 khoản 3).';
    $form['ghi_chu'][] = 'Chưa có dữ liệu trong phần mềm: doanh thu theo từng địa điểm, hoạt động trên sàn không có chức năng thanh toán, thuế tiêu thụ đặc biệt, tài nguyên, bảo vệ môi trường (dùng sổ S3a), số thuế được miễn. Hộ tự bổ sung khi nhập vào ứng dụng của cơ quan thuế.';
    if ($f['liable'] && $nhomThue === '2') {
        $form['chan'] = 'Bạn còn được tiếp tục trừ ' . tkSo(max(0.0, TK_NGUONG - $f['ded'])) . ' đồng vào doanh thu tính thuế TNCN của kỳ tiếp theo.';
    }
} elseif ($mau === '02qtt') {
    $cp = tongHopChiPhiNam($pdo, $tenantId, (string) $nam);
    // chi phi duoc tru theo nhom a-e cua mau
    $c = ['a' => $cp['gia_von'], 'b' => 0.0, 'c' => $cp['khau_hao'], 'd' => 0.0, 'dd' => 0.0, 'e' => $cp['phan_bo_tra_truoc']];
    $q = $pdo->prepare('SELECT ce.category, COALESCE(SUM(ce.amount),0) v FROM cashbook_entries ce JOIN branches b ON b.id=ce.branch_id
        WHERE ' . dieuKienChiKinhDoanh() . ' AND ce.deductible = 1 AND YEAR(ce.created_at)=? AND b.tenant_id=? GROUP BY ce.category');
    $q->execute([$nam, $tenantId]);
    foreach ($q->fetchAll() as $r) {
        $k = (string) $r['category'];
        if ($k === 'SALARY') $c['b'] += (float) $r['v'];
        elseif (in_array($k, ['RENT', 'UTILITY', 'TRANSPORT', 'REPAIR', 'MARKETING', 'FEE'], true)) $c['d'] += (float) $r['v'];
        else $c['e'] += (float) $r['v'];
    }
    $chi = array_sum($c);
    $thuNhap = $tongNam - $chi;
    $suat = thueSuatTncnThuNhap($tongNam);
    $pit = $thuNhap > 0 ? round($thuNhap * $suat) : 0.0;
    $tam = 0.0;
    for ($k = 1; $k <= 4; $k++) $tam += tkQuy($rev, $k, '3')['pit'];
    $net = $pit - $tam;
    $phaiNop = max(0.0, $net);
    $mien = ($phaiNop > 0 && $phaiNop <= 50000) ? $phaiNop : 0.0;
    $thua = max(0.0, -$net);
    $form = tkTieuDe($nnt, '02/CNKD-TNCN-QTT', '(Kèm theo Thông tư số 89/2026/TT-BTC ngày 30 tháng 6 năm 2026 của Bộ trưởng Bộ Tài chính)', 'TỜ KHAI QUYẾT TOÁN THUẾ THU NHẬP CÁ NHÂN', '[01] Kỳ tính thuế: Năm ' . $nam . '   [02] Lần đầu: ☑   [03] Bổ sung lần thứ: ....');
    $R = fn(string $stt, string $nd, string $ma, float|string $v, bool $tong = false) => [$tong ? 'tong' : 'dong', $stt, $nd, $ma, is_string($v) ? $v : tkSo($v)];
    $rows = [
        $R('1', 'Doanh thu bán hàng hoá và cung cấp dịch vụ [09] = [09a]+[09b]+[09c]', '[09]', $tongNam),
        $R('1.1', 'Doanh thu từ hoạt động sản xuất kinh doanh không gồm kinh doanh trên nền tảng TMĐT, nền tảng số', '[09a]', $tongNam),
        $R('1.2', 'Doanh thu trên nền tảng TMĐT đã được khấu trừ, nộp thay', '[09b]', 0),
        $R('1.3', 'Doanh thu trên nền tảng TMĐT không có chức năng thanh toán trực tiếp', '[09c]', 0),
        $R('2', 'Các khoản chi được trừ [10] = [10a]+[10b]+[10c]+[10d]+[10đ]+[10e]', '[10]', $chi),
        $R('2.1', 'Chi phí nguyên liệu, vật liệu, nhiên liệu, năng lượng, hàng hóa sử dụng vào sản xuất, kinh doanh', '[10a]', $c['a']),
        $R('2.2', 'Chi phí tiền lương, tiền công, phụ cấp, bảo hiểm bắt buộc cho người lao động', '[10b]', $c['b']),
        $R('2.3', 'Chi phí khấu hao tài sản cố định phục vụ sản xuất, kinh doanh', '[10c]', $c['c']),
        $R('2.4', 'Chi phí dịch vụ mua ngoài: điện, nước, điện thoại, internet, vận chuyển, thuê tài sản, sửa chữa, bảo dưỡng', '[10d]', $c['d']),
        $R('2.5', 'Chi phí trả lãi tiền vay vốn sản xuất, kinh doanh', '[10đ]', $c['dd']),
        $R('2.6', 'Các khoản chi khác phục vụ trực tiếp hoạt động sản xuất, kinh doanh', '[10e]', $c['e']),
        $R('3', 'Thu nhập tính thuế [11] = [09] − [10]', '[11]', $thuNhap, true),
        $R('4', 'Thuế suất (%)', '[12]', rtrim(rtrim(number_format($suat * 100, 2, ',', ''), '0'), ',')),
        $R('5', 'Số thuế TNCN phát sinh trong kỳ [13] = [11] x [12]', '[13]', $pit, true),
        $R('6', 'Số thuế TNCN đã khấu trừ', '[14]', 0),
        $R('7', 'Số thuế TNCN đã tạm nộp trong năm', '[15]', $tam),
        $R('8', 'Số thuế TNCN được giảm do thiên tai, dịch bệnh, hỏa hoạn, tai nạn, bệnh hiểm nghèo', '[16]', 0),
        $R('9', 'Tổng số thuế TNCN còn phải nộp [17] = [13] − [14] − [15] − [16] ≥ 0', '[17]', $phaiNop, true),
        $R('10', 'Số thuế TNCN được miễn từ 50.000 đồng trở xuống [18]', '[18]', $mien),
        $R('11', 'Số thuế TNCN còn phải nộp sau miễn [19] = [17] − [18]', '[19]', $phaiNop - $mien, true),
        $R('12', 'Tổng số thuế TNCN nộp thừa [20] = [13] − [14] − [15] − [16] < 0', '[20]', $thua),
    ];
    $form['bang'][] = ['tieu_de' => 'A. KÊ KHAI THÔNG TIN TÍNH THUẾ', 'cot' => ['STT', 'Chỉ tiêu', 'Mã chỉ tiêu', 'Số tiền'], 'dong' => $rows];
    $ton = $pdo->prepare("SELECT COALESCE(SUM(i.quantity * p.cost_price),0) FROM inventory i JOIN products p ON p.id=i.product_id JOIN branches b ON b.id=i.branch_id WHERE b.tenant_id=?");
    try { $ton->execute([$tenantId]); $tonKho = (float) $ton->fetchColumn(); } catch (Throwable $e) { $tonKho = 0.0; }
    $form['bang'][] = ['tieu_de' => 'B. VẬT LIỆU, DỤNG CỤ, SẢN PHẨM, HÀNG HÓA (theo sổ S2d)', 'cot' => ['STT [25]', 'Nội dung [26]', 'Tồn cuối kỳ [30]'], 'dong' => [
        ['dong', '1', 'Hàng tồn kho tại thời điểm hiện tại (theo giá vốn)', tkSo($tonKho)]]];
    $form['bang'][] = ['tieu_de' => 'C. HỖ TRỢ THÔNG TIN NỘP THUẾ', 'cot' => ['STT [35]', 'Nội dung các khoản nộp NSNN [36]', 'Số tiền [37]', 'Chương [38]', 'Tiểu mục [39]', 'Cơ quan thuế [42]', 'Hạn nộp [43]'], 'dong' => [
        ['dong', '1', 'Thuế TNCN còn phải nộp', tkSo($phaiNop - $mien), '', '', '', '31/03/' . ($nam + 1)]]];
    if ($nhomThue !== '3') $form['ghi_chu'][] = 'Cửa hàng đang chọn tính TNCN theo % doanh thu (nhóm 2): mẫu này không áp dụng; số liệu bên dưới chỉ để so sánh với cách tính trên thu nhập. Đổi cách tính ở trang Kế toán và Thuế.';
    $form['ghi_chu'][] = 'Bản điền sẵn để đối chiếu và nhập vào ứng dụng của cơ quan thuế; không phải bản nộp. Thuế suất [12] theo bậc doanh thu cả năm: 15% đến 3 tỷ, 17% trên 3 tỷ đến 50 tỷ, 20% trên 50 tỷ (Luật TNCN Điều 7 khoản 2).';
    $form['ghi_chu'][] = 'Số đã tạm nộp [15] là số phần mềm tính theo quý (0,5% × doanh thu tính thuế); nếu đã nộp thực tế khác, hãy sửa theo chứng từ nộp tiền. Chi phí chỉ gồm khoản đã đánh dấu "được trừ" (cùng giá vốn, khấu hao, phân bổ trả trước); khoản chi từ 5 triệu đồng trả tiền mặt cần chứng từ không dùng tiền mặt mới được trừ (NĐ 68/2026 Điều 6).';
    if ($cp['chua_phan_loai_so'] > 0) $form['ghi_chu'][] = 'Còn ' . $cp['chua_phan_loai_so'] . ' khoản chi chưa phân loại (' . tkSo($cp['chua_phan_loai_tien']) . ' đ) chưa được tính vào chi phí được trừ: hãy phân loại ở trang Chi phí trước khi lập tờ khai.';
    $form['ghi_chu'][] = 'Chưa có dữ liệu: mục D (hoàn trả, bù trừ nộp thừa), giảm thuế do thiên tai [16], thuế sàn đã khấu trừ [14], doanh thu sàn không có chức năng thanh toán [09c].';
} elseif ($mau === 'giam43') {
    $cut = ($nam === 2026 || $nam === 2027) && $tongNam <= TK_NGUONG_GIAM ? 0.30 : 0.0;
    $dong = [];
    $tong = [0.0, 0.0];
    if ($nhomThue === '3') {
        $cpx = tongHopChiPhiNam($pdo, $tenantId, (string) $nam);
        $thuNhapX = max(0.0, $tongNam - $cpx['gia_von'] - $cpx['chi_phi_duoc_tru']);
        $pitX = $tongNam > TK_NGUONG ? round($thuNhapX * thueSuatTncnThuNhap($tongNam)) : 0.0;
        $ky = 'Năm ' . $nam . ' (hộ tính TNCN trên thu nhập, theo tờ khai quyết toán 02/CNKD-TNCN-QTT)';
        if ($pitX > 0) $dong[] = [$pitX, round($pitX * $cut)];
    } else {
        $f = tkQuy($rev, $quy, $nhomThue);
        $ky = 'Quý ' . $quy . ' năm ' . $nam . ' (theo tờ khai 01/CNKD cùng kỳ)';
        if ($f['pit'] > 0) $dong[] = [$f['pit'], round($f['pit'] * $cut)];
    }
    $rows = [];
    foreach ($dong as $i => [$p, $g]) { $rows[] = ['dong', (string) ($i + 1), '', 'Trụ sở kinh doanh', 'Phân phối, cung cấp hàng hóa', tkSo($p), tkSo($g)]; $tong = [$tong[0] + $p, $tong[1] + $g]; }
    if (!$rows) $rows[] = ['dong', '1', '', '', '', '0', '0'];
    $rows[] = ['tong', '', '', 'Tổng cộng [13], [14]', '', tkSo($tong[0]), tkSo($tong[1])];
    $form = tkTieuDe($nnt, 'Mẫu số 01', '(Kèm theo Nghị định số 398/2026/NĐ-CP ngày 29 tháng 10 năm 2026 của Chính phủ)', 'GIẢM THUẾ THU NHẬP CÁ NHÂN THEO NGHỊ QUYẾT SỐ 43/2026/QH16', 'Kỳ tính thuế: ' . $ky);
    $form['bang'][] = ['tieu_de' => '', 'cot' => ['STT [06]', 'Mã địa điểm [07]', 'Tên địa điểm kinh doanh [08]', 'Hoạt động sản xuất kinh doanh [09]', 'Nhóm ngành nghề [10]', 'Số thuế TNCN phải nộp [11]', 'Số thuế TNCN được giảm [12]'], 'dong' => $rows];
    $form['ghi_chu'][] = 'Nộp kèm tờ khai thuế TNCN của kỳ (NĐ 398/2026/NĐ-CP Điều 2 khoản 4 điểm a). Được giảm 30% số thuế TNCN phải nộp của kỳ tính thuế năm 2026, 2027 nếu doanh thu năm không quá 10 tỷ đồng, không phân biệt cách tính thuế.';
    $form['ghi_chu'][] = $cut > 0
        ? 'Doanh thu năm ' . $nam . ' đến nay ' . tkSo($tongNam) . ' đ: thuộc diện được giảm. Nếu cả năm vượt 10 tỷ đồng thì phải nộp lại số đã giảm theo Mẫu số 03 kèm tờ khai của kỳ vượt.'
        : (in_array($nam, [2026, 2027], true) ? 'Doanh thu năm ' . $nam . ' đã vượt 10 tỷ đồng: không thuộc diện được giảm; nếu đã kê khai giảm ở kỳ trước thì phải nộp lại theo Mẫu số 03.' : 'Mức giảm chỉ áp dụng cho kỳ tính thuế năm 2026 và 2027.');
} elseif ($mau === '01tkn') {
    $form = tkTieuDe($nnt, '01/TKN-CNKD', '(Kèm theo Thông tư số 18/2026/TT-BTC ngày 05/3/2026 của Bộ trưởng Bộ Tài chính)', 'THÔNG BÁO DOANH THU NĂM', 'Năm ' . $nam . ' (hộ có doanh thu năm từ 01 tỷ đồng trở xuống, nộp trước 31/01/' . ($nam + 1) . ')');
    $rows = [];
    foreach ([1, 2, 3, 4] as $k) $rows[] = ['dong', (string) $k, 'Quý ' . $k . '/' . $nam, tkSo($rev[$k])];
    $rows[] = ['tong', '', 'Tổng doanh thu năm ' . $nam . ' (doanh thu thuần)', tkSo($tongNam)];
    $form['bang'][] = ['tieu_de' => '', 'cot' => ['STT', 'Kỳ', 'Doanh thu (đồng)'], 'dong' => $rows];
    $form['ghi_chu'][] = $tongNam <= TK_NGUONG
        ? 'Doanh thu năm không quá 1 tỷ đồng: chưa phải nộp thuế GTGT, TNCN; hộ chỉ thông báo doanh thu năm cho cơ quan thuế trước 31/01 năm sau.'
        : 'Doanh thu năm ' . $nam . ' đã vượt 1 tỷ đồng: hộ khai và nộp thuế theo quý (Mẫu 01/CNKD), không dùng mẫu thông báo này.';
    $form['ghi_chu'][] = 'Bản điền sẵn doanh thu từ đơn hàng; hộ kiểm tra lại và nhập vào ứng dụng của cơ quan thuế. Không phải bản nộp.';
} elseif ($mau === 'thumua') {
    $tu = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['tu'] ?? '') ? $_GET['tu'] : "$nam-01-01";
    $den = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['den'] ?? '') ? $_GET['den'] : "$nam-12-31";
    $s = $pdo->prepare("SELECT r.code, COALESCE(r.invoice_date, DATE(r.created_at)) ngay, r.reference_no, s.name sname, s.address saddr, s.phone sphone, s.tax_code stax,
            p.name pname, p.unit, i.quantity, i.cost_price
        FROM stock_receipts r JOIN branches b ON b.id=r.branch_id LEFT JOIN suppliers s ON s.id=r.supplier_id
        JOIN stock_receipt_items i ON i.receipt_id=r.id JOIN products p ON p.id=i.product_id
        WHERE b.tenant_id=? AND COALESCE(r.invoice_date, DATE(r.created_at)) BETWEEN ? AND ?
          AND (r.reference_no IS NULL OR r.reference_no = '') AND (s.tax_code IS NULL OR s.tax_code = '')
        ORDER BY ngay, r.id, i.id");
    $s->execute([$tenantId, $tu, $den]);
    $rows = [];
    $tong = 0.0;
    foreach ($s->fetchAll() as $r) {
        $tt = (float) $r['quantity'] * (float) $r['cost_price'];
        $tong += $tt;
        $rows[] = ['dong', date('d/m/Y', strtotime($r['ngay'])), (string) $r['sname'], (string) $r['saddr'], '', (string) $r['sphone'], $r['pname'],
            rtrim(rtrim(number_format((float) $r['quantity'], 3, ',', '.'), '0'), ',') . ' ' . $r['unit'], tkSo((float) $r['cost_price']), tkSo($tt), $r['code']];
    }
    $rows[] = ['tong', '', 'Tổng giá trị hàng hóa, dịch vụ mua vào', '', '', '', '', '', '', tkSo($tong), ''];
    $form = tkTieuDe($nnt, '02/TNDN', '(Ban hành kèm theo Thông tư số 20/2026/TT-BTC của Bộ trưởng Bộ Tài chính)', 'BẢNG KÊ THU MUA HÀNG HÓA, DỊCH VỤ KHÔNG CÓ HÓA ĐƠN', 'Từ ngày ' . date('d/m/Y', strtotime($tu)) . ' đến ngày ' . date('d/m/Y', strtotime($den)));
    $form['bang'][] = ['tieu_de' => '', 'cot' => ['Ngày mua (1)', 'Tên người bán (2)', 'Địa chỉ (3)', 'Số căn cước (4)', 'Số điện thoại (5)', 'Tên hàng hóa, dịch vụ (6)', 'Số lượng (7)', 'Đơn giá (8)', 'Tổng giá thanh toán (9)', 'Ghi chú: số phiếu nhập (10)'], 'dong' => $rows];
    $form['ghi_chu'][] = 'Chỉ gồm các phiếu nhập hàng không có số hóa đơn đầu vào và nhà cung cấp chưa có mã số thuế. Phần mềm chưa lưu số căn cước của người bán nên cột (4) để trống, hãy ghi tay hoặc xin giấy tờ khi mua.';
    $form['ghi_chu'][] = 'Với hộ tính TNCN trên thu nhập, khoản mua không có hóa đơn chỉ được trừ khi có đủ chứng từ theo quy định (NĐ 68/2026 Điều 6); khoản thanh toán từ 5 triệu đồng trở lên cần chứng từ không dùng tiền mặt.';
} elseif ($mau === 'ddkd') {
    $loai = ['establish' => 'Thành lập địa điểm kinh doanh', 'change' => 'Thay đổi thông tin', 'suspend' => 'Tạm ngừng kinh doanh', 'resume' => 'Khôi phục tạm ngừng kinh doanh trước thời hạn', 'close' => 'Chấm dứt hoạt động'];
    $lo = isset($loai[$_GET['loai'] ?? '']) ? $_GET['loai'] : 'establish';
    $s = $pdo->prepare('SELECT name, address, phone FROM branches WHERE tenant_id = ? ORDER BY id');
    $s->execute([$tenantId]);
    $cn = $s->fetchAll();
    $form = tkTieuDe($nnt, '01/TB-ĐĐKD', '(Kèm theo Thông tư số 18/2026/TT-BTC ngày 05/3/2026 của Bộ trưởng Bộ Tài chính)', 'THÔNG BÁO về việc thành lập/thay đổi thông tin/tạm ngừng/chấm dứt hoạt động đối với địa điểm kinh doanh', 'Loại thông báo: ' . $loai[$lo]);
    $form['dau'][] = 'Kính gửi: (Cơ quan thuế quản lý trực tiếp NNT) ……………';
    $cot = match ($lo) {
        'establish' => ['Tên địa điểm kinh doanh', 'Hình thức kinh doanh TMĐT', 'Ngày bắt đầu hoạt động', 'Ngành nghề kinh doanh chính', 'Địa chỉ', 'Điện thoại'],
        'change' => ['Tên địa điểm kinh doanh', 'Chuyển thành trụ sở chính (x)', 'Ngày bắt đầu hoạt động', 'Ngành nghề chính', 'Địa chỉ', 'Điện thoại'],
        'suspend' => ['Tên địa điểm kinh doanh', 'Từ ngày', 'Đến ngày', 'Lý do tạm ngừng', 'Địa chỉ', 'Điện thoại'],
        'resume' => ['Tên địa điểm kinh doanh', 'Đã thông báo tạm ngừng từ ngày', 'Đến ngày', 'Tiếp tục kinh doanh từ ngày', 'Địa chỉ', 'Điện thoại'],
        default => ['Tên địa điểm kinh doanh', 'Ngày chấm dứt hoạt động', 'Lý do chấm dứt', '', 'Địa chỉ', 'Điện thoại'],
    };
    $rows = [];
    foreach ($cn as $c) $rows[] = ['dong', $c['name'], '', '', '', (string) $c['address'], (string) $c['phone']];
    if (!$rows) $rows[] = ['dong', '', '', '', '', '', ''];
    $form['bang'][] = ['tieu_de' => '', 'cot' => $cot, 'dong' => $rows];
    $form['ghi_chu'][] = 'Bản điền sẵn từ danh sách chi nhánh. Các thông tin phần mềm chưa lưu (ngày bắt đầu, ngành nghề chính, lý do) hộ tự điền.';
    $form['ghi_chu'][] = 'Thời hạn thông báo: 10 ngày làm việc kể từ ngày địa điểm đi vào hoạt động, thay đổi thông tin, tạm ngừng hoặc chấm dứt (NĐ 68/2026 Điều 8 khoản 4 điểm đ). Gửi cơ quan thuế quản lý trực tiếp trụ sở của hộ.';
}

if ($mau === 'bkstk') {
    $rows = [];
    foreach (preg_split('/\r?\n/', getSetting('tax_bank_accounts', '')) as $ln) {
        if (trim($ln) === '') continue;
        $p = array_map('trim', explode('|', $ln)) + ['', '', ''];
        $rows[] = ['dong', (string) (count($rows) + 1), $p[0], $p[1], $p[2] !== '' ? $p[2] : $nnt['chu'], 'Thêm mới'];
    }
    if (!$rows) $rows[] = ['dong', '1', '', '', '', ''];
    $form = tkTieuDe($nnt, '01/BK-STK', '(Kèm theo Thông tư số 18/2026/TT-BTC ngày 05/3/2026 của Bộ trưởng Bộ Tài chính)', 'THÔNG BÁO SỐ TÀI KHOẢN NGÂN HÀNG, SỐ HIỆU VÍ ĐIỆN TỬ DÙNG CHO HOẠT ĐỘNG KINH DOANH', 'Gửi cơ quan thuế quản lý trực tiếp');
    $form['bang'][] = ['tieu_de' => '', 'cot' => ['STT', 'Tổ chức cung ứng dịch vụ thanh toán (ngân hàng, ví)', 'Số tài khoản/số hiệu ví', 'Tên chủ tài khoản', 'Nội dung thông báo'], 'dong' => $rows];
    $form['ghi_chu'][] = 'Hộ kinh doanh có doanh thu năm trên 1 tỷ đồng phải thông báo số tài khoản dùng cho hoạt động kinh doanh với cơ quan thuế. Nhập danh sách tài khoản ở khung phía trên, mỗi dòng một tài khoản.';
}
if ($mau === 'bkhtk') {
    $s = $pdo->prepare("SELECT p.name, p.unit, SUM(i.quantity) q, p.cost_price FROM inventory i JOIN products p ON p.id=i.product_id JOIN branches b ON b.id=i.branch_id
        WHERE b.tenant_id=? AND i.quantity > 0 GROUP BY p.id, p.name, p.unit, p.cost_price ORDER BY p.name");
    $s->execute([$tenantId]);
    $rows = [];
    $tong = 0.0;
    foreach ($s->fetchAll() as $r) {
        $gt = (float) $r['q'] * (float) $r['cost_price'];
        $tong += $gt;
        $rows[] = ['dong', (string) (count($rows) + 1), $r['name'], (string) $r['unit'], rtrim(rtrim(number_format((float) $r['q'], 3, ',', '.'), '0'), ','), tkSo((float) $r['cost_price']), tkSo($gt)];
    }
    $rows[] = ['tong', '', 'Tổng giá trị hàng tồn kho', '', '', '', tkSo($tong)];
    $ts = $pdo->prepare('SELECT code, name, cost, acquired_date FROM fixed_assets WHERE tenant_id=? AND disposed_date IS NULL ORDER BY acquired_date');
    $ts->execute([$tenantId]);
    $rows2 = [];
    $tt = 0.0;
    foreach ($ts->fetchAll() as $r) { $tt += (float) $r['cost']; $rows2[] = ['dong', (string) (count($rows2) + 1), $r['name'] . ' (' . $r['code'] . ')', date('d/m/Y', strtotime($r['acquired_date'])), tkSo((float) $r['cost'])]; }
    $rows2[] = ['tong', '', 'Tổng nguyên giá', '', tkSo($tt)];
    $form = tkTieuDe($nnt, '01/BK-HTK', '(Kèm theo Thông tư số 18/2026/TT-BTC ngày 05/3/2026 của Bộ trưởng Bộ Tài chính)', 'BẢNG KÊ HÀNG TỒN KHO, MÁY MÓC, THIẾT BỊ', 'Tại thời điểm ' . date('d/m/Y'));
    $form['bang'][] = ['tieu_de' => 'I. HÀNG HÓA TỒN KHO (giá vốn)', 'cot' => ['STT', 'Tên hàng hóa', 'ĐVT', 'Số lượng', 'Đơn giá', 'Thành tiền'], 'dong' => $rows];
    $form['bang'][] = ['tieu_de' => 'II. MÁY MÓC, THIẾT BỊ (tài sản cố định đang dùng)', 'cot' => ['STT', 'Tên tài sản', 'Ngày mua', 'Nguyên giá'], 'dong' => $rows2];
    $form['ghi_chu'][] = 'Số liệu lấy từ tồn kho hiện tại và danh sách tài sản cố định chưa thanh lý. Hộ bắt đầu nộp thuế theo doanh thu trên 1 tỷ cần lập bảng kê tại ngày 31/12 của năm trước: xuất Excel và lưu lại vào ngày đó.';
}

// Xuat Excel
if ($form && isset($_GET['export'])) {
    $x = [[$form['ma'] . ' - ' . $form['tieu_de']], [$form['ref']], [$form['ky']]];
    foreach ($form['dau'] as $d) $x[] = [$d];
    foreach ($form['bang'] as $b) {
        $x[] = [''];
        if ($b['tieu_de'] !== '') $x[] = [$b['tieu_de']];
        $x[] = $b['cot'];
        foreach ($b['dong'] as $d) $x[] = $d[0] === 'nhom' ? [$d[1]] : array_slice($d, 1);
    }
    $x[] = [''];
    foreach ($form['ghi_chu'] as $g) $x[] = ['Ghi chú: ' . $g];
    logActivity('EXPORT_TOKHAI', $mau . ' ' . $nam);
    downloadXlsx($x, 'to_khai_' . $mau . '_' . $nam . '.xlsx');
    exit;
}

$qs = fn(array $o = []) => 'to_khai_thue.php?' . http_build_query(array_merge(['mau' => $mau, 'nam' => $nam, 'quy' => $quy], $o));
$inMode = isset($_GET['in']);
if (!$inMode) require_once __DIR__ . '/inc_header.php';
else echo '<!doctype html><html lang="vi"><head><meta charset="utf-8"><title>' . e($form['ma'] ?? 'Tờ khai') . '</title></head><body style="font-family:Times New Roman,serif;margin:20px;">';
?>
<?php if (!$inMode): ?>
<div style="display:flex;gap:4px;flex-wrap:wrap;margin:0 0 16px;border-bottom:1px solid #e2e8f0;padding-bottom:6px;">
  <a href="accounting.php?nam=<?= e((string) $nam) ?>#han-nop" style="padding:8px 12px;border-radius:8px 8px 0 0;text-decoration:none;color:#334155;">Lịch &amp; hạn nộp</a>
  <?php foreach ($tabNhan as $k => $t): $on = $k === $mau; ?>
    <a href="<?= e($qs(['mau' => $k])) ?>" style="padding:8px 12px;border-radius:8px 8px 0 0;text-decoration:none;<?= $on ? 'background:var(--brand,#2563eb);color:#fff;font-weight:600;' : 'color:#334155;' ?>"><?= e($t) ?></a>
  <?php endforeach; ?>
  <a href="accounting.php?nam=<?= e((string) $nam) ?>" style="padding:8px 12px;text-decoration:none;color:#334155;">Sổ S1a–S2e</a>
  <a href="thue_khac.php" style="padding:8px 12px;text-decoration:none;color:#334155;">Sổ thuế khác S3a</a>
</div>
<h1 style="font-size:24px;font-weight:600;margin-bottom:6px;">Tờ khai thuế hộ kinh doanh</h1>
<p class="muted" style="font-size:13px;margin:0 0 14px;">Điền sẵn từ đơn hàng, chi phí, khấu hao đã ghi sổ để đối chiếu và nhập vào ứng dụng kê khai của cơ quan thuế. Không phải bản nộp, không thay thế tư vấn của kế toán.</p>

<form method="get" class="card" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;margin-bottom:12px;">
  <label>Mẫu<br><select name="mau" onchange="this.form.submit()"><?php foreach ($dsMau as $k => $t): ?><option value="<?= e($k) ?>"<?= $k === $mau ? ' selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></label>
  <label>Năm<br><input type="number" name="nam" value="<?= e((string) $nam) ?>" min="2020" max="2100" style="width:90px;"></label>
  <?php if (in_array($mau, ['01cnkd', 'giam43'], true)): ?><label>Quý<br><select name="quy"><?php for ($k = 1; $k <= 4; $k++): ?><option value="<?= $k ?>"<?= $k === $quy ? ' selected' : '' ?>>Quý <?= $k ?></option><?php endfor; ?></select></label><?php endif; ?>
  <?php if ($mau === 'thumua'): ?><label>Từ<br><input type="date" name="tu" value="<?= e($_GET['tu'] ?? "$nam-01-01") ?>"></label><label>Đến<br><input type="date" name="den" value="<?= e($_GET['den'] ?? "$nam-12-31") ?>"></label><?php endif; ?>
  <?php if ($mau === 'ddkd'): ?><label>Loại<br><select name="loai"><?php foreach ($loai as $k => $t): ?><option value="<?= e($k) ?>"<?= $k === ($lo ?? '') ? ' selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></label><?php endif; ?>
  <button class="btn" type="submit">Xem</button>
  <?php if ($form): ?><a class="btn" href="<?= e($qs(['export' => 1] + array_filter(['tu' => $_GET['tu'] ?? null, 'den' => $_GET['den'] ?? null, 'loai' => $_GET['loai'] ?? null]))) ?>">Xuất Excel</a>
  <a class="btn" target="_blank" href="<?= e($qs(['in' => 1] + array_filter(['tu' => $_GET['tu'] ?? null, 'den' => $_GET['den'] ?? null, 'loai' => $_GET['loai'] ?? null]))) ?>">In</a><?php endif; ?>
</form>

<details class="card" style="margin-bottom:12px;"<?= $nnt['mst'] === '' ? ' open' : '' ?>>
  <summary style="cursor:pointer;font-weight:600;">Thông tin người nộp thuế (in ở đầu tờ khai)</summary>
  <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;margin-top:10px;">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="info">
    <label>Mã số thuế<br><input name="tax_code" value="<?= e($nnt['mst']) ?>" maxlength="20"></label>
    <label>Chủ hộ kinh doanh<br><input name="owner" value="<?= e($nnt['chu']) ?>" maxlength="100"></label>
    <label style="flex:1;min-width:240px;">Địa chỉ trụ sở<br><input name="address" value="<?= e($nnt['dia_chi']) ?>" maxlength="255" style="width:100%;"></label>
    <button class="btn" type="submit">Lưu</button>
  </form>
</details>
<?php endif; ?>

<?php if ($mau === 'sansang'): ?>
  <?php
  $cpS = tongHopChiPhiNam($pdo, $tenantId, (string) $nam);
  $ds = [
      ['Đã nhập mã số thuế, chủ hộ, địa chỉ ở đầu tờ khai', $nnt['mst'] !== '' && $nnt['chu'] !== '' && $nnt['dia_chi'] !== '', 'Nhập ở khung "Thông tin người nộp thuế" phía trên.'],
      ['Mọi khoản chi đã phân loại được trừ / không được trừ', $cpS['chua_phan_loai_so'] === 0, 'Còn ' . (int) $cpS['chua_phan_loai_so'] . ' khoản chưa phân loại (' . tkSo($cpS['chua_phan_loai_tien']) . ' đ). Vào trang Chi phí.'],
      ['Đã khóa sổ kỳ trước', ngayKhoaSo() !== null, 'Khóa sổ để số liệu đã kê khai không bị sửa nữa (trang Chi phí).'],
      ['Đã chọn cách tính TNCN (% doanh thu hoặc thu nhập)', getSetting('accounting_tax_group', '') !== '', 'Chọn ở trang Kế toán và Thuế.'],
      ['Đã khai báo số tài khoản ngân hàng nếu doanh thu trên 1 tỷ', $tongNam <= TK_NGUONG || trim(getSetting('tax_bank_accounts', '')) !== '', 'Lập mẫu 01/BK-STK.'],
  ];
  ?>
  <div class="card"><h2 style="font-size:15px;font-weight:600;margin:0 0 10px;">Sẵn sàng kê khai năm <?= e((string) $nam) ?></h2>
    <table class="table" style="width:100%;max-width:760px;">
      <?php foreach ($ds as [$t, $ok, $g]): ?><tr><td style="width:36px;font-size:18px;"><?= $ok ? '✅' : '⚠️' ?></td><td><?= e($t) ?><?php if (!$ok): ?><div class="muted" style="font-size:12px;"><?= e($g) ?></div><?php endif; ?></td></tr><?php endforeach; ?>
    </table>
    <p class="muted" style="font-size:13px;margin:10px 0 0;">Xong các mục trên thì lập <a href="<?= e($qs(['mau' => '01cnkd'])) ?>">tờ khai quý 01/CNKD</a> hoặc <a href="<?= e($qs(['mau' => '01tkn'])) ?>">thông báo doanh thu năm</a>.</p>
  </div>
<?php elseif ($mau === 'dubao'): ?>
  <?php
  $thangQua = $nam === (int) date('Y') ? max(1, (int) date('n')) : 12;
  $tb = $tongNam / $thangQua;
  $moc = [TK_NGUONG => '1 tỷ (bắt đầu nộp thuế)', 3000000000 => '3 tỷ (bắt buộc tính TNCN trên thu nhập)'];
  $d30 = $pdo->prepare("SELECT COALESCE(SUM(o.total_amount),0) FROM orders o JOIN branches b ON b.id=o.branch_id WHERE o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND o.status NOT IN ('CANCELLED','DRAFT') AND b.tenant_id=?");
  $d30->execute([$tenantId]);
  $doanh30 = (float) $d30->fetchColumn();
  ?>
  <div class="card"><h2 style="font-size:15px;font-weight:600;margin:0 0 10px;">Dự báo ngưỡng doanh thu năm <?= e((string) $nam) ?></h2>
    <table class="table" style="width:100%;max-width:700px;">
      <tr><td>Doanh thu thuần đến nay</td><td style="text-align:right;"><?= tkSo($tongNam) ?></td></tr>
      <tr><td>Trung bình mỗi tháng (<?= (int) $thangQua ?> tháng)</td><td style="text-align:right;"><?= tkSo($tb) ?></td></tr>
      <tr><td>Doanh thu 30 ngày gần nhất</td><td style="text-align:right;"><?= tkSo($doanh30) ?></td></tr>
      <tr><td>Dự kiến cả năm theo nhịp hiện tại</td><td style="text-align:right;"><b><?= tkSo($tb * 12) ?></b></td></tr>
      <?php foreach ($moc as $m => $ten): $con = $m - $tongNam; ?>
        <tr><td>Mốc <?= e($ten) ?></td><td style="text-align:right;"><?= $con <= 0 ? '<b style="color:#b45309;">Đã vượt</b>' : 'Còn ' . tkSo($con) . ($doanh30 > 0 ? ' (khoảng ' . ceil($con / ($doanh30 / 30)) . ' ngày nữa)' : '') ?></td></tr>
      <?php endforeach; ?>
    </table>
    <p class="muted" style="font-size:13px;margin:10px 0 0;">Dự báo chỉ mang tính tham khảo, tính theo doanh thu 30 ngày gần nhất. Khi doanh thu lũy kế vượt 1 tỷ đồng, quý đó phải khai và nộp thuế GTGT, TNCN.</p>
  </div>
<?php elseif ($mau === 'tonghop'): ?>
  <?php
  $cpN = tongHopChiPhiNam($pdo, $tenantId, (string) $nam);
  $thuNhapN = max(0.0, $tongNam - $cpN['gia_von'] - $cpN['chi_phi_duoc_tru']);
  $suatN = thueSuatTncnThuNhap($tongNam);
  $tncnTN = $tongNam > TK_NGUONG ? round($thuNhapN * $suatN) : 0.0;
  ?>
  <div class="card" style="margin-bottom:12px;overflow-x:auto;">
    <h2 style="font-size:15px;font-weight:600;margin:0 0 10px;">Thuế phải nộp theo quý, năm <?= e((string) $nam) ?></h2>
    <table class="table" style="width:100%;min-width:560px;">
      <thead><tr><th>Quý</th><th style="text-align:right;">Doanh thu</th><th style="text-align:right;">Doanh thu tính thuế</th><th style="text-align:right;">GTGT 1%</th><th style="text-align:right;">TNCN tạm nộp</th><th>Tờ khai</th></tr></thead>
      <tbody>
      <?php $sv = $sp = 0.0; foreach ([1, 2, 3, 4] as $k): $f = tkQuy($rev, $k, $nhomThue); $sv += $f['vat']; $sp += $f['pit']; ?>
        <tr><td>Quý <?= $k ?></td><td style="text-align:right;"><?= tkSo($rev[$k]) ?></td><td style="text-align:right;"><?= tkSo($f['base']) ?></td>
          <td style="text-align:right;"><?= tkSo($f['vat']) ?></td><td style="text-align:right;"><?= tkSo($f['pit']) ?></td>
          <td><a href="<?= e($qs(['mau' => '01cnkd', 'quy' => $k])) ?>">01/CNKD quý <?= $k ?></a></td></tr>
      <?php endforeach; ?>
        <tr style="font-weight:600;"><td>Cả năm</td><td style="text-align:right;"><?= tkSo($tongNam) ?></td><td></td><td style="text-align:right;"><?= tkSo($sv) ?></td><td style="text-align:right;"><?= tkSo($sp) ?></td><td></td></tr>
      </tbody>
    </table>
    <p class="muted" style="font-size:13px;margin:10px 0 0;">
      <?php if ($first === null): ?>Doanh thu năm chưa vượt 1 tỷ đồng: chưa phải nộp thuế GTGT, TNCN; chỉ cần nộp <a href="<?= e($qs(['mau' => '01tkn'])) ?>">thông báo doanh thu năm 01/TKN-CNKD</a> trước 31/01 năm sau.
      <?php else: ?>Quý <?= (int) $first ?> là quý đầu tiên doanh thu lũy kế vượt 1 tỷ đồng, bắt đầu phải khai và nộp thuế.<?php endif; ?>
      Cách tính TNCN hiện chọn: <b><?= $nhomThue === '3' ? 'theo thu nhập (doanh thu − chi phí)' : 'theo % doanh thu' ?></b> (đổi ở trang Kế toán và Thuế).
    </p>
  </div>
  <div class="card" style="margin-bottom:12px;">
    <h2 style="font-size:15px;font-weight:600;margin:0 0 10px;">So sánh hai cách tính TNCN năm <?= e((string) $nam) ?></h2>
    <table class="table" style="width:100%;max-width:640px;">
      <tr><td>Doanh thu thuần cả năm</td><td style="text-align:right;"><?= tkSo($tongNam) ?></td></tr>
      <tr><td>Chi phí được trừ (giá vốn, chi phí đã phân loại, khấu hao, phân bổ)</td><td style="text-align:right;"><?= tkSo($cpN['gia_von'] + $cpN['chi_phi_duoc_tru']) ?></td></tr>
      <tr><td>Thu nhập tính thuế</td><td style="text-align:right;"><?= tkSo($thuNhapN) ?></td></tr>
      <tr><td>TNCN theo thu nhập (thuế suất <?= rtrim(rtrim(number_format($suatN * 100, 1, ',', ''), '0'), ',') ?>%)</td><td style="text-align:right;"><b><?= tkSo($tncnTN) ?></b></td></tr>
      <tr><td>TNCN theo % doanh thu (0,5% × (doanh thu − 1 tỷ)), chỉ dùng được đến 3 tỷ</td><td style="text-align:right;"><b><?= $tongNam > TK_NGUONG && $tongNam <= 3000000000 ? tkSo(($tongNam - TK_NGUONG) * 0.005) : '—' ?></b></td></tr>
    </table>
    <?php if ($cpN['chua_phan_loai_so'] > 0): ?><p style="color:#b45309;font-size:13px;margin:8px 0 0;">Còn <?= (int) $cpN['chua_phan_loai_so'] ?> khoản chi chưa phân loại (<?= tkSo($cpN['chua_phan_loai_tien']) ?> đ) chưa được tính vào chi phí. <a href="chi_phi.php">Phân loại ngay</a></p><?php endif; ?>
  </div>
  <div class="card"><h2 style="font-size:15px;font-weight:600;margin:0 0 8px;">Các mẫu khác</h2>
    <ul style="margin:0;padding-left:18px;line-height:1.9;">
      <?php foreach ($dsMau as $k => $t): if ($k === 'tonghop') continue; ?><li><a href="<?= e($qs(['mau' => $k])) ?>"><?= e($t) ?></a></li><?php endforeach; ?>
      <li><a href="accounting.php?nam=<?= e((string) $nam) ?>">Sổ S1a–S2e và lịch hạn nộp</a> · <a href="thue_khac.php">Sổ thuế khác S3a</a></li>
    </ul></div>
<?php elseif ($form): ?>
<?php if ($mau === 'bkstk' && !$inMode): ?>
  <form method="post" class="card" style="margin-bottom:12px;"><input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="bank">
    <label><b>Danh sách tài khoản</b> (mỗi dòng: Ngân hàng hoặc ví | Số tài khoản | Tên chủ tài khoản)<br>
    <textarea name="accounts" rows="4" style="width:100%;" placeholder="Vietcombank | 0123456789 | Nguyễn Văn A"><?= e(getSetting('tax_bank_accounts', '')) ?></textarea></label>
    <button class="btn" type="submit" style="margin-top:8px;">Lưu danh sách</button></form>
<?php endif; ?>
<div class="card" style="<?= $inMode ? 'border:0;' : '' ?>overflow-x:auto;">
  <div style="text-align:center;margin-bottom:10px;">
    <div style="font-weight:700;">CỘNG HÒA XÃ HỘI CHỦ NGHĨA VIỆT NAM</div><div style="font-weight:600;">Độc lập - Tự do - Hạnh phúc</div>
    <div style="text-align:right;font-size:13px;">Mẫu số: <b><?= e($form['ma']) ?></b></div>
    <h2 style="margin:8px 0 4px;font-size:17px;white-space:pre-line;"><?= e($form['tieu_de']) ?></h2>
    <div style="font-size:13px;"><?= e($form['ky']) ?></div>
    <div class="muted" style="font-size:12px;"><?= e($form['ref']) ?></div>
  </div>
  <div style="font-size:14px;line-height:1.7;margin-bottom:10px;"><?php foreach ($form['dau'] as $d): ?><div><?= e($d) ?></div><?php endforeach; ?></div>
  <?php foreach ($form['bang'] as $b): ?>
    <?php if ($b['tieu_de'] !== ''): ?><h3 style="font-size:14px;margin:14px 0 6px;"><?= e($b['tieu_de']) ?></h3><?php endif; ?>
    <table class="table" style="width:100%;border-collapse:collapse;font-size:13px;" border="1" cellpadding="5">
      <thead><tr><?php foreach ($b['cot'] as $c): ?><th><?= e($c) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php foreach ($b['dong'] as $d): $kind = $d[0]; ?>
        <?php if ($kind === 'nhom'): ?><tr><td colspan="<?= count($b['cot']) ?>" style="font-weight:600;background:#f1f5f9;"><?= e($d[1]) ?></td></tr>
        <?php else: ?><tr<?= $kind === 'tong' ? ' style="font-weight:700;background:#f8fafc;"' : '' ?>>
          <?php foreach (array_slice($d, 1) as $i => $cell): $so = preg_match('/^-?[\d.,]+$/', (string) $cell) && $cell !== '' && $i >= 3 - ($b['cot'][0] === 'STT' ? 0 : 0); ?>
            <td<?= $so && strlen((string) $cell) > 0 && preg_match('/^-?[\d.]+$/', (string) $cell) ? ' style="text-align:right;"' : '' ?>><?= e((string) $cell) ?></td>
          <?php endforeach; ?></tr><?php endif; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endforeach; ?>
  <?php if (!empty($form['chan'])): ?><p style="font-size:13px;margin:10px 0 0;"><?= e($form['chan']) ?></p><?php endif; ?>
  <p style="font-size:14px;margin:14px 0 6px;">Tôi cam đoan những nội dung kê khai trên là đúng và chịu trách nhiệm trước pháp luật về những nội dung đã khai./.</p>
  <div style="display:flex;justify-content:space-between;gap:20px;flex-wrap:wrap;font-size:13px;margin-top:8px;">
    <div style="text-align:center;min-width:220px;"><b>NHÂN VIÊN ĐẠI LÝ THUẾ</b><br>Họ và tên: ……………<br>Chứng chỉ hành nghề số: ……<br><i>(để trống khi hộ tự khai)</i></div>
    <div style="text-align:center;min-width:240px;">……, ngày …… tháng …… năm ……<br><b>NGƯỜI NỘP THUẾ hoặc ĐẠI DIỆN HỢP PHÁP</b><br><i>(Ký, ghi rõ họ tên)</i><br><br><br><?= e($nnt['chu']) ?></div>
  </div>
  <?php if ($form['ghi_chu']): ?><div class="muted" style="font-size:12px;margin-top:14px;border-top:1px dashed #cbd5e1;padding-top:8px;">
    <?php foreach ($form['ghi_chu'] as $g): ?><p style="margin:0 0 4px;">• <?= e($g) ?></p><?php endforeach; ?></div><?php endif; ?>
</div>
<?php endif; ?>

<?php
if ($inMode) { echo '<script>window.print()</script></body></html>'; exit; }
require_once __DIR__ . '/inc_footer.php';
