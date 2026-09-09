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

function requireLogin(): array
{
    $user = currentUser();
    if (!$user) {
        redirect('login.php');
    }
    return $user;
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
