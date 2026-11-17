<?php
/** Them cot/bang cho ke hang, PLU, lich kiem kho, vat tu tieu hao, qua tang. Idempotent. Tai len tam thanh _chk_mig.php roi xoa. */
require_once __DIR__ . '/inc_auth.php';
if ((string) ($_GET['k'] ?? '') !== '__KEY__') {
    http_response_code(404);
    exit;
}
$pdo = db();
foreach ([
    <<<'SQL'
ALTER TABLE products ADD COLUMN plu VARCHAR(10) NULL
SQL,
    <<<'SQL'
ALTER TABLE products ADD COLUMN high_loss TINYINT NOT NULL DEFAULT 0
SQL,
    <<<'SQL'
ALTER TABLE inventory ADD COLUMN shelf_min DECIMAL(12,3) NOT NULL DEFAULT 0
SQL,
    <<<'SQL'
ALTER TABLE inventory ADD COLUMN shelf_qty DECIMAL(12,3) NOT NULL DEFAULT 0
SQL,
    <<<'SQL'
CREATE TABLE IF NOT EXISTS stock_take_schedules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  category_id INT NOT NULL,
  every_days INT NOT NULL DEFAULT 7,
  last_done DATE NULL,
  UNIQUE KEY uq_take_schedule (tenant_id, category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,
    <<<'SQL'
CREATE TABLE IF NOT EXISTS auto_deduct_rules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  kind ENUM('CONSUMABLE','GIFT') NOT NULL,
  product_id INT NOT NULL,
  qty DECIMAL(12,3) NOT NULL DEFAULT 1,
  min_total DECIMAL(14,2) NOT NULL DEFAULT 0,
  is_active TINYINT NOT NULL DEFAULT 1,
  KEY idx_auto_rule (tenant_id, kind, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,
    <<<'SQL'
CREATE TABLE IF NOT EXISTS order_auto_deductions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  branch_id INT NOT NULL,
  kind ENUM('CONSUMABLE','GIFT') NOT NULL,
  product_id INT NOT NULL,
  qty DECIMAL(12,3) NOT NULL,
  cost_price DECIMAL(14,2) NOT NULL DEFAULT 0,
  restored TINYINT NOT NULL DEFAULT 0,
  KEY idx_auto_ded_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,
] as $q) {
    try {
        $pdo->exec($q);
        echo 'ok: ' . substr(trim($q), 0, 60) . "\n";
    } catch (Throwable $e) {
        echo 'skip: ' . $e->getMessage() . "\n";
    }
}
