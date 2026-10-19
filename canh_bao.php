<?php
/**
 * Canh bao bat thuong: huy don nhieu, chiet khau cao, chenh lech ca, hao hut tang, no qua han, hang het han chua xu ly.
 * Quy tac minh bach (xem phatHienBatThuong() trong inc_thong_minh.php), tinh tren du lieu 30 ngay qua cua cua hang.
 */
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
require_once __DIR__ . '/inc_thong_minh.php';
$currentUser = requireRole('ADMIN', 'MANAGER');

$pdo = db();
$tenantId = currentTenantId();
// MANAGER chi xem chi nhanh minh; ADMIN xem ca cua hang
$branchId = hasRole('ADMIN') ? 0 : effectiveBranchId($currentUser);
$alerts = phatHienBatThuong($pdo, $tenantId, $branchId);

$style = [
    'cao' => ['#dc2626', '#fef2f2', 'Cao'],
    'trung' => ['#d97706', '#fffbeb', 'Trung bình'],
    'thap' => ['#64748b', '#f8fafc', 'Thấp'],
];

require_once __DIR__ . '/inc_header.php';
?>

<h1 style="font-size:24px;font-weight:600;margin:0 0 8px;">Cảnh báo bất thường</h1>
<p class="muted" style="margin:0 0 16px;font-size:13px;max-width:760px;">
  Hệ thống tự rà soát 30 ngày qua để tìm dấu hiệu cần xem lại: huỷ đơn nhiều, chiết khấu cao, chênh lệch tiền mặt ca,
  hao hụt tăng, công nợ quá hạn, hàng hết hạn chưa xử lý. Đây là <b>gợi ý để kiểm tra</b>, không phải kết luận.
</p>

<?php if (!$alerts): ?>
  <div class="card" style="max-width:640px;"><b style="color:#059669;">✓ Không phát hiện điều gì bất thường.</b>
    <div class="muted" style="font-size:13px;margin-top:4px;">Các quy tắc đã chạy: huỷ đơn, chiết khấu, chênh lệch ca, hao hụt, nợ quá hạn, hàng hết hạn, trả hàng.</div></div>
<?php endif; ?>

<div style="display:flex;flex-direction:column;gap:10px;max-width:820px;">
  <?php foreach ($alerts as $al): [$color, $bg, $label] = $style[$al['muc']]; ?>
    <a href="<?= e($al['link']) ?>" style="text-decoration:none;color:inherit;">
      <div class="card" style="border-left:4px solid <?= $color ?>;background:<?= $bg ?>;display:flex;justify-content:space-between;gap:12px;align-items:center;">
        <div>
          <div style="font-weight:600;"><?= e($al['tieu_de']) ?></div>
          <div style="font-size:13px;margin-top:2px;"><?= e($al['chi_tiet']) ?></div>
        </div>
        <span style="font-size:12px;font-weight:700;color:<?= $color ?>;white-space:nowrap;"><?= $label ?> →</span>
      </div>
    </a>
  <?php endforeach; ?>
</div>

<p class="muted" style="font-size:12px;margin:16px 0 0;max-width:820px;">⚖️ Quy tắc: huỷ ≥15% (từ 3 đơn); giảm ≥25% từ 3 đơn; chênh lệch ca ≥20.000đ; xuất huỷ tuần này ≥ gấp đôi trung bình 4 tuần và ≥100.000đ; khách nợ &gt;30 ngày; phiếu nhập quá hạn trả; lô đã hết hạn còn tồn; ≥5 phiếu trả hàng/nhân viên; huỷ đơn hoặc giảm ≥25% vào khung giờ rất ít khách (&lt;3% đơn); một người huỷ ≥70% số đơn huỷ; một người đóng ca thiếu tiền từ 3 lần; hàng thiếu qua ≥2 lần kiểm kho (≥50.000đ).</p>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
