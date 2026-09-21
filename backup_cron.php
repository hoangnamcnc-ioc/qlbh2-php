<?php
// Endpoint danh cho cron tu dong tao sao luu hang ngay - KHONG lien quan phien dang nhap admin,
// chi bao ve bang 1 token bi mat trong URL (?key=...). Goi qua HTTP vi hosting chia se khong co
// SSH/shell de dat lich cron chay script CLI truc tiep - chi co the dat lich "curl 1 URL" trong
// bang dieu khien hosting.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inc_functions.php';
require_once __DIR__ . '/inc_mail.php';

header('Content-Type: text/plain; charset=utf-8');

$key = $_GET['key'] ?? '';
if (empty(BACKUP_CRON_SECRET) || !hash_equals(BACKUP_CRON_SECRET, $key)) {
    http_response_code(403);
    exit('Forbidden');
}

$filename = createBackup();
$path = backupDir() . '/' . $filename;
$sizeKb = number_format(filesize($path) / 1024, 0);

// Bao qua email khi co loi hoac dinh ky - khong dinh kem file (co the qua lon cho mail()), chi
// bao ten file + dung luong de chu dong biet backup van chay deu, khong phai doi den luc can moi
// phat hien backup da ngung chay tu bao gio.
$subject = 'QLBH-CLOUD - Sao luu tu dong thanh cong';
$body = "Da tao sao luu tu dong: {$filename} ({$sizeKb} KB).\n"
    . "Tai xuong tai: https://" . $_SERVER['HTTP_HOST'] . "/backup.php (dang nhap tai khoan quan tri he thong).\n";
sendMail('hoangnamcnc@gmail.com', $subject, $body);

echo "OK: $filename ($sizeKb KB)\n";
