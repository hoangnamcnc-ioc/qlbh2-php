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
    $stmt = $pdo->prepare(
        "SELECT p.id, p.sku, p.name, p.unit,
                COALESCE(i.quantity, 0) AS ton_hien_co,
                COALESCE(i.min_stock, 0) AS dinh_muc,
                COALESCE(sale.qty_30d, 0) AS ban_30_ngay
         FROM products p
         JOIN inventory i ON i.product_id = p.id AND i.branch_id = ? AND i.variant_id IS NULL
         LEFT JOIN (
             SELECT oi.product_id, SUM(oi.quantity) AS qty_30d
             FROM order_items oi JOIN orders o ON o.id = oi.order_id
             WHERE o.branch_id = ? AND o.status != 'CANCELLED' AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY oi.product_id
         ) sale ON sale.product_id = p.id
         WHERE p.tenant_id = ? AND p.is_active = 1 AND p.product_type = 'PRODUCT'"
    );
    $stmt->execute([$branchId, $branchId, $tenantId]);
    foreach ($stmt->fetchAll() as $row) {
        $ton = (float) $row['ton_hien_co'];
        $dinhMuc = (float) $row['dinh_muc'];
        $toc_do = (float) $row['ban_30_ngay'] / 30;
        $soNgayConDu = $toc_do > 0 ? $ton / $toc_do : null;

        $goiY = 0.0;
        if ($toc_do > 0 && ($soNgayConDu === null || $soNgayConDu <= $mucTieuNgay)) {
            $goiY = max($goiY, ceil($toc_do * $mucTieuNgay - $ton));
        }
        if ($dinhMuc > 0 && $ton <= $dinhMuc) {
            $goiY = max($goiY, $dinhMuc - $ton);
        }
        if ($goiY > 0) {
            $suggestions[] = $row + ['ton' => $ton, 'dinh_muc' => $dinhMuc, 'toc_do' => $toc_do,
                'so_ngay_con_du' => $soNgayConDu, 'goi_y' => $goiY];
        }
    }
    // Uu tien hang sap het truoc (it ngay con du nhat len dau) - hang chua tung ban (so_ngay_con_du
    // = null) xep sau cung vi khong khan cap bang hang dang ban chay ma sap het.
    usort($suggestions, static function (array $a, array $b): int {
        $da = $a['so_ngay_con_du'] ?? PHP_INT_MAX;
        $db = $b['so_ngay_con_du'] ?? PHP_INT_MAX;
        return $da <=> $db;
    });
}

if (isset($_GET['export']) && $suggestions) {
    $rows = [['sku', 'ten_san_pham', 'don_vi', 'ton_hien_co', 'dinh_muc_toi_thieu', 'ban_30_ngay_qua', 'so_luong_de_nghi_nhap']];
    foreach ($suggestions as $s) {
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
  Dựa trên tốc độ bán 30 ngày qua, tồn hiện có và định mức tối thiểu. Không tự tạo đơn đặt hàng —
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
        <th class="text-right">Bán 30 ngày</th><th>Còn đủ bán</th>
        <th class="text-right">Đề nghị nhập</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$branchId): ?>
        <tr><td colspan="6" class="text-center muted" style="padding:32px;">Vui lòng chọn chi nhánh.</td></tr>
      <?php elseif (!$suggestions): ?>
        <tr><td colspan="6" class="text-center muted" style="padding:32px;">Chưa có mặt hàng nào cần nhập thêm — tồn kho đang đủ dùng.</td></tr>
      <?php endif; ?>
      <?php foreach ($suggestions as $s): ?>
        <tr>
          <td class="muted" style="font-family:monospace;font-size:12px;"><?= e($s['sku']) ?></td>
          <td><a href="product_form.php?id=<?= (int) $s['id'] ?>"><?= e($s['name']) ?></a></td>
          <td class="text-right"><?= fmtQty($s['ton']) ?> <?= e($s['unit']) ?></td>
          <td class="text-right muted"><?= fmtQty($s['ban_30_ngay']) ?></td>
          <td>
            <?php if ($s['so_ngay_con_du'] === null): ?>
              <span class="badge badge-gray">Chưa bán trong 30 ngày</span>
            <?php elseif ($s['so_ngay_con_du'] <= 3): ?>
              <span class="badge badge-red"><?= (int) $s['so_ngay_con_du'] ?> ngày</span>
            <?php else: ?>
              <span class="badge badge-gray"><?= (int) $s['so_ngay_con_du'] ?> ngày</span>
            <?php endif; ?>
          </td>
          <td class="text-right" style="font-weight:700;color:#2563eb;"><?= fmtQty($s['goi_y']) ?> <?= e($s['unit']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
