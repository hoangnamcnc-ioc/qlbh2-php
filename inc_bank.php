<?php
/**
 * Tu doi soat chuyen khoan ngan hang.
 *
 * Nguon du lieu: thong bao BIEN DONG SO DU tu dich vu trung gian (SePay, Casso, ...) goi ve bank_webhook.php moi khi tai khoan
 * cua cua hang nhan tien. Moi giao dich duoc luu vao bang bank_transactions (chong trung theo ma giao dich cua nha cung cap),
 * roi doi chieu NOI DUNG chuyen khoan voi MA DON (khach quet QR sau khi dat hang) hoac MA THANH TOAN POS (TTxxxxxx, hien tren
 * man hinh POS khi thu ngan cho khach quet QR).
 *
 * Trang thai: MATCHED (da ghi thu vao don), POS_REF (cho don POS nhan), UNMATCHED (chua khop - quan ly gan tay), IGNORED (bo qua).
 */

/** Chuan hoa de so khop: bo ky tu khong phai chu/so, viet hoa (ngan hang thuong xoa dau gach/khoang trang trong noi dung). */
function bankChuanHoa(string $s): string
{
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $s));
}

/** Khoa bi mat cua webhook cho cua hang (tu tao lan dau). */
function bankWebhookKey(int $tenantId, bool $regenerate = false): string
{
    $key = getSettingForTenant($tenantId, 'bank_webhook_key', '');
    if ($key === '' || $regenerate) {
        $key = bin2hex(random_bytes(20));
        db()->prepare('INSERT INTO store_settings (tenant_id, setting_key, setting_value) VALUES (?,?,?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
            ->execute([$tenantId, 'bank_webhook_key', $key]);
    }
    return $key;
}

/** Tai khoan he thong de ghi so khi khong co nguoi dung dang dang nhap (webhook): quan tri dau tien cua cua hang. */
function bankSystemUserId(int $tenantId): int
{
    $st = db()->prepare("SELECT id FROM users WHERE tenant_id = ? AND is_active = 1 ORDER BY (role = 'ADMIN') DESC, id LIMIT 1");
    $st->execute([$tenantId]);
    return (int) $st->fetchColumn();
}

/**
 * Ghi nhan thu tien chuyen khoan vao 1 don hang (dung cung quy tac voi order_pay.php): cap nhat da thanh toan, payments,
 * so quy, cong no khach neu don co ghi no. Tra ve so tien thuc te da ghi (<= con thieu cua don). Goi trong giao dich cua nguoi goi.
 */
function bankGhiThuDon(PDO $pdo, array $order, float $amount, int $userId, string $reason): float
{
    $orderId = (int) $order['id'];
    $remaining = max(0.0, (float) $order['total_amount'] - (float) $order['paid_amount']);
    $apply = min($amount, $remaining);
    if ($apply <= 0) {
        return 0.0;
    }
    $newPaid = (float) $order['paid_amount'] + $apply;
    $newStatus = $newPaid >= (float) $order['total_amount'] ? 'PAID' : 'PARTIAL';
    $pdo->prepare('UPDATE orders SET paid_amount = ?, payment_status = ? WHERE id = ?')->execute([$newPaid, $newStatus, $orderId]);
    $pdo->prepare('INSERT INTO payments (order_id, method, amount) VALUES (?,?,?)')->execute([$orderId, 'BANK_TRANSFER', $apply]);
    $debtReduce = $order['customer_id'] ? min($apply, congNoConLaiCuaDon($pdo, $orderId)) : 0.0;
    if ($debtReduce > 0) {
        $pdo->prepare('UPDATE customers SET debt = GREATEST(0, debt - ?) WHERE id = ?')->execute([$debtReduce, $order['customer_id']]);
        $pdo->prepare('INSERT INTO customer_debt_entries (customer_id, order_id, amount, note, created_by_id) VALUES (?,?,?,?,?)')
            ->execute([$order['customer_id'], $orderId, -$debtReduce, 'Thu nợ đơn hàng (chuyển khoản)', $userId]);
    }
    recordCashbookEntry((int) $order['branch_id'], 'RECEIPT', $apply, $reason, 'BANK_TRANSFER', $userId, $orderId);
    return $apply;
}

/** Tim don chua thanh toan du cua cua hang co ma don xuat hien trong noi dung chuyen khoan. */
function bankTimDonTheoNoiDung(PDO $pdo, int $tenantId, string $content): ?array
{
    $norm = bankChuanHoa($content);
    if ($norm === '') {
        return null;
    }
    $st = $pdo->prepare(
        "SELECT o.* FROM orders o JOIN branches b ON b.id = o.branch_id
         WHERE b.tenant_id = ? AND o.status <> 'CANCELLED' AND o.total_amount > o.paid_amount AND o.created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY)
         ORDER BY o.id DESC LIMIT 500"
    );
    $st->execute([$tenantId]);
    $best = null; $bestLen = 0;
    foreach ($st->fetchAll() as $o) {
        $code = bankChuanHoa((string) $o['code']);
        if ($code !== '' && strlen($code) >= 6 && str_contains($norm, $code) && strlen($code) > $bestLen) {
            $best = $o; $bestLen = strlen($code);
        }
    }
    return $best;
}

/** Ma thanh toan POS (TT + 6 ky tu) trong noi dung chuyen khoan, hoac null. */
function bankTimMaPos(string $content): ?string
{
    return preg_match('/TT[A-HJ-NP-Z2-9]{6}/', bankChuanHoa($content), $m) ? $m[0] : null;
}

/**
 * Xu ly 1 giao dich TIEN VAO. $tx: ['id' => ma giao dich nha cung cap, 'amount', 'content', 'time', 'account', 'provider'].
 * Tra ve ['status' => ..., 'duplicate' => bool, 'order_code' => ?]. Idempotent: cung (provider, id) chi xu ly 1 lan.
 */
function bankXuLyGiaoDich(PDO $pdo, int $tenantId, array $tx): array
{
    $provider = substr((string) ($tx['provider'] ?? 'generic'), 0, 30);
    $txId = substr((string) ($tx['id'] ?? ''), 0, 80);
    $amount = round((float) ($tx['amount'] ?? 0), 2);
    $content = substr((string) ($tx['content'] ?? ''), 0, 500);
    if ($txId === '' || $amount <= 0) {
        return ['status' => 'IGNORED', 'duplicate' => false, 'order_code' => null];
    }

    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare(
            'INSERT IGNORE INTO bank_transactions (tenant_id, provider, tx_id, amount, content, account_number, tx_time, status) VALUES (?,?,?,?,?,?,?, "UNMATCHED")'
        );
        $ins->execute([$tenantId, $provider, $txId, $amount, $content, substr((string) ($tx['account'] ?? ''), 0, 40),
            !empty($tx['time']) && strtotime((string) $tx['time']) ? date('Y-m-d H:i:s', strtotime((string) $tx['time'])) : date('Y-m-d H:i:s')]);
        if ($ins->rowCount() === 0) {
            $pdo->rollBack();
            return ['status' => 'DUPLICATE', 'duplicate' => true, 'order_code' => null];
        }
        $rowId = (int) $pdo->lastInsertId();

        $status = 'UNMATCHED'; $orderId = null; $orderCode = null; $applied = 0.0; $posRef = null;
        $order = bankTimDonTheoNoiDung($pdo, $tenantId, $content);
        if ($order) {
            $lock = $pdo->prepare('SELECT * FROM orders WHERE id = ? FOR UPDATE');
            $lock->execute([$order['id']]);
            $order = $lock->fetch();
            $userId = bankSystemUserId($tenantId);
            $applied = $userId ? bankGhiThuDon($pdo, $order, $amount, $userId, 'Thu chuyển khoản đơn hàng ' . $order['code'] . ' (tự đối soát)') : 0.0;
            if ($applied > 0) {
                $status = 'MATCHED'; $orderId = (int) $order['id']; $orderCode = $order['code'];
            }
        } elseif ($posRef = bankTimMaPos($content)) {
            $status = 'POS_REF';
        }
        $pdo->prepare('UPDATE bank_transactions SET status = ?, order_id = ?, applied_amount = ?, pos_ref = ? WHERE id = ?')
            ->execute([$status, $orderId, $applied, $posRef, $rowId]);
        $pdo->commit();
        return ['status' => $status, 'duplicate' => false, 'order_code' => $orderCode];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** Lay danh sach giao dich tien vao tu body webhook cua cac nha cung cap thuong gap (SePay, Casso, dang chung). */
function bankParseWebhook(array $body): array
{
    $out = [];
    // Casso: {"error":0,"data":[{id, tid, description, amount, when, bank_sub_acc_id,...}, ...]}
    if (isset($body['data']) && is_array($body['data']) && array_keys($body['data']) === range(0, count($body['data']) - 1)) {
        foreach ($body['data'] as $r) {
            if (!is_array($r)) continue;
            $out[] = ['provider' => 'casso', 'id' => (string) ($r['id'] ?? $r['tid'] ?? ''), 'amount' => (float) ($r['amount'] ?? 0),
                'content' => (string) ($r['description'] ?? ''), 'time' => $r['when'] ?? null, 'account' => (string) ($r['subAccId'] ?? $r['bank_sub_acc_id'] ?? '')];
        }
        return array_values(array_filter($out, static fn($t) => $t['amount'] > 0));
    }
    // SePay: {id, gateway, transactionDate, accountNumber, content, transferType:"in", transferAmount, referenceCode, description}
    if (isset($body['transferAmount']) || isset($body['transferType'])) {
        if (strtolower((string) ($body['transferType'] ?? 'in')) !== 'in') {
            return [];
        }
        return [['provider' => 'sepay', 'id' => (string) ($body['id'] ?? $body['referenceCode'] ?? ''), 'amount' => (float) ($body['transferAmount'] ?? 0),
            'content' => trim((string) ($body['content'] ?? '') . ' ' . (string) ($body['description'] ?? '')), 'time' => $body['transactionDate'] ?? null,
            'account' => (string) ($body['accountNumber'] ?? '')]];
    }
    // Dang chung: {id, amount, content, time}
    if (isset($body['amount'])) {
        return [['provider' => 'generic', 'id' => (string) ($body['id'] ?? ''), 'amount' => (float) $body['amount'], 'content' => (string) ($body['content'] ?? $body['description'] ?? ''),
            'time' => $body['time'] ?? null, 'account' => (string) ($body['account'] ?? '')]];
    }
    return [];
}
