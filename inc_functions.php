<?php
function money($amount): string
{
    return number_format((float) $amount, 0, ',', '.');
}

function e(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

function backupDir(): string
{
    $dir = __DIR__ . '/backups';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    if (!file_exists($dir . '/.htaccess')) {
        file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
    }
    return $dir;
}

/**
 * Sao lưu 1-click bằng PHP thuần (dump SQL + nén gzip) vì hosting chia sẻ không có SSH/shell
 * để dùng mysqldump thật. Dump từng bảng theo lô 500 dòng để tránh tràn bộ nhớ với bảng lớn.
 * Giữ lại tối đa 20 bản sao lưu gần nhất, tự xóa bản cũ hơn.
 */
function createBackup(): string
{
    $pdo = db();
    $dir = backupDir();
    $filename = 'backup_' . date('Ymd_His') . '.sql.gz';
    $gz = gzopen($dir . '/' . $filename, 'wb9');

    gzwrite($gz, "-- QLBH-CLOUD backup " . date('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        $create = $pdo->query("SHOW CREATE TABLE `$table`")->fetch();
        gzwrite($gz, "DROP TABLE IF EXISTS `$table`;\n" . $create['Create Table'] . ";\n\n");

        $count = (int) $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
        $chunk = 500;
        for ($offset = 0; $offset < $count; $offset += $chunk) {
            $rows = $pdo->query("SELECT * FROM `$table` LIMIT $chunk OFFSET $offset")->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) {
                break;
            }
            $colList = '`' . implode('`,`', array_keys($rows[0])) . '`';
            $valueGroups = [];
            foreach ($rows as $row) {
                $vals = array_map(fn ($v) => $v === null ? 'NULL' : $pdo->quote($v), $row);
                $valueGroups[] = '(' . implode(',', $vals) . ')';
            }
            gzwrite($gz, "INSERT INTO `$table` ($colList) VALUES " . implode(',', $valueGroups) . ";\n");
        }
        gzwrite($gz, "\n");
    }

    gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
    gzclose($gz);

    $files = glob($dir . '/backup_*.sql.gz');
    usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));
    foreach (array_slice($files, 20) as $old) {
        unlink($old);
    }

    return $filename;
}

function listBackups(): array
{
    $files = glob(backupDir() . '/backup_*.sql.gz');
    usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));
    return array_map(fn ($f) => ['name' => basename($f), 'size' => filesize($f), 'time' => filemtime($f)], $files);
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

/**
 * Đọc 1 giá trị số lượng từ POST — cho phép số lẻ (vd 0.35 kg hàng cân) thay vì chỉ số nguyên,
 * làm tròn 3 chữ số thập phân (đủ chính xác tới gram). Dùng cho mọi cột quantity đã đổi sang
 * DECIMAL(12,3): order_items, inventory, stock_receipt_items, stock_transfer_items...
 */
function postQty(string $key, float $default = 0): float
{
    $v = $_POST[$key] ?? null;
    if ($v === null || $v === '') return $default;
    $n = round((float) $v, 3);
    return $n >= 0 ? $n : $default;
}

/** Hiển thị số lượng đẹp: bỏ số 0 thừa ở cuối (1 thay vì 1.000, 0.5 thay vì 0.500). */
function fmtQty($value): string
{
    $n = round((float) $value, 3);
    if ($n == (int) $n) return (string) (int) $n;
    return rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.');
}

/** Chi nhánh đang bán hàng trong phiên POS hiện tại — mặc định là chi nhánh gán cho tài khoản,
 * nhưng ADMIN/MANAGER có thể tạm đổi sang chi nhánh khác qua nút "Đổi chi nhánh" trong POS. */
function effectiveBranchId(array $user): int
{
    if (in_array($user['role'], ['ADMIN', 'MANAGER'], true) && !empty($_SESSION['pos_branch_id'])) {
        // Lop bao ve thu hai: xac nhan lai chi nhanh dang chon trong phien thuc su thuoc tenant
        // hien tai. Can thiet ngay ca khi pos_switch_branch.php da kiem tra, vi phien dang nhap
        // cu (tao truoc khi va loi) co the con giu branch_id cua tenant khac. Chi kiem tra 1 lan
        // moi request nho cache tinh - chi phi them toi da 1 cau SELECT theo khoa chinh.
        static $verified = null;
        $sessionBranchId = (int) $_SESSION['pos_branch_id'];
        if ($verified === null || $verified['id'] !== $sessionBranchId) {
            $stmt = db()->prepare('SELECT id FROM branches WHERE id = ? AND tenant_id = ?');
            $stmt->execute([$sessionBranchId, (int) ($user['tenant_id'] ?? 0)]);
            $verified = ['id' => $sessionBranchId, 'ok' => (bool) $stmt->fetchColumn()];
        }
        if ($verified['ok']) {
            return $sessionBranchId;
        }
        unset($_SESSION['pos_branch_id']);
    }
    return (int) ($user['branch_id'] ?? 0);
}

/** Tenant (công ty/cửa hàng) của tài khoản đang đăng nhập — dùng để lọc mọi bảng "gốc"
 * (sản phẩm, khách hàng, NCC, danh mục...) sao cho các tenant khác nhau không thấy dữ liệu
 * của nhau. Bắt buộc phải có, vì mọi user đều thuộc đúng 1 tenant kể từ khi migrate. */
function currentTenantId(): int
{
    return (int) (currentUser()['tenant_id'] ?? 0);
}

/** Làm tối 1 màu hex đi $percent% (dùng cho trạng thái hover của màu chủ đạo tùy chỉnh). */
function darkenColor(string $hex, int $percent = 15): string
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) !== 6) return '#1d4ed8';
    [$r, $g, $b] = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    $factor = 1 - $percent / 100;
    $r = max(0, (int) round($r * $factor));
    $g = max(0, (int) round($g * $factor));
    $b = max(0, (int) round($b * $factor));
    return sprintf('#%02x%02x%02x', $r, $g, $b);
}

/** Ghi 1 dòng nhật ký hoạt động (Cấu hình > Nhật ký hoạt động). */
function logActivity(string $action, string $detail = ''): void
{
    $user = currentUser();
    db()->prepare('INSERT INTO activity_logs (user_id, user_name, action, detail, tenant_id) VALUES (?,?,?,?,?)')
        ->execute([$user['id'] ?? null, $user['name'] ?? 'Hệ thống', $action, $detail, $user['tenant_id'] ?? null]);
}

/** Đọc 1 giá trị cấu hình chung của cửa hàng (bảng store_settings, dạng key-value, theo tenant). */
function getSetting(string $key, string $default = ''): string
{
    static $cache = [];
    // Trang đặt hàng online công khai (shop.php) không có phiên đăng nhập nên không biết
    // tenant nào — tạm mặc định về tenant #1 (chủ sở hữu) cho tới khi trang shop hỗ trợ multi-
    // tenant thật (vd theo subdomain riêng từng cửa hàng).
    $tenantId = currentTenantId() ?: 1;
    if (!isset($cache[$tenantId])) {
        $cache[$tenantId] = [];
        $stmt = db()->prepare('SELECT setting_key, setting_value FROM store_settings WHERE tenant_id = ?');
        $stmt->execute([$tenantId]);
        foreach ($stmt->fetchAll() as $row) {
            $cache[$tenantId][$row['setting_key']] = $row['setting_value'];
        }
    }
    return $cache[$tenantId][$key] ?? $default;
}

/** Ghi 1 giá trị cấu hình cho tenant hiện tại (bảng store_settings, PRIMARY KEY (tenant_id, setting_key)). */
function setSetting(string $key, string $value): void
{
    db()->prepare(
        'INSERT INTO store_settings (tenant_id, setting_key, setting_value) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    )->execute([currentTenantId(), $key, $value]);
}

/** Tự động tạo phiếu bảo hành cho các dòng sản phẩm có bật has_warranty trong 1 đơn hàng đã hoàn thành. */
function createWarrantyCardsForOrder(int $orderId, ?int $customerId, int $createdById): void
{
    $pdo = db();
    $items = $pdo->prepare(
        'SELECT oi.id AS order_item_id, oi.product_id, p.has_warranty, p.warranty_policy_id
         FROM order_items oi JOIN products p ON p.id = oi.product_id
         WHERE oi.order_id = ? AND p.has_warranty = 1'
    );
    $items->execute([$orderId]);

    $warrantyStmt = $pdo->prepare(
        'INSERT INTO warranty_cards (code, order_item_id, product_id, customer_id, policy_id, start_date, end_date, created_by_id) VALUES (?,?,?,?,?,?,?,?)'
    );
    foreach ($items->fetchAll() as $it) {
        $duration = 12;
        if ($it['warranty_policy_id']) {
            $durStmt = $pdo->prepare('SELECT duration_months FROM warranty_policies WHERE id = ?');
            $durStmt->execute([$it['warranty_policy_id']]);
            $duration = (int) ($durStmt->fetchColumn() ?: 12);
        }
        $wCode = 'WR' . strtoupper(base_convert((string) (microtime(true) * 1000 + $it['order_item_id']), 10, 36));
        $wStart = date('Y-m-d');
        $wEnd = date('Y-m-d', strtotime("+$duration months"));
        $warrantyStmt->execute([$wCode, $it['order_item_id'], $it['product_id'], $customerId, $it['warranty_policy_id'], $wStart, $wEnd, $createdById]);
    }
}

/** Tự động ghi 1 phiếu thu/chi vào Sổ quỹ khi có dòng tiền thật phát sinh (bán hàng, thu nợ, trả NCC...). */
function recordCashbookEntry(
    int $branchId,
    string $type,
    float $amount,
    string $reason,
    string $paymentMethod,
    int $createdById,
    ?int $orderId = null,
    ?int $receiptId = null
): void {
    if ($amount <= 0 || !$branchId) {
        return;
    }
    $prefix = $type === 'RECEIPT' ? 'PT' : 'PC';
    $code = genCode($prefix);
    db()->prepare(
        'INSERT INTO cashbook_entries (code, branch_id, type, amount, reason, payment_method, created_by_id, order_id, receipt_id, auto_generated) VALUES (?,?,?,?,?,?,?,?,?,1)'
    )->execute([$code, $branchId, $type, $amount, $reason, $paymentMethod, $createdById, $orderId, $receiptId]);
}

const RATE_LIMIT_MAX = 5;
const RATE_LIMIT_LOCK_SECONDS = 15 * 60;

function clientIp(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

/**
 * Số giây còn lại bị khóa cho 1 hành động (login/forgot_password) theo IP hiện tại — 0 nếu
 * không bị khóa. Lưu ở DB (không phải session) để không thể bypass bằng cách xóa cookie.
 */
function rateLimitSecondsLeft(string $action): int
{
    $stmt = db()->prepare('SELECT locked_until FROM login_attempts WHERE ip_addr = ? AND action = ?');
    $stmt->execute([clientIp(), $action]);
    $lockedUntil = $stmt->fetchColumn();
    if (!$lockedUntil || strtotime($lockedUntil) <= time()) {
        return 0;
    }
    return strtotime($lockedUntil) - time();
}

/** Ghi 1 lần thất bại cho hành động theo IP hiện tại, tự khóa RATE_LIMIT_LOCK_SECONDS khi đạt RATE_LIMIT_MAX lần. */
function rateLimitRecordFailure(string $action): void
{
    $ip = clientIp();
    $stmt = db()->prepare('SELECT attempt_count FROM login_attempts WHERE ip_addr = ? AND action = ?');
    $stmt->execute([$ip, $action]);
    $count = (int) $stmt->fetchColumn() + 1;

    if ($count >= RATE_LIMIT_MAX) {
        $lockedUntil = date('Y-m-d H:i:s', time() + RATE_LIMIT_LOCK_SECONDS);
        db()->prepare(
            'INSERT INTO login_attempts (ip_addr, action, attempt_count, locked_until) VALUES (?,?,0,?)
             ON DUPLICATE KEY UPDATE attempt_count = 0, locked_until = VALUES(locked_until)'
        )->execute([$ip, $action, $lockedUntil]);
    } else {
        db()->prepare(
            'INSERT INTO login_attempts (ip_addr, action, attempt_count, locked_until) VALUES (?,?,?,NULL)
             ON DUPLICATE KEY UPDATE attempt_count = VALUES(attempt_count), locked_until = NULL'
        )->execute([$ip, $action, $count]);
    }
}

/** Xóa bộ đếm thất bại khi thành công (đăng nhập đúng...). */
function rateLimitReset(string $action): void
{
    db()->prepare('DELETE FROM login_attempts WHERE ip_addr = ? AND action = ?')->execute([clientIp(), $action]);
}
