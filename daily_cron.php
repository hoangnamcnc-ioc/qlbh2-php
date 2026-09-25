<?php
/**
 * Cron THAT chay 1 lan/ngay (khac maybeSendTrialReminder/maybeSendWeeklyReport - 2 ham do "an
 * theo" request nguoi dung, khong dam bao chay dung gio neu khong ai mo app). Dat lich trong
 * bang dieu khien hosting goi URL nay kem ?key=..., giong het backup_cron.php da co san.
 *
 * Lich de xuat: 21:00 hang ngay (sau gio ban hang cua da so quan tap hoa) - cron nay tu xu ly
 * TOAN BO tenant active trong 1 lan goi, khong can 1 lich rieng cho tung cua hang.
 *
 * Gom 2 viec trong CUNG 1 cron de nguoi dung chi can dat 1 lich hosting (thay vi 2):
 *   1. Bao cao cuoi ngay - moi ngay, neu hom do co ban hang.
 *   2. Sao luu dinh ky gui email kem link tai - moi 7 ngay/lan.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inc_functions.php';
require_once __DIR__ . '/inc_mail.php';
// sendDailyReportForTenant()/sendBackupEmailForTenant() nam trong inc_auth.php (canh
// maybeSendWeeklyReport()) - phai nap file nay, khac backup_cron.php chi can inc_functions.php
// vi createBackup() nam o do.
require_once __DIR__ . '/inc_auth.php';

header('Content-Type: text/plain; charset=utf-8');

$key = $_GET['key'] ?? '';
if (empty(BACKUP_CRON_SECRET) || !hash_equals(BACKUP_CRON_SECRET, $key)) {
    http_response_code(403);
    exit('Forbidden');
}

$pdo = db();
$tenants = $pdo->query(
    "SELECT id, name, owner_email, last_daily_report_at, last_backup_email_at
     FROM tenants WHERE is_active = 1 AND owner_email IS NOT NULL AND owner_email != ''"
)->fetchAll();

// Dem tenant DA XU LY (khong phai "da gui that", vi ca 2 ham co the bo qua neu khong du dieu
// kien - vd hom nay chua co don hang) bang cach doc lai tu DB sau vong lap, thay vi so sanh
// bien $tenant cuc bo TRUOC va SAU khi goi ham: PHP truyen mang theo GIA TRI, nen sendXxx($tenant)
// sua duoc DB nhung khong he sua bien $tenant trong vong lap nay - so sanh truoc/sau se sai (luon
// bang nhau, dem ra 0) du email van gui dung.
$idsXuLy = array_column($tenants, 'id');
$loi = [];

foreach ($tenants as $tenant) {
    try {
        sendDailyReportForTenant($tenant);
    } catch (Throwable $e) {
        $loi[] = "bao cao tenant #{$tenant['id']}: " . $e->getMessage();
    }

    try {
        sendBackupEmailForTenant($tenant);
    } catch (Throwable $e) {
        $loi[] = "sao luu tenant #{$tenant['id']}: " . $e->getMessage();
    }
}

$soBaoCaoHomNay = $idsXuLy ? (int) $pdo->query(
    'SELECT COUNT(*) FROM tenants WHERE id IN (' . implode(',', $idsXuLy) . ")
     AND DATE(last_daily_report_at) = CURDATE()"
)->fetchColumn() : 0;
$soSaoLuuVuaGui = $idsXuLy ? (int) $pdo->query(
    'SELECT COUNT(*) FROM tenants WHERE id IN (' . implode(',', $idsXuLy) . ')
     AND last_backup_email_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)'
)->fetchColumn() : 0;

// Don rac: file sao luu tenant khong con token hop le (da het han hoac da tai) qua 1 ngay van
// con nam tren dia - xoa file cu hon 8 ngay de khong phinh dung luong hosting theo thoi gian.
$soDaDon = 0;
foreach (glob(tenantBackupDir() . '/tenant*.sql.gz') as $f) {
    if (filemtime($f) < time() - 8 * 86400) {
        @unlink($f);
        $soDaDon++;
    }
}

echo "Xong. Cua hang kiem tra: " . count($tenants) . " | da co bao cao hom nay: $soBaoCaoHomNay | vua gui sao luu: $soSaoLuuVuaGui | don file cu: $soDaDon\n";
if ($loi) {
    echo "Loi (" . count($loi) . "):\n" . implode("\n", $loi) . "\n";
}
