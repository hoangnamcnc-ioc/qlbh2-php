<?php
/**
 * Kiem tra du lieu: tim cho du lieu sai/thieu lam bao cao va goi y thieu chinh xac (gia ban < gia von, thieu gia von, trung ma,
 * ton kho am, khach trung so dien thoai...). Chi doc, khong tu sua gi - moi dong co lien ket di thang toi cho can sua.
 */
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
require_once __DIR__ . '/inc_thong_minh.php';
requireRole('ADMIN', 'MANAGER');

$pdo = db();
$tenantId = currentTenantId();
$msg = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'auto_min_stock') {
    checkCsrf();
    $n = apDungDinhMuc($pdo, $tenantId);
    logActivity('AUTO_MIN_STOCK', 'so_dong=' . $n);
    $msg = $n > 0 ? "Đã đặt định mức tồn tối thiểu (đủ bán 7 ngày) cho $n mặt hàng." : 'Không có mặt hàng nào cần đặt thêm định mức.';
}
$goiYDm = goiYDinhMuc($pdo, $tenantId);
$sections = kiemTraDuLieu($pdo, $tenantId);
$tongVanDe = array_sum(array_column($sections, 'total'));
$soMuc = count(array_filter($sections, static fn($s) => $s['total'] > 0));

require_once __DIR__ . '/inc_header.php';
?>

<h1 style="font-size:24px;font-weight:600;margin:0 0 8px;">Kiểm tra dữ liệu</h1>
<p class="muted" style="margin:0 0 16px;font-size:13px;max-width:780px;">
  Tìm chỗ dữ liệu sai hoặc thiếu làm báo cáo, gợi ý nhập hàng và cảnh báo kém chính xác. Trang này chỉ <b>đọc</b>, không tự sửa gì —
  bấm vào từng dòng để đến đúng chỗ cần sửa.
</p>

<?php if ($msg): ?><div class="alert alert-success" style="max-width:720px;"><?= e($msg) ?></div><?php endif; ?>
<?php if ($tongVanDe === 0): ?>
  <div class="alert alert-success" style="max-width:720px;">✓ Dữ liệu sạch: không phát hiện vấn đề nào.</div>
<?php else: ?>
  <div class="alert alert-error" style="max-width:720px;">Phát hiện <b><?= (int) $tongVanDe ?></b> chỗ cần xem lại ở <b><?= (int) $soMuc ?></b> nhóm.</div>
<?php endif; ?>

<div style="display:flex;flex-direction:column;gap:12px;max-width:860px;">
  <?php foreach ($sections as $s): $ok = $s['total'] === 0; ?>
    <div class="card" style="border-left:4px solid <?= $ok ? '#059669' : '#d97706' ?>;">
      <div style="display:flex;justify-content:space-between;gap:8px;align-items:baseline;">
        <b><?= $ok ? '✓' : '⚠' ?> <?= e($s['title']) ?></b>
        <span style="font-weight:700;color:<?= $ok ? '#059669' : '#d97706' ?>;"><?= $ok ? 'Ổn' : (int) $s['total'] ?></span>
      </div>
      <?php if (!$ok): ?>
        <div class="muted" style="font-size:12px;margin:2px 0 8px;"><?= e($s['hint']) ?></div>
        <?php foreach ($s['rows'] as $r): ?>
          <a href="<?= e($r['link']) ?>" style="display:block;font-size:13px;padding:3px 0;border-top:1px solid #f1f5f9;"><?= e($r['label']) ?></a>
        <?php endforeach; ?>
        <?php if ($s['total'] > count($s['rows'])): ?><div class="muted" style="font-size:12px;margin-top:4px;">… và <?= (int) ($s['total'] - count($s['rows'])) ?> mục nữa.</div><?php endif; ?>
        <?php if ($s['key'] === 'thieu_dinh_muc' && $goiYDm): ?>
          <form method="post" style="margin-top:10px;" onsubmit="return confirm('Đặt định mức tồn tối thiểu = bán trung bình 7 ngày cho <?= count($goiYDm) ?> mặt hàng đang để 0? Các mặt hàng đã có định mức không bị đổi.');">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="auto_min_stock">
            <button class="btn" type="submit">⚙️ Tự đặt định mức cho <?= count($goiYDm) ?> mặt hàng (đủ bán 7 ngày)</button>
            <div class="muted" style="font-size:12px;margin-top:4px;">Ví dụ: <?= e(implode('; ', array_map(static fn($g) => $g['name'] . ' → ' . $g['dinh_muc'], array_slice($goiYDm, 0, 3)))) ?></div>
          </form>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
