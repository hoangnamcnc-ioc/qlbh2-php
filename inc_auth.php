<?php
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
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

    $stmt = db()->prepare('SELECT role, branch_id, is_active, name FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user']['id']]);
    $fresh = $stmt->fetch();

    if (!$fresh || !$fresh['is_active']) {
        $_SESSION = [];
        session_destroy();
        redirect('login.php?locked=1');
    }

    $_SESSION['user']['role'] = $fresh['role'];
    $_SESSION['user']['branch_id'] = $fresh['branch_id'];
    $_SESSION['user']['name'] = $fresh['name'];
}

function requireLogin(): array
{
    $user = currentUser();
    if (!$user) {
        redirect('login.php');
    }
    refreshUserSession();
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

function attemptLogin(string $email, string $password): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? AND is_active = 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return null;
    }

    unset($user['password_hash']);
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
