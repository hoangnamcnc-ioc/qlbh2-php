<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN');

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $filename = createBackup();
    logActivity('BACKUP', $filename);
    $message = "Đã tạo sao lưu: $filename";
}

$backups = listBackups();
require_once __DIR__ . '/inc_header.php';
?>

<h1 style="font-size:24px;font-weight:600;margin-bottom:16px;">Sao lưu dữ liệu</h1>

<div class="card" style="max-width:760px;">
  <p class="muted" style="margin:0 0 16px;">
    Tạo bản sao lưu toàn bộ dữ liệu (cấu trúc bảng + dữ liệu) thành 1 file <code>.sql.gz</code> —
    dùng để khôi phục thủ công qua phpMyAdmin khi cần. Hệ thống tự động giữ lại 20 bản gần nhất, bản
    cũ hơn sẽ tự động bị xóa khi tạo bản mới.
  </p>

  <?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>

  <form method="post" style="margin-bottom:20px;">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <button type="submit" class="btn">🗄️ Tạo sao lưu mới</button>
  </form>

  <table>
    <thead><tr><th>Tên file</th><th>Thời gian</th><th class="text-right">Dung lượng</th><th></th></tr></thead>
    <tbody>
      <?php if (!$backups): ?>
        <tr><td colspan="4" class="muted">Chưa có bản sao lưu nào.</td></tr>
      <?php endif; ?>
      <?php foreach ($backups as $b): ?>
        <tr>
          <td style="font-family:monospace;font-size:13px;"><?= e($b['name']) ?></td>
          <td class="muted"><?= date('d/m/Y H:i:s', $b['time']) ?></td>
          <td class="text-right"><?= number_format($b['size'] / 1024, 0) ?> KB</td>
          <td class="text-right"><a href="backup_download.php?f=<?= urlencode($b['name']) ?>">Tải xuống</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
