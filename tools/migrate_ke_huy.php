<?php
/** Bang ghi so luong da tru tren ke theo don (de hoan khi huy). Idempotent. Tai len tam thanh _chk_mig.php roi xoa. */
require_once __DIR__ . '/inc_auth.php';
if ((string) ($_GET['k'] ?? '') !== '__KEY__') {
    http_response_code(404);
    exit;
}
$pdo = db();
foreach ([
    <<<'SQL'
CREATE TABLE IF NOT EXISTS order_shelf_deductions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  branch_id INT NOT NULL,
  product_id INT NOT NULL,
  qty DECIMAL(12,3) NOT NULL,
  restored TINYINT NOT NULL DEFAULT 0,
  KEY idx_shelf_ded_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,
    <<<'SQL'
ALTER TABLE order_auto_deductions ADD COLUMN shelf_taken DECIMAL(12,3) NOT NULL DEFAULT 0
SQL,
] as $q) {
    try {
        $pdo->exec($q);
        echo 'ok: ' . substr(trim($q), 0, 60) . "\n";
    } catch (Throwable $e) {
        echo 'skip: ' . $e->getMessage() . "\n";
    }
}
