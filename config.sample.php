<?php
// Sao chép file này thành config.php rồi điền thông tin DB thật (config.php KHÔNG commit lên git).
//
// Quirk hosting iNet đã biết: DB_HOST phải là 127.0.0.1 (không dùng 'localhost'),
// nếu không sẽ không kết nối được.

// Tat hien thi loi PHP ra man hinh tren production - tranh lo duong dan server/query SQL khi co
// loi. Van ghi log day du vao error_log cua host de con debug duoc.
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'ten_database');
define('DB_USER', 'ten_user');
define('DB_PASS', 'mat_khau');

// Chuỗi ngẫu nhiên dùng để bảo vệ session — đổi thành chuỗi dài, ngẫu nhiên của riêng bạn.
// Tạo nhanh bằng lệnh: php -r "echo bin2hex(random_bytes(32));"
define('AUTH_SALT', 'change-this-to-a-random-long-string');

// Bi mat de goi backup_cron.php tu dong (qua cron cua hosting) - doi thanh chuoi rieng cua ban.
define('BACKUP_CRON_SECRET', 'change-this-to-another-random-long-string');

// Thanh toan VNPay - dien thong tin tu Cong thong tin thanh toan VNPay (muc "Thong tin ket noi").
// De trong ('') neu chua dung tinh nang thanh toan online - pay.php se bao "chua cau hinh".
define('VNPAY_TMN_CODE', '');
define('VNPAY_HASH_SECRET', '');
define('VNPAY_URL', 'https://sandbox.vnpayment.vn/paymentv2/vpcpay.html');
define('VNPAY_RETURN_URL', 'https://your-domain/vnpay_return.php');

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}
