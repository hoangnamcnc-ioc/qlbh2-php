<?php
/** Tao bang bao gia. Idempotent. Tai len tam thanh _chk_mig.php roi xoa. */
require_once __DIR__ . '/inc_auth.php';
if ((string) ($_GET['k'] ?? '') !== '__KEY__') {
    http_response_code(404);
    exit;
}
$pdo = db();
foreach ([
    <<<'SQL'
CREATE TABLE IF NOT EXISTS quotes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  branch_id INT NOT NULL,
  code VARCHAR(30) NOT NULL,
  customer_id INT NULL,
  customer_name VARCHAR(255) NOT NULL,
  customer_phone VARCHAR(50) NULL,
  customer_address VARCHAR(500) NULL,
  valid_until DATE NOT NULL,
  discount DECIMAL(14,2) NOT NULL DEFAULT 0,
  total DECIMAL(14,2) NOT NULL DEFAULT 0,
  note TEXT NULL,
  status ENUM('DRAFT','SENT','ACCEPTED','REJECTED') NOT NULL DEFAULT 'DRAFT',
  created_by_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_quote_code (tenant_id, code),
  KEY idx_quote_tenant (tenant_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,
    <<<'SQL'
CREATE TABLE IF NOT EXISTS quote_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  quote_id INT NOT NULL,
  product_id INT NOT NULL,
  variant_id INT NULL,
  name VARCHAR(255) NOT NULL,
  quantity DECIMAL(12,3) NOT NULL,
  unit_price DECIMAL(14,2) NOT NULL,
  line_total DECIMAL(14,2) NOT NULL,
  KEY idx_quote_items (quote_id),
  FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,
] as $q) {
    try {
        $pdo->exec($q);
        echo 'ok: ' . substr(trim($q), 0, 60) . "\n";
    } catch (Throwable $e) {
        echo 'loi: ' . $e->getMessage() . "\n";
    }
}
