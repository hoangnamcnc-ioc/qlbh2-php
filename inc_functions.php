<?php
function money($amount): string
{
    return number_format((float) $amount, 0, ',', '.');
}

function e(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

function redirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}

function genCode(string $prefix): string
{
    return $prefix . strtoupper(base_convert((string) (microtime(true) * 1000), 10, 36));
}

/** Lấy giá trị POST đã trim, hoặc chuỗi rỗng. */
function post(string $key): string
{
    return trim((string) ($_POST[$key] ?? ''));
}

function postFloat(string $key, float $default = 0): float
{
    $v = $_POST[$key] ?? null;
    if ($v === null || $v === '') return $default;
    $n = (float) $v;
    return $n >= 0 ? $n : $default;
}

function postInt(string $key, int $default = 0): int
{
    $v = $_POST[$key] ?? null;
    if ($v === null || $v === '') return $default;
    $n = (int) $v;
    return $n >= 0 ? $n : $default;
}

/** Chi nhánh đang bán hàng trong phiên POS hiện tại — mặc định là chi nhánh gán cho tài khoản,
 * nhưng ADMIN/MANAGER có thể tạm đổi sang chi nhánh khác qua nút "Đổi chi nhánh" trong POS. */
function effectiveBranchId(array $user): int
{
    if (in_array($user['role'], ['ADMIN', 'MANAGER'], true) && !empty($_SESSION['pos_branch_id'])) {
        return (int) $_SESSION['pos_branch_id'];
    }
    return (int) ($user['branch_id'] ?? 0);
}

/** Ghi 1 dòng nhật ký hoạt động (Cấu hình > Nhật ký hoạt động). */
function logActivity(string $action, string $detail = ''): void
{
    $user = currentUser();
    db()->prepare('INSERT INTO activity_logs (user_id, user_name, action, detail) VALUES (?,?,?,?)')
        ->execute([$user['id'] ?? null, $user['name'] ?? 'Hệ thống', $action, $detail]);
}

/** Đọc 1 giá trị cấu hình chung của cửa hàng (bảng store_settings, dạng key-value). */
function getSetting(string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        $stmt = db()->query('SELECT setting_key, setting_value FROM store_settings');
        foreach ($stmt->fetchAll() as $row) {
            $cache[$row['setting_key']] = $row['setting_value'];
        }
    }
    return $cache[$key] ?? $default;
}
