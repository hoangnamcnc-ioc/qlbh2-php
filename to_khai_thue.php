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
require_once __DIR__ . '/inc_to_khai_mau.php';

if ($mau === '01cnkd') {
    $form = tkMauCnkd($nnt, $nam, $quy, tkQuy($rev, $quy, $nhomThue), $first, $nhomThue);
} elseif ($mau === '02qtt') {
    $cp = tongHopChiPhiNam($pdo, $tenantId, (string) $nam);
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
    $kho = ['dau' => 0.0, 'nhap' => 0.0, 'xuat' => 0.0, 'cuoi' => 0.0];
    try {
        $st = $pdo->prepare('SELECT COALESCE(SUM(i.quantity * p.cost_price),0) FROM inventory i JOIN products p ON p.id=i.product_id JOIN branches b ON b.id=i.branch_id WHERE b.tenant_id=?');
        $st->execute([$tenantId]);
        $kho['cuoi'] = (float) $st->fetchColumn();
        $st = $pdo->prepare('SELECT COALESCE(SUM(sri.quantity * sri.cost_price),0) FROM stock_receipt_items sri JOIN stock_receipts sr ON sr.id=sri.receipt_id JOIN branches b ON b.id=sr.branch_id WHERE YEAR(sr.created_at)=? AND b.tenant_id=?');
        $st->execute([$nam, $tenantId]);
        $kho['nhap'] = (float) $st->fetchColumn();
        $kho['xuat'] = $cp['gia_von'];
        $kho['dau'] = max(0.0, $kho['cuoi'] - $kho['nhap'] + $kho['xuat']);
    } catch (Throwable $e) {
    }
    $form = tkMauQtt($nnt, $nam, ['rev' => $tongNam, 'chi' => $chi, 'c' => $c, 'thu_nhap' => $thuNhap, 'suat' => $suat, 'pit' => $pit, 'tam' => $tam, 'phai_nop' => $phaiNop,
        'mien' => ($phaiNop > 0 && $phaiNop <= 50000) ? $phaiNop : 0.0, 'thua' => max(0.0, -$net), 'kho' => $kho, 'nhom' => $nhomThue, 'chua_pl_so' => $cp['chua_phan_loai_so'], 'chua_pl_tien' => $cp['chua_phan_loai_tien']]);
} elseif ($mau === 'giam43') {
    $cut = ($nam === 2026 || $nam === 2027) && $tongNam <= TK_NGUONG_GIAM ? 0.30 : 0.0;
    $dong = [];
    if ($nhomThue === '3') {
        $cpx = tongHopChiPhiNam($pdo, $tenantId, (string) $nam);
        $thuNhapX = max(0.0, $tongNam - $cpx['gia_von'] - $cpx['chi_phi_duoc_tru']);
        $pitX = $tongNam > TK_NGUONG ? round($thuNhapX * thueSuatTncnThuNhap($tongNam)) : 0.0;
        $ky = 'Năm ' . $nam . ' (hộ tính TNCN trên thu nhập, theo tờ khai 02/CNKD-TNCN-QTT)';
        if ($pitX > 0) $dong[] = [$pitX, '', $nnt['ten']];
    } else {
        $f = tkQuy($rev, $quy, $nhomThue);
        $ky = 'Quý ' . $quy . ' năm ' . $nam . ' (theo tờ khai 01/CNKD cùng kỳ)';
        if ($f['pit'] > 0) $dong[] = [$f['pit'], '', 'Trụ sở kinh doanh'];
    }
    $form = tkMauGiam($nnt, $nam, $ky, $dong, $cut, $tongNam);
} elseif ($mau === '01tkn') {
    $f1 = tkQuy([1 => $tongNam, 2 => 0.0, 3 => 0.0, 4 => 0.0], 1, $nhomThue);   // ca nam xem nhu mot ky
    $form = tkMauTkn($nnt, $nam, $tongNam, $tongNam, $tongNam > TK_NGUONG, $nhomThue, $f1['vat'], $f1['pit'], $f1['ded']);
} elseif ($mau === 'thumua') {
    $tu = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['tu'] ?? '') ? $_GET['tu'] : "$nam-01-01";
    $den = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['den'] ?? '') ? $_GET['den'] : "$nam-12-31";
    $st = $pdo->prepare("SELECT r.code, COALESCE(r.invoice_date, DATE(r.created_at)) ngay, s.name sname, s.address saddr, s.phone sphone,
            p.name pname, p.unit, i.quantity, i.cost_price
        FROM stock_receipts r JOIN branches b ON b.id=r.branch_id LEFT JOIN suppliers s ON s.id=r.supplier_id
        JOIN stock_receipt_items i ON i.receipt_id=r.id JOIN products p ON p.id=i.product_id
        WHERE b.tenant_id=? AND COALESCE(r.invoice_date, DATE(r.created_at)) BETWEEN ? AND ?
          AND (r.reference_no IS NULL OR r.reference_no = '') AND (s.tax_code IS NULL OR s.tax_code = '')
        ORDER BY ngay, r.id, i.id");
    $st->execute([$tenantId, $tu, $den]);
    $rows = [];
    $tong = 0.0;
    foreach ($st->fetchAll() as $r) {
        $tt = (float) $r['quantity'] * (float) $r['cost_price'];
        $tong += $tt;
        $rows[] = [date('d/m/Y', strtotime($r['ngay'])), (string) $r['sname'], (string) $r['saddr'], '', (string) $r['sphone'], $r['pname'],
            rtrim(rtrim(number_format((float) $r['quantity'], 3, ',', '.'), '0'), ',') . ' ' . $r['unit'], (int) round((float) $r['cost_price']), (int) round($tt), $r['code']];
    }
    $form = tkMauThuMua($nnt, $tu, $den, $rows, $tong);
} elseif ($mau === 'ddkd') {
    $lo = isset(['establish' => 1, 'change' => 1, 'suspend' => 1, 'resume' => 1, 'close' => 1][$_GET['loai'] ?? '']) ? $_GET['loai'] : 'establish';
    $st = $pdo->prepare('SELECT name, address, phone FROM branches WHERE tenant_id = ? ORDER BY id');
    $st->execute([$tenantId]);
    $form = tkMauDdkd($nnt, $lo, $st->fetchAll());
} elseif ($mau === 'bkstk') {
    $acc = [];
    foreach (preg_split('/\r?\n/', getSetting('tax_bank_accounts', '')) as $ln) {
        if (trim($ln) === '') continue;
        $pp = array_map('trim', explode('|', $ln)) + ['', '', ''];
        $acc[] = [$pp[0], $pp[1], $pp[2]];
    }
    $form = tkMauStk($nnt, $acc, $nnt['ten']);
} elseif ($mau === 'bkhtk') {
    $st = $pdo->prepare("SELECT p.name, p.unit, SUM(i.quantity) q, p.cost_price FROM inventory i JOIN products p ON p.id=i.product_id JOIN branches b ON b.id=i.branch_id
        WHERE b.tenant_id=? AND i.quantity > 0 GROUP BY p.id, p.name, p.unit, p.cost_price ORDER BY p.name");
    $st->execute([$tenantId]);
    $hang = [];
    foreach ($st->fetchAll() as $r) $hang[] = ['name' => $r['name'], 'unit' => (string) $r['unit'], 'qty' => (float) $r['q'], 'value' => (float) $r['q'] * (float) $r['cost_price']];
    $st = $pdo->prepare('SELECT name, cost FROM fixed_assets WHERE tenant_id=? AND disposed_date IS NULL ORDER BY name');
    $st->execute([$tenantId]);
    $tsan = [];
    foreach ($st->fetchAll() as $r) $tsan[] = ['name' => $r['name'], 'unit' => 'cái', 'qty' => 1.0, 'value' => (float) $r['cost']];
    $form = tkMauHtk($nnt, date('Y-m-d'), $hang, $tsan);
}

// Xuat Excel
if ($form && isset($_GET['export'])) {
    logActivity('EXPORT_TOKHAI', $mau . ' ' . $nam);
    downloadXlsx(tkExcelRows($form), 'to_khai_' . $mau . '_' . $nam . '.xlsx');
    exit;
}

$qs = fn(array $o = []) => 'to_khai_thue.php?' . http_build_query(array_merge(['mau' => $mau, 'nam' => $nam, 'quy' => $quy], $o));
$inMode = isset($_GET['in']);
if (!$inMode) require_once __DIR__ . '/inc_header.php';
else echo '<!doctype html><html lang="vi"><head><meta charset="utf-8"><title>' . e($form['code'] ?? 'Tờ khai') . '</title><style>@page{size:A4 landscape;margin:10mm}.noprint{display:none}</style></head><body style="margin:0;">';
?>
<?php if (!$inMode): ?>
<?= thanhTabThue($mau) ?>
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
<?php if (!$inMode): ?><div class="card" style="background:#fffbeb;border:1px solid #fcd34d;margin-bottom:12px;font-size:13px;"><b>Kiểm tra trước khi nộp.</b> Số liệu điền sẵn từ chứng từ đã ghi sổ theo đúng bố cục mẫu của cơ quan thuế. Hãy đối chiếu các mục tự khai (ủy quyền, đại lý thuế, địa bàn, hạn nộp) rồi in ký hoặc nhập vào ứng dụng kê khai của cơ quan thuế (eTax, HTKK).</div><?php endif; ?>
<div class="card" style="<?= $inMode ? 'border:0;padding:0;' : 'overflow-x:auto;padding:0;' ?>"><?php tkRender($form); ?></div>
<?php endif; ?>

<?php
if ($inMode) { echo '<script>window.print()</script></body></html>'; exit; }
require_once __DIR__ . '/inc_footer.php';
