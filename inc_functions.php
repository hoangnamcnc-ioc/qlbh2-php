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
        return (int) $_SESSION['pos_branch_id'];
    }
    return (int) ($user['branch_id'] ?? 0);
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
