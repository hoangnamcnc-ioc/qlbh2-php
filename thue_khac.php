<?php
/**
 * So theo doi nghia vu thue khac - mau S3a-HKD (Thong tu 152/2025/TT-BTC): thue xuat nhap khau, tieu thu dac biet,
 * bao ve moi truong, tai nguyen, su dung dat... Ghi tay vi phan lon cua hang khong phat sinh; co xuat Excel.
 */
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
require_once __DIR__ . '/inc_xlsx.php';
$currentUser = requireRole('ADMIN', 'MANAGER');

$pdo = db();
$tenantId = currentTenantId();
$error = null;
$loai = [
    'XNK' => 'Thuế xuất khẩu, nhập khẩu', 'TTDB' => 'Thuế tiêu thụ đặc biệt', 'BVMT' => 'Thuế bảo vệ môi trường',
    'TNGUYEN' => 'Thuế tài nguyên', 'SDDAT' => 'Thuế sử dụng đất', 'PHI' => 'Phí, lệ phí', 'KHAC' => 'Khác',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'add') {
        $date = (string) ($_POST['entry_date'] ?? '');
        $type = array_key_exists($_POST['tax_type'] ?? '', $loai) ? $_POST['tax_type'] : 'KHAC';
        $desc = mb_substr(post('description'), 0, 255);
        $base = postFloat('tax_base');
        $payable = postFloat('amount_payable');
        $paid = postFloat('amount_paid');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $error = 'Chọn ngày ghi sổ';
        } elseif ($desc === '') {
            $error = 'Nhập diễn giải';
        } elseif ($payable < 0 || $paid < 0 || $base < 0) {
            $error = 'Số tiền không được âm';
        } elseif (kyDaKhoa($date)) {
            $error = 'Ngày này thuộc kỳ đã khóa sổ đến ' . date('d/m/Y', strtotime(ngayKhoaSo())) . '.';
        } else {
            $pdo->prepare('INSERT INTO other_tax_entries (tenant_id, entry_date, tax_type, description, tax_base, amount_payable, amount_paid, created_by_id) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$tenantId, $date, $type, $desc, $base, $payable, $paid, $currentUser['id']]);
            logActivity('OTHER_TAX_ADD', "$type $payable");
            redirect('thue_khac.php?ok=1');
        }
    } elseif ($action === 'delete') {
        $st = $pdo->prepare('SELECT entry_date FROM other_tax_entries WHERE id = ? AND tenant_id = ?');
        $st->execute([(int) ($_POST['id'] ?? 0), $tenantId]);
        $d = $st->fetchColumn();
        if ($d && kyDaKhoa((string) $d)) {
            $error = 'Dòng này thuộc kỳ đã khóa sổ, không xóa được.';
        } elseif ($d) {
            $pdo->prepare('DELETE FROM other_tax_entries WHERE id = ? AND tenant_id = ?')->execute([(int) $_POST['id'], $tenantId]);
            logActivity('OTHER_TAX_DELETE', (string) (int) $_POST['id']);
            redirect('thue_khac.php?ok=1');
        }
    }
}

$nam = preg_match('/^\d{4}$/', $_GET['nam'] ?? '') ? $_GET['nam'] : date('Y');
$st = $pdo->prepare('SELECT * FROM other_tax_entries WHERE tenant_id = ? AND YEAR(entry_date) = ? ORDER BY entry_date, id');
$st->execute([$tenantId, $nam]);
$rows = $st->fetchAll();
$tongPhaiNop = array_sum(array_map(static fn($r) => (float) $r['amount_payable'], $rows));
$tongDaNop = array_sum(array_map(static fn($r) => (float) $r['amount_paid'], $rows));

if (isset($_GET['export'])) {
    $x = [['SỔ THEO DÕI NGHĨA VỤ THUẾ KHÁC (S3a-HKD) NĂM ' . $nam, '', '', '', '', ''],
        ['Cửa hàng', getSetting('store_name', 'Cửa hàng'), '', '', '', ''],
        ['Ngày', 'Loại thuế', 'Diễn giải', 'Căn cứ tính thuế', 'Số phải nộp', 'Số đã nộp']];
    foreach ($rows as $r) {
        $x[] = [date('d/m/Y', strtotime($r['entry_date'])), $loai[$r['tax_type']] ?? $r['tax_type'], $r['description'],
            (string) round((float) $r['tax_base']), (string) round((float) $r['amount_payable']), (string) round((float) $r['amount_paid'])];
    }
    $x[] = ['', '', 'Cộng', '', (string) round($tongPhaiNop), (string) round($tongDaNop)];
    $x[] = ['', '', 'Còn phải nộp', '', (string) round($tongPhaiNop - $tongDaNop), ''];
    logActivity('EXPORT_S3A', $nam);
    downloadXlsx($x, 'so_s3a_' . $nam . '.xlsx');
    exit;
}

require_once __DIR__ . '/inc_header.php';
?>

<h1 style="font-size:24px;font-weight:600;margin-bottom:6px;">Sổ nghĩa vụ thuế khác (S3a-HKD)</h1>
<p class="muted" style="margin:0 0 16px;max-width:760px;font-size:14px;">
  Dùng khi cửa hàng phát sinh thuế xuất nhập khẩu, tiêu thụ đặc biệt, bảo vệ môi trường, tài nguyên, sử dụng đất...
  (ngoài GTGT và TNCN). Phần lớn cửa hàng bán lẻ không phát sinh nên không bắt buộc lập. <a href="accounting.php">← Kế toán và Thuế</a>
</p>

<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
<?php if (!empty($_GET['ok'])): ?><div class="alert alert-success">Đã lưu.</div><?php endif; ?>

<form method="get" class="card" style="display:flex;gap:10px;align-items:end;margin-bottom:16px;">
  <div><label>Năm</label><input type="number" name="nam" value="<?= e($nam) ?>" class="input" style="width:110px;"></div>
  <button class="btn" type="submit">Xem</button>
  <a class="btn btn-secondary" href="thue_khac.php?nam=<?= e($nam) ?>&amp;export=1">Xuất Excel</a>
</form>

<div class="card" style="margin-bottom:16px;">
  <div style="font-weight:600;margin-bottom:10px;">Ghi một dòng</div>
  <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;align-items:end;">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="add">
    <div><label>Ngày</label><input type="date" name="entry_date" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>" class="input" required></div>
    <div><label>Loại thuế</label><select name="tax_type"><?php foreach ($loai as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
    <div><label>Diễn giải</label><input class="input" name="description" required placeholder="Tờ khai nhập khẩu số..."></div>
    <div><label>Căn cứ tính thuế</label><input class="input" name="tax_base" type="number" min="0" step="1" value="0"></div>
    <div><label>Số phải nộp</label><input class="input" name="amount_payable" type="number" min="0" step="1" value="0"></div>
    <div><label>Số đã nộp</label><input class="input" name="amount_paid" type="number" min="0" step="1" value="0"></div>
    <div><button class="btn" type="submit">Ghi sổ</button></div>
  </form>
</div>

<div class="card" style="overflow-x:auto;">
  <table>
    <thead><tr><th>Ngày</th><th>Loại thuế</th><th>Diễn giải</th><th style="text-align:right;">Căn cứ</th><th style="text-align:right;">Phải nộp</th><th style="text-align:right;">Đã nộp</th><th></th></tr></thead>
    <tbody>
      <?php if (!$rows): ?><tr><td colspan="7" class="muted" style="text-align:center;padding:24px;">Năm <?= e($nam) ?> chưa có dòng nào.</td></tr><?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td style="white-space:nowrap;"><?= e(date('d/m/Y', strtotime($r['entry_date']))) ?></td>
          <td><?= e($loai[$r['tax_type']] ?? $r['tax_type']) ?></td>
          <td><?= e($r['description']) ?></td>
          <td style="text-align:right;"><?= money($r['tax_base']) ?></td>
          <td style="text-align:right;"><?= money($r['amount_payable']) ?></td>
          <td style="text-align:right;"><?= money($r['amount_paid']) ?></td>
          <td>
            <form method="post" onsubmit="return confirm('Xóa dòng này khỏi sổ?');" style="margin:0;">
              <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <button class="btn btn-secondary" style="padding:3px 10px;font-size:12px;" type="submit">Xóa</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
    <?php if ($rows): ?>
    <tfoot><tr><td colspan="4" style="text-align:right;font-weight:700;">Cộng / còn phải nộp <?= money($tongPhaiNop - $tongDaNop) ?></td>
      <td style="text-align:right;font-weight:700;"><?= money($tongPhaiNop) ?></td><td style="text-align:right;font-weight:700;"><?= money($tongDaNop) ?></td><td></td></tr></tfoot>
    <?php endif; ?>
  </table>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
