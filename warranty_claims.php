<?php
/**
 * Danh sach YEU CAU bao hanh (§9 dac ta).
 *
 * Truoc day chi co warranty_claim_form.php (tao) va warranty_claim_view.php (xem 1 cai), deu phai
 * di vong qua tung phieu bao hanh moi toi duoc - khong co cho nao nhin thay "hom nay con bao nhieu
 * yeu cau chua xu ly". Trang nay bu dung cho do.
 */
require_once __DIR__ . '/inc_header.php';

$pdo = db();
$tenantId = currentTenantId();

$statusLabels = [
    'PENDING' => 'Chờ xử lý', 'PROCESSING' => 'Đang xử lý',
    'DONE' => 'Đã xong', 'REJECTED' => 'Từ chối',
];
$statusBadges = [
    'PENDING' => 'badge-red', 'PROCESSING' => 'badge-gray',
    'DONE' => 'badge-green', 'REJECTED' => 'badge-gray',
];

$status = $_GET['status'] ?? '';
$q = trim($_GET['q'] ?? '');

// Loc theo tenant qua products (giong warranty_card_view.php) - warranty_claims khong co
// tenant_id rieng, no ke thua qua phieu bao hanh -> san pham.
$where = ['p.tenant_id = ?'];
$params = [$tenantId];
if (array_key_exists($status, $statusLabels)) {
    $where[] = 'cl.status = ?';
    $params[] = $status;
}
if ($q !== '') {
    $where[] = '(cl.code LIKE ? OR w.code LIKE ? OR p.name LIKE ? OR cus.name LIKE ? OR cus.phone LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like);
}

$stmt = $pdo->prepare(
    'SELECT cl.*, w.code AS card_code, p.name AS product_name,
            cus.name AS customer_name, cus.phone AS customer_phone, u.name AS created_by_name
     FROM warranty_claims cl
     JOIN warranty_cards w ON w.id = cl.warranty_card_id
     JOIN products p ON p.id = w.product_id
     LEFT JOIN customers cus ON cus.id = w.customer_id
     LEFT JOIN users u ON u.id = cl.created_by_id
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY cl.created_at DESC LIMIT 200'
);
$stmt->execute($params);
$claims = $stmt->fetchAll();

// Dem theo trang thai de nguoi dung thay ngay con bao nhieu viec ton, khong phai tu loc rồi đếm.
$countStmt = $pdo->prepare(
    'SELECT cl.status, COUNT(*) AS n FROM warranty_claims cl
     JOIN warranty_cards w ON w.id = cl.warranty_card_id
     JOIN products p ON p.id = w.product_id
     WHERE p.tenant_id = ? GROUP BY cl.status'
);
$countStmt->execute([$tenantId]);
$counts = array_column($countStmt->fetchAll(), 'n', 'status');
?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;gap:8px;flex-wrap:wrap;">
  <h1 style="font-size:24px;font-weight:600;margin:0;">Yêu cầu bảo hành</h1>
  <a href="warranty_cards.php" class="btn btn-secondary">Phiếu bảo hành</a>
</div>

<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;">
  <?php foreach ($statusLabels as $k => $label): ?>
    <a href="?status=<?= e($k) ?>" class="card" style="padding:10px 16px;text-decoration:none;<?= $status === $k ? 'border-color:#2563eb;' : '' ?>">
      <div class="muted" style="font-size:12px;"><?= e($label) ?></div>
      <div style="font-size:20px;font-weight:700;<?= $k === 'PENDING' && (int) ($counts[$k] ?? 0) > 0 ? 'color:#dc2626;' : '' ?>"><?= (int) ($counts[$k] ?? 0) ?></div>
    </a>
  <?php endforeach; ?>
  <?php if ($status !== '' || $q !== ''): ?>
    <a href="warranty_claims.php" class="card" style="padding:10px 16px;text-decoration:none;display:flex;align-items:center;">Bỏ lọc</a>
  <?php endif; ?>
</div>

<form style="margin-bottom:16px;display:flex;gap:8px;flex-wrap:wrap;">
  <input type="hidden" name="status" value="<?= e($status) ?>">
  <input type="text" name="q" class="input" style="max-width:320px;" placeholder="Tìm theo mã yêu cầu, mã phiếu, sản phẩm, khách hàng..." value="<?= e($q) ?>">
  <button type="submit" class="btn btn-secondary">Tìm</button>
</form>

<div class="card" style="padding:0;overflow-x:auto;">
  <table>
    <thead>
      <tr>
        <th>Mã yêu cầu</th><th>Phiếu bảo hành</th><th>Sản phẩm</th><th>Khách hàng</th>
        <th>Lỗi khách báo</th><th>Trạng thái</th><th>Người tạo</th><th>Ngày tạo</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$claims): ?>
        <tr><td colspan="8" class="text-center muted" style="padding:32px 16px;">
          <div style="font-weight:600;color:#334155;margin-bottom:6px;">Chưa có yêu cầu bảo hành nào</div>
          <div style="font-size:13px;">Yêu cầu bảo hành được tạo từ một <a href="warranty_cards.php"><b>phiếu bảo hành</b></a> đã cấp cho khách.</div>
        </td></tr>
      <?php endif; ?>
      <?php foreach ($claims as $cl): ?>
        <tr>
          <td><a href="warranty_claim_view.php?id=<?= (int) $cl['id'] ?>" style="font-family:monospace;"><?= e($cl['code']) ?></a></td>
          <td class="muted" style="font-family:monospace;font-size:12px;"><?= e($cl['card_code']) ?></td>
          <td><?= e($cl['product_name']) ?></td>
          <td>
            <?= e($cl['customer_name'] ?: '—') ?>
            <?php if ($cl['customer_phone']): ?><div class="muted" style="font-size:12px;"><?= e($cl['customer_phone']) ?></div><?php endif; ?>
          </td>
          <td class="muted" style="font-size:13px;max-width:280px;"><?= e($cl['issue_description']) ?></td>
          <td><span class="badge <?= e($statusBadges[$cl['status']] ?? 'badge-gray') ?>"><?= e($statusLabels[$cl['status']] ?? $cl['status']) ?></span></td>
          <td class="muted" style="font-size:13px;"><?= e($cl['created_by_name'] ?: '—') ?></td>
          <td class="muted" style="font-size:12px;"><?= date('d/m/Y H:i', strtotime($cl['created_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
