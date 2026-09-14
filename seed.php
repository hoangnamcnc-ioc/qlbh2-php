<?php
// Chay file nay 1 LAN DUY NHAT (mo tren trinh duyet: yoursite.com/seed.php) de tao tai khoan
// admin dau tien voi MAT KHAU NGAU NHIEN (khong con mat khau co dinh trong code nua - neu
// quen xoa file nay, ke xau cung khong biet duoc mat khau vi no chi hien 1 lan duy nhat va
// file se tu khoa lai ngay sau khi chay xong).

require_once __DIR__ . '/config.php';

$lockFile = __DIR__ . '/seed.lock';

if (file_exists($lockFile)) {
    http_response_code(403);
    echo "Da chay seed.php truoc do roi (thay file seed.lock). ";
    echo "Neu can tao lai tai khoan admin, xoa thu cong file seed.lock roi tai lai trang nay, ";
    echo "hoac nho ho tro ky thuat.<br>";
    echo "<b style='color:red'>Vi ly do an toan, hay xoa han file seed.php khoi server ngay bay gio.</b>";
    exit;
}

$email = 'admin@qlbh2.local';
$password = bin2hex(random_bytes(9)); // mat khau ngau nhien, chi hien 1 lan duy nhat o day
$hash = password_hash($password, PASSWORD_DEFAULT);

$pdo = db();

$stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
$stmt->execute([$email]);
$existing = $stmt->fetch();

if ($existing) {
    $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
        ->execute([$hash, $existing['id']]);
    echo "Da cap nhat mat khau cho tai khoan $email.<br>";
} else {
    $pdo->prepare('INSERT INTO users (name, email, password_hash, role, branch_id) VALUES (?, ?, ?, ?, ?)')
        ->execute(['Quan tri vien', $email, $hash, 'ADMIN', 1]);
    echo "Da tao tai khoan $email.<br>";
}

// Khoa lai ngay - lan chay ke tiep (vo tinh hay co y) se khong lam gi ca, tranh bi loi dung
// de reset mat khau admin ve gia tri co the doan duoc.
file_put_contents($lockFile, date('c') . "\n");

echo "Dang nhap bang: <b>$email</b> / mat khau: <b>$password</b><br>";
echo "<b style='color:red'>Ghi lai mat khau nay ngay (se khong hien lai lan nao khac), doi mat khau sau khi dang nhap lan dau, va xoa han file seed.php khoi server.</b>";
