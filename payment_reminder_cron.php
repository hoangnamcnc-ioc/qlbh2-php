<?php
/**
 * Cron nhac chu cua hang: don dat online chua thanh toan qua N phut (mac dinh 60) va CHUA nhac lan nao -> gui 1 email tong hop
 * cho chu cua hang (kem SDT khach de goi), roi danh dau da nhac. Dat lich tren hosting goi URL nay moi 15-30 phut:
 *   https://app.kt-soft.vn/payment_reminder_cron.php?key=<BACKUP_CRON_SECRET>[&m=60]
 * Giong backup_cron.php / daily_cron.php: xu ly TOAN BO cua hang dang hoat dong trong 1 lan goi.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inc_functions.php';
require_once __DIR__ . '/inc_mail.php';
header('Content-Type: text/plain; charset=utf-8');

$key = $_GET['key'] ?? '';
if (empty(BACKUP_CRON_SECRET) || !hash_equals(BACKUP_CRON_SECRET, $key)) {
    http_response_code(403);
    exit('Forbidden');
}
$minutes = max(15, min(10080, (int) ($_GET['m'] ?? 60)));
$pdo = db();

$tenants = $pdo->query("SELECT id, name, owner_email FROM tenants WHERE is_active = 1 AND owner_email IS NOT NULL AND owner_email <> ''")->fetchAll();
$sent = 0; $orders = 0;
foreach ($tenants as $t) {
    $st = $pdo->prepare(
        "SELECT o.id, o.code, o.total_amount, o.paid_amount, o.created_at, c.name AS customer_name, c.phone AS customer_phone
         FROM orders o JOIN branches b ON b.id = o.branch_id LEFT JOIN customers c ON c.id = o.customer_id
         LEFT JOIN payment_reminders r ON r.order_id = o.id
         WHERE b.tenant_id = ? AND o.source = 'ONLINE' AND o.status <> 'CANCELLED' AND o.total_amount > o.paid_amount
           AND o.created_at <= DATE_SUB(NOW(), INTERVAL ? MINUTE) AND o.created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) AND r.order_id IS NULL
         ORDER BY o.created_at LIMIT 30"
    );
    $st->execute([$t['id'], $minutes]);
    $rows = $st->fetchAll();
    if (!$rows) continue;

    $lines = [];
    foreach ($rows as $r) {
        $lines[] = '- ' . $r['code'] . ' · ' . ($r['customer_name'] ?: 'Khách') . ' · ' . preg_replace('/\D/', '', (string) $r['customer_phone'])
            . ' · còn ' . number_format((float) $r['total_amount'] - (float) $r['paid_amount'], 0, ',', '.') . 'đ · đặt lúc ' . date('H:i d/m', strtotime($r['created_at']));
    }
    $body = "Có " . count($rows) . " đơn đặt online chưa thanh toán sau " . ($minutes >= 60 ? ($minutes / 60) . ' giờ' : $minutes . ' phút') . " tại cửa hàng {$t['name']}:\n\n"
        . implode("\n", $lines)
        . "\n\nVào https://app.kt-soft.vn/don_cho_thanh_toan.php để gọi/nhắn tin nhắc khách (đã soạn sẵn tin nhắn kèm mã QR thanh toán).\n"
        . "Nếu khách đã chuyển khoản mà đơn vẫn hiện ở đây, hãy kiểm tra mục Đối soát chuyển khoản tự động.\n";
    if (sendMail($t['owner_email'], '[QLBH-CLOUD] ' . count($rows) . ' đơn online chưa thanh toán', $body)) {
        $sent++;
        foreach ($rows as $r) {
            $pdo->prepare("INSERT IGNORE INTO payment_reminders (order_id, channel) VALUES (?, 'EMAIL')")->execute([$r['id']]);
            $orders++;
        }
    }
}
$flushed = mailQueueFlush(20);
echo "Da gui $sent email, nhac $orders don.\n";
echo "Hang doi email: da xu ly $flushed thu.\n";
