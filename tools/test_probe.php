<?php
/**
 * Cong cu DOC trang thai + DON DEP cho tools/regression_test.sh. Chi duoc tai len TAM THOI (ten
 * _chk_probe.php, khoa ngau nhien duoc thay vao __KEY__ luc tai len) va bi xoa ngay khi test xong.
 *   ?key=..&a=state&c=<customer_id>&o=<order_id>   -> JSON trang thai
 *   ?key=..&a=cleanup                              -> xoa moi tenant ten 'ZZTEST Reg'
 */
require_once __DIR__ . '/inc_auth.php';
header('Content-Type: application/json; charset=utf-8');
if (!hash_equals('__KEY__', (string) ($_GET['key'] ?? ''))) {
    http_response_code(403);
    exit('{}');
}
$pdo = db();

if (($_GET['a'] ?? '') === 'state') {
    $c = (int) ($_GET['c'] ?? 0);
    $o = (int) ($_GET['o'] ?? 0);
    $one = static function (string $sql, array $args = []) use ($pdo) {
        $st = $pdo->prepare($sql);
        $st->execute($args);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    };
    echo json_encode([
        'customer' => $one('SELECT debt, loyalty_points FROM customers WHERE id = ?', [$c]),
        'order' => $one('SELECT status, payment_status, paid_amount, total_amount FROM orders WHERE id = ?', [$o]),
        'cash_net' => (float) ($one("SELECT COALESCE(SUM(CASE WHEN type='RECEIPT' THEN amount ELSE -amount END),0) AS n FROM cashbook_entries WHERE order_id = ?", [$o])['n'] ?? 0),
        'debt_entries' => (float) ($one('SELECT COALESCE(SUM(amount),0) AS n FROM customer_debt_entries WHERE customer_id = ?', [$c])['n'] ?? 0),
    ]);
    exit;
}

if (in_array($_GET['a'] ?? '', ['expire', 'unexpire'], true)) {
    // Gia lap het han dung thu / gia han cho cac cua hang thu 'ZZTEST Reg'.
    $sql = $_GET['a'] === 'expire'
        ? "UPDATE tenants SET trial_ends_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE name = 'ZZTEST Reg' AND plan = 'TRIAL'"
        : "UPDATE tenants SET trial_ends_at = DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE name = 'ZZTEST Reg' AND plan = 'TRIAL'";
    echo json_encode(['updated' => $pdo->exec($sql)]);
    exit;
}

if (($_GET['a'] ?? '') === 'cleanup') {
    // Xoa bo dem gioi han dang ky cua CHINH IP dang chay test (moi IP chi duoc tao 5 cua hang/15 phut,
    // bo test tao nhieu cua hang thu nen se tu khoa minh).
    $pdo->prepare("DELETE FROM login_attempts WHERE action = 'signup' AND ip_addr = ?")->execute([clientIp()]);
    $ids = array_map('intval', $pdo->query("SELECT id FROM tenants WHERE name = 'ZZTEST Reg'")->fetchAll(PDO::FETCH_COLUMN));
    $tables = $pdo->prepare("SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'tenant_id' AND TABLE_NAME <> 'tenants'");
    $tables->execute();
    $tables = $tables->fetchAll(PDO::FETCH_COLUMN);
    $done = [];
    foreach ($ids as $tid) {
        $br = $pdo->prepare('SELECT id FROM branches WHERE tenant_id = ?');
        $br->execute([$tid]);
        $bl = $br->fetchAll(PDO::FETCH_COLUMN);
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $pdo->beginTransaction();
        try {
            if ($bl) {
                $in = implode(',', array_fill(0, count($bl), '?'));
                $ord = "SELECT id FROM orders WHERE branch_id IN ($in)";
                $items = "SELECT id FROM order_items WHERE order_id IN ($ord)";
                foreach ([
                    "DELETE FROM order_item_batches WHERE order_item_id IN ($items)",
                    "DELETE FROM warranty_cards WHERE order_item_id IN ($items)",
                    "DELETE FROM order_return_items WHERE return_id IN (SELECT id FROM order_returns WHERE order_id IN ($ord))",
                    "DELETE FROM order_returns WHERE order_id IN ($ord)",
                    "DELETE FROM customer_debt_entries WHERE order_id IN ($ord)",
                    "DELETE FROM order_status_history WHERE order_id IN ($ord)",
                    "DELETE FROM payments WHERE order_id IN ($ord)",
                    "DELETE FROM order_items WHERE order_id IN ($ord)",
                    "DELETE FROM cashbook_entries WHERE branch_id IN ($in)",
                    "DELETE FROM pos_shifts WHERE branch_id IN ($in)",
                    "DELETE FROM inventory WHERE branch_id IN ($in)",
                    "DELETE FROM product_batches WHERE branch_id IN ($in)",
                    "DELETE FROM stock_receipt_items WHERE receipt_id IN (SELECT id FROM stock_receipts WHERE branch_id IN ($in))",
                    "DELETE FROM stock_receipts WHERE branch_id IN ($in)",
                    "DELETE FROM orders WHERE branch_id IN ($in)",
                ] as $sql) {
                    $reps = (int) (substr_count($sql, '?') / count($bl));
                    $args = [];
                    for ($k = 0; $k < $reps; $k++) {
                        $args = array_merge($args, $bl);
                    }
                    $pdo->prepare($sql)->execute($args);
                }
            }
            $pdo->prepare('DELETE FROM product_variants WHERE product_id IN (SELECT id FROM products WHERE tenant_id = ?)')->execute([$tid]);
            foreach ($tables as $t) {
                $pdo->prepare("DELETE FROM `$t` WHERE tenant_id = ?")->execute([$tid]);
            }
            $pdo->prepare('DELETE FROM tenants WHERE id = ?')->execute([$tid]);
            $pdo->commit();
            $done[] = $tid;
        } catch (Throwable $e) {
            $pdo->rollBack();
            $done[] = "$tid: LOI " . $e->getMessage();
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }
    echo json_encode(['cleaned' => $done]);
}
