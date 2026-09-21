<?php
// IPN (Instant Payment Notification) - VNPay goi TRUC TIEP tu server cua ho sang day, khong
// qua trinh duyet khach hang. Day moi la nguon xac nhan dang tin cay nhat: neu khach thanh toan
// xong roi dong trinh duyet/mat mang truoc khi bi redirect ve vnpay_return.php thi don hang van
// duoc ghi nhan va tenant van duoc nang cap - khong bi "mat tien ma khong duoc dung".
//
// Khai bao URL nay trong phan cau hinh merchant cua VNPay: https://app.kt-soft.vn/vnpay_ipn.php
// VNPay yeu cau tra ve JSON {"RspCode":"...","Message":"..."} chu khong phai HTML.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inc_functions.php';
require_once __DIR__ . '/inc_mail.php';
require_once __DIR__ . '/inc_vnpay.php';

header('Content-Type: application/json; charset=utf-8');

function ipnRespond(string $code, string $message): void
{
    echo json_encode(['RspCode' => $code, 'Message' => $message]);
    exit;
}

if (!vnpayConfigured() || !vnpayVerifySignature($_GET)) {
    ipnRespond('97', 'Invalid signature');
}

$orderCode = $_GET['vnp_TxnRef'] ?? '';
$responseCode = $_GET['vnp_ResponseCode'] ?? '';
$amountFromVnpay = (int) ($_GET['vnp_Amount'] ?? 0);

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM subscription_payments WHERE order_code = ?');
$stmt->execute([$orderCode]);
$payment = $stmt->fetch();

if (!$payment) {
    ipnRespond('01', 'Order not found');
}

// VNPay gui so tien nhan 100 - doi chieu lai voi so tien minh da tao don, tranh truong hop so
// tien bi sua doi tren duong truyen (du da co chu ky, van nen kiem tra doi chung theo dung
// khuyen nghi cua VNPay).
if ($amountFromVnpay !== ((int) $payment['amount']) * 100) {
    ipnRespond('04', 'Invalid amount');
}

if ($payment['status'] !== 'PENDING') {
    // Da xu ly roi - VNPay co the goi IPN nhieu lan, phai tra ve "da xac nhan" chu khong duoc
    // cong don thoi han them lan nua.
    ipnRespond('02', 'Order already confirmed');
}

if ($responseCode !== '00') {
    $pdo->prepare("UPDATE subscription_payments SET status = 'FAILED' WHERE id = ?")->execute([$payment['id']]);
    ipnRespond('00', 'Confirm Success');
}

$pdo->prepare("UPDATE subscription_payments SET status = 'SUCCESS', vnp_transaction_no = ?, paid_at = NOW() WHERE id = ?")
    ->execute([$_GET['vnp_TransactionNo'] ?? null, $payment['id']]);

$pdo->prepare(
    "UPDATE tenants SET plan = 'PAID',
        paid_until = DATE_ADD(GREATEST(COALESCE(paid_until, NOW()), NOW()), INTERVAL ? MONTH),
        trial_ends_at = NULL
     WHERE id = ?"
)->execute([(int) $payment['months'], (int) $payment['tenant_id']]);

logActivity('PAYMENT_SUCCESS_IPN', 'order=' . $orderCode . ' amount=' . $payment['amount']);

$tenantStmt = $pdo->prepare('SELECT name, owner_email FROM tenants WHERE id = ?');
$tenantStmt->execute([(int) $payment['tenant_id']]);
$tenant = $tenantStmt->fetch();

$subject = 'QLBH-CLOUD - Thanh toan thanh cong';
$body = "Da nhan thanh toan " . number_format((float) $payment['amount'], 0, ',', '.') . "d cho {$tenant['name']}.\n"
    . "Goi dich vu da duoc kich hoat them {$payment['months']} thang.\n";
if (!empty($tenant['owner_email'])) {
    sendMail($tenant['owner_email'], $subject, $body);
}
sendMail('hoangnamcnc@gmail.com', $subject, $body);

ipnRespond('00', 'Confirm Success');
