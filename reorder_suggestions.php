<?php
/**
 * Goi y nhap hang tu dong.
 *
 * Cong thuc: toc do ban/ngay = tong so luong ban 30 ngay qua / 30. So ngay con du hang = ton
 * hien co / toc do ban. Goi y nhap = du bao du ban trong $mucTieuNgay ngay toi, tru di ton hien
 * co. Rieng hang ban CHAM (toc do = 0) nhung DUOI dinh muc toi thieu van duoc goi y nhap ve dung
 * dinh muc - vi dinh muc la nguoi dung tu dat, phai ton trong du lieu do ke ca khi khong ban
 * duoc trong 30 ngay qua (hang moi nhap, hang theo mua...).
 *
 * Combo/dich vu khong co "nhap hang" nen loai khoi danh sach.
 */
require_once __DIR__ . '/inc_header.php';
require_once __DIR__ . '/inc_xlsx.php';
require_once __DIR__ . '/inc_thong_minh.php';

$pdo = db();
$tenantId = currentTenantId();
$branchesStmt = $pdo->prepare('SELECT * FROM branches WHERE tenant_id = ? ORDER BY name');
$branchesStmt->execute([$tenantId]);
$branches = $branchesStmt->fetchAll();

$branchId = hasRole('ADMIN', 'MANAGER') ? (int) ($_GET['branch_id'] ?? 0) : effectiveBranchId($currentUser);
if (!$branchId && $branches) {
    $branchId = (int) $branches[0]['id'];
}
if ($branchId && !in_array($branchId, array_map('intval', array_column($branches, 'id')), true)) {
    $branchId = 0;
}
$mucTieuNgay = max(1, (int) ($_GET['target_days'] ?? 14));

$suggestions = [];
if ($branchId) {
    // Du bao thong minh: toc do ban uu tien 7 ngay gan day, xu huong, ngay het hang, canh bao hang se het han truoc khi ban het
    $suggestions = array_map(static function (array $r): array {
        return $r + ['ban_30_ngay' => $r['ban_30'], 'so_ngay_con_du' => $r['so_ngay']];
    }, duBaoNhapHang($pdo, $tenantId, $branchId, $mucTieuNgay));
}

if (isset($_GET['export']) && $suggestions) {
    $rows = [['sku', 'ten_san_pham', 'don_vi', 'ton_hien_co', 'dinh_muc_toi_thieu', 'ban_30_ngay_qua', 'so_luong_de_nghi_nhap']];
    foreach ($suggestions as $s) {
        if ($s['goi_y'] <= 0) {
            continue;   // dong chi co canh bao het han, khong phai de nghi nhap
        }
        $rows[] = [$s['sku'], $s['name'], $s['unit'], fmtQty($s['ton']), fmtQty($s['dinh_muc']), fmtQty($s['ban_30_ngay']), fmtQty($s['goi_y'])];
    }
    logActivity('EXPORT_REORDER_SUGGESTIONS', 'branch_id=' . $branchId . ' so_dong=' . count($suggestions));
    downloadXlsx($rows, 'de_nghi_nhap_hang.xlsx');
    exit;
}
?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;gap:8px;flex-wrap:wrap;">
  <h1 style="font-size:24px;font-weight:600;margin:0;">Gợi ý nhập hàng</h1>
  <a href="purchase_order_form.php" class="btn btn-secondary">+ Tạo đặt hàng nhập</a>
</div>
<p class="muted" style="margin:0 0 16px;font-size:13px;max-width:680px;">
  Dựa trên tốc độ bán (ưu tiên 7 ngày gần nhất), xu hướng tăng/giảm, tồn hiện có và định mức tối thiểu; cảnh báo hàng sẽ hết hạn trước khi bán hết. Không tự tạo đơn đặt hàng —
  xuất ra Excel để gửi thẳng nhà cung cấp, hoặc tự chọn hàng cần nhập ở
  <a href="purchase_order_form.php">Tạo đặt hàng nhập</a>.
</p>

<form style="margin-bottom:16px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
  <?php if (hasRole('ADMIN', 'MANAGER')): ?>
    <select name="branch_id" class="input" style="max-width:200px;" onchange="this.form.submit()">
      <?php foreach ($branches as $b): ?>
        <option value="<?= (int) $b['id'] ?>" <?= $branchId === (int) $b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option>
      <?php endforeach; ?>
    </select>
  <?php endif; ?>
  <label class="muted" style="font-size:13px;">Dự trữ đủ bán</label>
  <select name="target_days" class="input" style="max-width:140px;" onchange="this.form.submit()">
    <?php foreach ([7, 14, 21, 30] as $d): ?>
      <option value="<?= $d ?>" <?= $mucTieuNgay === $d ? 'selected' : '' ?>><?= $d ?> ngày</option>
    <?php endforeach; ?>
  </select>
  <?php if ($suggestions): ?>
    <a href="?branch_id=<?= $branchId ?>&target_days=<?= $mucTieuNgay ?>&export=1" class="btn btn-secondary">Xuất file</a>
  <?php endif; ?>
</form>

<div class="card" style="padding:0;overflow-x:auto;">
  <table>
    <thead>
      <tr>
        <th>SKU</th><th>Sản phẩm</th><th class="text-right">Tồn hiện có</th>
        <th class="text-right">Bán 30 ngày</th><th>Xu hướng</th><th>Còn đủ bán</th>
        <th class="text-right">Đề nghị nhập</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$branchId): ?>
        <tr><td colspan="7" class="text-center muted" style="padding:32px;">Vui lòng chọn chi nhánh.</td></tr>
      <?php elseif (!$suggestions): ?>
        <tr><td colspan="7" class="text-center muted" style="padding:32px;">Chưa có mặt hàng nào cần nhập thêm — tồn kho đang đủ dùng.</td></tr>
      <?php endif; ?>
      <?php foreach ($suggestions as $s): ?>
        <tr>
          <td class="muted" style="font-family:monospace;font-size:12px;"><?= e($s['sku']) ?></td>
          <td><a href="product_form.php?id=<?= (int) $s['id'] ?>"><?= e($s['name']) ?></a></td>
          <td class="text-right"><?= fmtQty($s['ton']) ?> <?= e($s['unit']) ?></td>
          <td class="text-right muted"><?= fmtQty($s['ban_30_ngay']) ?></td>
          <td>
            <?php if ($s['xu_huong'] === null): ?><span class="muted">—</span>
            <?php elseif ($s['xu_huong'] >= 25): ?><span style="color:#059669;font-weight:600;" title="7 ngày gần nhất bán nhanh hơn trung bình 30 ngày">▲ +<?= (int) round($s['xu_huong']) ?>%</span>
            <?php elseif ($s['xu_huong'] <= -25): ?><span style="color:#d97706;font-weight:600;" title="7 ngày gần nhất bán chậm hơn trung bình 30 ngày">▼ <?= (int) round($s['xu_huong']) ?>%</span>
            <?php else: ?><span class="muted">→ ổn định</span><?php endif; ?>
          </td>
          <td>
            <?php if ($s['so_ngay_con_du'] === null): ?>
              <span class="badge badge-gray">Chưa bán trong 30 ngày</span>
            <?php elseif ($s['so_ngay_con_du'] <= 3): ?>
              <span class="badge badge-red"><?= (int) $s['so_ngay_con_du'] ?> ngày</span>
            <?php else: ?>
              <span class="badge badge-gray"><?= (int) $s['so_ngay_con_du'] ?> ngày</span>
            <?php endif; ?>
            <?php if (!empty($s['het_hang_du_kien'])): ?><div class="muted" style="font-size:11px;">hết ~<?= date('d/m', strtotime($s['het_hang_du_kien'])) ?></div><?php endif; ?>
            <?php if (!empty($s['canh_bao_han'])): ?><div style="font-size:11px;color:#dc2626;" title="Theo tốc độ bán hiện tại, lô này sẽ hết hạn trước khi bán hết">⚠ ~<?= fmtQty($s['canh_bao_han']['du']) ?> sẽ hết hạn <?= date('d/m', strtotime($s['canh_bao_han']['han'])) ?> — xả trước khi nhập</div><?php endif; ?>
          </td>
          <td class="text-right" style="font-weight:700;color:#2563eb;"><?= $s['goi_y'] > 0 ? fmtQty($s['goi_y']) . ' ' . e($s['unit']) : '—' ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
