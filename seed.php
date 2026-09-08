<?php
// Chạy file này 1 LẦN DUY NHẤT (mở trên trình duyệt: yoursite.com/seed.php) để tạo/đổi mật khẩu
// tài khoản admin đầu tiên. Sau khi chạy xong, XÓA FILE NÀY khỏi server để đảm bảo an toàn.

require_once __DIR__ . '/config.php';

$email = 'admin@qlbh2.local';
$password = 'Admin@123';
$hash = password_hash($password, PASSWORD_DEFAULT);

$pdo = db();

$stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
$stmt->execute([$email]);
$existing = $stmt->fetch();

if ($existing) {
    $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
        ->execute([$hash, $existing['id']]);
    echo "Đã cập nhật mật khẩu cho tài khoản $email.<br>";
} else {
    $pdo->prepare('INSERT INTO users (name, email, password_hash, role, branch_id) VALUES (?, ?, ?, ?, ?)')
        ->execute(['Quản trị viên', $email, $hash, 'ADMIN', 1]);
    echo "Đã tạo tài khoản $email.<br>";
}

echo "Đăng nhập bằng: <b>$email</b> / mật khẩu: <b>$password</b><br>";
echo "<b style='color:red'>Hãy xóa file seed.php khỏi server ngay sau khi đọc thông báo này, và đổi mật khẩu sau khi đăng nhập lần đầu.</b>";
