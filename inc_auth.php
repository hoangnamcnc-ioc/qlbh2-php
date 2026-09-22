<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inc_functions.php';
require_once __DIR__ . '/inc_mail.php';

if (session_status() === PHP_SESSION_NONE) {
    // Dat ten cookie session rieng theo tung deployment (dua tren AUTH_SALT) thay vi dung
    // ten mac dinh PHPSESSID - tranh xung dot/de doan ten cookie khi hosting chia se co
    // nhieu app PHP khac chay chung 1 domain/subdomain.
    session_name('qlbh2_' . substr(hash('sha256', AUTH_SALT), 0, 12));
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        // Bat co 'secure' khi truy cap qua HTTPS (deploy that len app.kt-soft.vn se luon la
        // HTTPS) - tren local/HTTP (vd dang test) van hoat dong binh thuong vi co dieu kien.
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
    ]);
    session_start();
}

function currentUser(): ?array
{
    return $_SESSION['user'] ?? null;
}

/**
 * Đồng bộ lại role/chi nhánh/trạng thái hoạt động của user trong session với dữ liệu mới nhất
 * trong DB — tránh trường hợp tài khoản đã bị khóa/đổi quyền nhưng phiên đăng nhập cũ (trình
 * duyệt vẫn đang mở) tiếp tục hoạt động với quyền cũ cho đến khi tự đăng xuất. Chỉ chạy 1 lần
 * mỗi request nhờ cờ tĩnh, nên chi phí thêm là 1 câu SELECT đơn giản theo khóa chính.
 */
function refreshUserSession(): void
{
    static $checked = false;
    if ($checked || empty($_SESSION['user'])) {
        return;
    }
    $checked = true;

    $stmt = db()->prepare('SELECT role, branch_id, tenant_id, is_active, name FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user']['id']]);
    $fresh = $stmt->fetch();

    if (!$fresh || !$fresh['is_active']) {
        $_SESSION = [];
        session_destroy();
        redirect('login.php?locked=1');
    }

    $_SESSION['user']['role'] = $fresh['role'];
    $_SESSION['user']['branch_id'] = $fresh['branch_id'];
    $_SESSION['user']['tenant_id'] = $fresh['tenant_id'];
    $_SESSION['user']['name'] = $fresh['name'];
}

/**
 * Chặn truy cập nếu tenant (công ty) của user đang dùng thử đã hết hạn — không xóa dữ liệu,
 * chỉ khóa vào app cho tới khi nâng cấp lên PAID. Chỉ kiểm tra 1 lần/request giống
 * refreshUserSession(), và luôn cho qua trang trial_expired.php/logout.php để tránh vòng lặp
 * chuyển hướng.
 */
function checkTrialExpiry(): void
{
    static $checked = false;
    if ($checked || empty($_SESSION['user'])) {
        return;
    }
    $checked = true;

    $currentFile = basename($_SERVER['SCRIPT_NAME']);
    if (in_array($currentFile, ['trial_expired.php', 'logout.php', 'gia_han.php'], true)) {
        return;
    }

    $stmt = db()->prepare('SELECT id, name, plan, trial_ends_at, trial_reminder_sent_at, paid_until, owner_email, is_active, last_weekly_report_at, created_at FROM tenants WHERE id = ?');
    $stmt->execute([$_SESSION['user']['tenant_id']]);
    $tenant = $stmt->fetch();

    if (!$tenant || !$tenant['is_active']) {
        $_SESSION = [];
        session_destroy();
        redirect('login.php?locked=1');
    }

    if ($tenant['plan'] === 'TRIAL' && $tenant['trial_ends_at'] !== null && strtotime($tenant['trial_ends_at']) < time()) {
        redirect('trial_expired.php');
    }

    // paid_until = NULL nghia la khong gioi han (nang cap thu cong truoc khi co tinh nang thanh
    // toan online, hoac tenant #1 - chu so huu) - chi ap dung het han cho tenant da tung thanh
    // toan qua VNPay (co gia tri paid_until that).
    if ($tenant['plan'] === 'PAID' && $tenant['paid_until'] !== null && strtotime($tenant['paid_until']) < time()) {
        redirect('trial_expired.php');
    }

    maybeSendTrialReminder($tenant);
    maybeSendWeeklyReport($tenant);
}

/**
 * Gui email nhac con 3 ngay het han dung thu - chi gui 1 lan/tenant (danh dau qua
 * trial_reminder_sent_at). Khong dung cron (hosting khong co SSH/cron de tao job rieng) - kiem
 * tra ngay tren request cua chinh nguoi dung do khi ho dang nhap/dung app.
 *
 * Danh doi cua cach nay: khach KHONG mo app trong 3 ngay cuoi thi khong nhan duoc email nhac -
 * ma do lai dung la nhom de mat nhat. Dang ky hien cap 12 THANG dung thu (dang-ky.php), nen
 * khoang cach giua luc dang ky va luc nhac la rat dai. Neu ve sau thay nhieu khach im lang roi
 * het han ma khong ai nhac duoc, nen chuyen viec nhac sang cron (backup_cron.php da co san mau
 * goi qua HTTP kem key).
 */
function maybeSendTrialReminder(array $tenant): void
{
    if ($tenant['plan'] !== 'TRIAL' || $tenant['trial_ends_at'] === null || empty($tenant['owner_email'])) {
        return;
    }
    if ($tenant['trial_reminder_sent_at'] !== null) {
        return;
    }
    $secondsLeft = strtotime($tenant['trial_ends_at']) - time();
    if ($secondsLeft <= 0 || $secondsLeft > 3 * 86400) {
        return;
    }

    $daysLeft = max(1, (int) ceil($secondsLeft / 86400));
    $subject = "QLBH-CLOUD - Con {$daysLeft} ngay dung thu";
    $body = "Xin chao,\n\n"
        . "Goi dung thu QLBH-CLOUD cua ban ({$tenant['name']}) se het han sau {$daysLeft} ngay nua.\n"
        . "De tiep tuc su dung khong gian doan, vui long lien he nang cap len goi tra phi:\n\n"
        . "DT/Zalo: 0945289666\nEmail: hoangnamcnc@gmail.com\n\n"
        . "Sau khi het han dung thu, du lieu cua ban van duoc giu nguyen - chi tam khoa truy cap"
        . " cho toi khi nang cap.\n";
    sendMail($tenant['owner_email'], $subject, $body);

    db()->prepare('UPDATE tenants SET trial_reminder_sent_at = NOW() WHERE id = ?')->execute([$tenant['id']]);
}

/**
 * Gui email tom tat doanh thu/don hang 7 ngay qua cho chu cua hang, moi 7 ngay/lan - cung khong
 * dung cron (giong maybeSendTrialReminder), kiem tra tren chinh request cua nguoi dung do. Muc
 * tieu: tao thoi quen quay lai dung phan mem, khong chi de "quen" sau khi dang ky.
 */
function maybeSendWeeklyReport(array $tenant): void
{
    if (empty($tenant['owner_email'])) {
        return;
    }
    $lastSent = $tenant['last_weekly_report_at'] ?? $tenant['created_at'];
    if ($lastSent === null || (time() - strtotime($lastSent)) < 7 * 86400) {
        return;
    }

    $pdo = db();
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(o.total_amount),0) AS revenue, COUNT(*) AS order_count
         FROM orders o JOIN branches b ON b.id = o.branch_id
         WHERE b.tenant_id = ? AND o.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND o.status != 'CANCELLED'"
    );
    $stmt->execute([$tenant['id']]);
    $stats = $stmt->fetch();

    // Khong lam phien chu cua hang neu tuan do khong co hoat dong gi - email rong khong co gia
    // tri, chi nen bao khi co so lieu thuc su de xem.
    if ((int) $stats['order_count'] > 0) {
        $subject = 'QLBH-CLOUD - Báo cáo tuần: ' . $tenant['name'];
        $body = "Tổng kết 7 ngày qua cho {$tenant['name']}:\n\n"
            . "Doanh thu: " . number_format((float) $stats['revenue'], 0, ',', '.') . "đ\n"
            . "Số đơn hàng: {$stats['order_count']}\n\n"
            . "Xem chi tiết tại: https://app.kt-soft.vn/reports.php\n";
        sendMail($tenant['owner_email'], $subject, $body);
    }

    $pdo->prepare('UPDATE tenants SET last_weekly_report_at = NOW() WHERE id = ?')->execute([$tenant['id']]);
}

function requireLogin(): array
{
    $user = currentUser();
    if (!$user) {
        redirect('login.php');
    }
    refreshUserSession();
    checkTrialExpiry();

    $currentFile = basename($_SERVER['SCRIPT_NAME']);
    if (!empty($_SESSION['locked']) && !in_array($currentFile, ['lock.php', 'logout.php'], true)) {
        redirect('lock.php');
    }

    return currentUser();
}

/** Chỉ cho phép các role được liệt kê. Chặn (403) nếu không đúng quyền. */
function requireRole(string ...$roles): array
{
    $user = requireLogin();
    if (!in_array($user['role'], $roles, true)) {
        http_response_code(403);
        require_once __DIR__ . '/inc_header.php';
        echo '<div class="alert alert-error">Bạn không có quyền truy cập trang này.</div>';
        require_once __DIR__ . '/inc_footer.php';
        exit;
    }
    return $user;
}

function hasRole(string ...$roles): bool
{
    $user = currentUser();
    return $user && in_array($user['role'], $roles, true);
}

/**
 * Trang quản trị hệ thống (danh sách toàn bộ tenant) — CỐ Ý phá vỡ quy tắc "mỗi tenant chỉ
 * thấy dữ liệu của mình" vì đây là công cụ vận hành nền tảng cho chủ sở hữu (KT-SOFT), không
 * phải nghiệp vụ của 1 cửa hàng. Giới hạn chặt: chỉ ADMIN của tenant #1 (tenant khởi tạo sẵn
 * khi migrate, xem fix_multitenant_migrate.php) mới qua được, không dùng role thường.
 */
function requireSuperAdmin(): array
{
    $user = requireLogin();
    if ($user['role'] !== 'ADMIN' || (int) $user['tenant_id'] !== 1) {
        http_response_code(403);
        require_once __DIR__ . '/inc_header.php';
        echo '<div class="alert alert-error">Bạn không có quyền truy cập trang này.</div>';
        require_once __DIR__ . '/inc_footer.php';
        exit;
    }
    return $user;
}

// Chong do mat khau: khoa tam 15 phut sau 5 lan sai lien tiep THEO IP, luu o bang login_attempts
// (khong phai session) - ke tan cong xoa cookie/mo tab an danh se duoc session moi tinh, vo hieu
// hoan toan khoa kieu session. Khoa theo IP (khong phai theo email) de chan ca kieu do quet nhieu
// email tu 1 nguon.
function loginLockedUntil(string $email): int
{
    $secondsLeft = rateLimitSecondsLeft('login');
    return $secondsLeft > 0 ? time() + $secondsLeft : 0;
}

function attemptLogin(string $email, string $password): ?array
{
    if (rateLimitSecondsLeft('login') > 0) {
        return null;
    }

    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? AND is_active = 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        rateLimitRecordFailure('login');
        return null;
    }

    rateLimitReset('login');
    unset($user['password_hash']);
    // Doi session ID moi khi dang nhap thanh cong - chan "session fixation" (ke tan cong dat
    // truoc 1 session ID roi du nan nhan dang nhap bang chinh ID do de chiem phien sau khi
    // dang nhap thanh cong).
    session_regenerate_id(true);
    $_SESSION['user'] = $user;
    require_once __DIR__ . '/inc_functions.php';
    logActivity('LOGIN', $user['email']);
    return $user;
}

function logout(): void
{
    $user = currentUser();
    if ($user) {
        require_once __DIR__ . '/inc_functions.php';
        logActivity('LOGOUT', $user['email'] ?? '');
    }
    $_SESSION = [];
    session_destroy();
}

/** Token CSRF cho form POST. */
function csrfToken(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function checkCsrf(): void
{
    $token = $_POST['csrf'] ?? '';
    // hash_equals('', '') tra ve true (2 chuoi rong bang nhau) - neu khong co phien nao
    // (chua tung goi csrfToken() de sinh $_SESSION['csrf']) va request cung khong gui csrf,
    // ca 2 ve deu la chuoi rong va se "khop" mot cach sai lech, vo hieu hoa hoan toan kiem
    // tra CSRF cho cac trang cong khai khong bat buoc dang nhap truoc. Phai bat buoc session
    // da co token that (khong rong) truoc khi so sanh.
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
        http_response_code(403);
        exit('Phiên làm việc không hợp lệ, vui lòng tải lại trang và thử lại.');
    }
}
