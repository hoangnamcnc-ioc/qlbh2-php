<?php
require_once __DIR__ . '/config.php';

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
    if (in_array($currentFile, ['trial_expired.php', 'logout.php'], true)) {
        return;
    }

    $stmt = db()->prepare('SELECT plan, trial_ends_at, is_active FROM tenants WHERE id = ?');
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

// Chong do mat khau: khoa tam 15 phut sau 5 lan sai lien tiep cho tung email. Luu trong
// session PHP (khong can bang DB rieng) - moi tien trinh PHP-FPM/session file la doc lap
// theo tung nguoi dung/trinh duyet nen du de chan bot do mat khau tu 1 nguon.
function loginLockedUntil(string $email): int
{
    return (int) ($_SESSION['login_lock'][$email]['until'] ?? 0);
}

function attemptLogin(string $email, string $password): ?array
{
    if (!isset($_SESSION['login_lock'][$email]) || !is_array($_SESSION['login_lock'][$email])) {
        $_SESSION['login_lock'][$email] = ['count' => 0, 'until' => 0];
    }
    if ($_SESSION['login_lock'][$email]['until'] > time()) {
        return null;
    }

    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? AND is_active = 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        $count = $_SESSION['login_lock'][$email]['count'] + 1;
        $_SESSION['login_lock'][$email]['count'] = $count;
        if ($count >= 5) {
            $_SESSION['login_lock'][$email]['until'] = time() + 15 * 60;
        }
        return null;
    }

    unset($_SESSION['login_lock'][$email]);
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
    if (!hash_equals($_SESSION['csrf'] ?? '', $token)) {
        http_response_code(403);
        exit('Phiên làm việc không hợp lệ, vui lòng tải lại trang và thử lại.');
    }
}
