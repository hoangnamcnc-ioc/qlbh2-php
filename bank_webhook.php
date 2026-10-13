<?php
/**
 * Nhan thong bao BIEN DONG SO DU tu SePay / Casso / dich vu tuong tu. KHONG dang nhap - xac thuc bang khoa bi mat rieng
 * cua tung cua hang (Cau hinh > Doi soat chuyen khoan):
 *   - header  Authorization: Apikey <KHOA>     (SePay)
 *   - header  secure-token: <KHOA>             (Casso)
 *   - hoac tham so ?key=<KHOA>
 * Moi giao dich chi xu ly 1 lan (chong trung theo ma giao dich), tra ve JSON 200.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inc_functions.php';
require_once __DIR__ . '/inc_bank.php';

header('Content-Type: application/json; charset=utf-8');

function webhookTraLoi(int $http, array $body): void
{
    http_response_code($http);
    echo json_encode($body + ['success' => $http === 200, 'error' => $http === 200 ? 0 : 1], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    webhookTraLoi(405, ['message' => 'Chỉ chấp nhận POST']);
}

$key = '';
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if (preg_match('/^(?:Apikey|Bearer)\s+(\S+)/i', (string) $auth, $m)) {
    $key = $m[1];
} elseif (!empty($_SERVER['HTTP_SECURE_TOKEN'])) {
    $key = (string) $_SERVER['HTTP_SECURE_TOKEN'];
} elseif (!empty($_GET['key'])) {
    $key = (string) $_GET['key'];
}
if (!preg_match('/^[a-f0-9]{40}$/', $key)) {
    webhookTraLoi(401, ['message' => 'Thiếu hoặc sai khóa xác thực']);
}

$pdo = db();
$st = $pdo->prepare("SELECT tenant_id FROM store_settings WHERE setting_key = 'bank_webhook_key' AND setting_value = ? LIMIT 1");
$st->execute([$key]);
$tenantId = (int) $st->fetchColumn();
if (!$tenantId) {
    webhookTraLoi(401, ['message' => 'Khóa xác thực không đúng']);
}
$t = $pdo->prepare('SELECT is_active FROM tenants WHERE id = ?');
$t->execute([$tenantId]);
if (!$t->fetchColumn()) {
    webhookTraLoi(403, ['message' => 'Cửa hàng không hoạt động']);
}

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) {
    $body = $_POST; // mot so dich vu gui form
}
$txs = bankParseWebhook($body);
$results = [];
foreach (array_slice($txs, 0, 50) as $tx) {
    try {
        $results[] = bankXuLyGiaoDich($pdo, $tenantId, $tx) + ['id' => $tx['id']];
    } catch (Throwable $e) {
        error_log('bank_webhook: ' . $e->getMessage());
        webhookTraLoi(500, ['message' => 'Lỗi xử lý giao dịch, vui lòng gửi lại']);
    }
}
webhookTraLoi(200, ['processed' => count($results), 'results' => $results]);
