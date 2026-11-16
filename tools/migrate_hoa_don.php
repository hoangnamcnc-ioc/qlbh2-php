<?php
/** Tao bang so hoa don. Idempotent. Tai len tam thanh _chk_mig.php roi xoa. */
require_once __DIR__ . '/inc_auth.php';
if ((string) ($_GET['k'] ?? '') !== '__KEY__') {
    http_response_code(404);
    exit;
}
$pdo = db();
foreach ([
    <<<'SQL'
CREATE TABLE IF NOT EXISTS invoice_register (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  direction ENUM('SALE','PURCHASE') NOT NULL,
  symbol VARCHAR(20) NOT NULL,
  number VARCHAR(20) NOT NULL,
  issue_date DATE NOT NULL,
  partner_name VARCHAR(255) NOT NULL,
  partner_tax_code VARCHAR(20) NULL,
  amount_before_tax DECIMAL(16,2) NOT NULL,
  vat_rate DECIMAL(4,1) NOT NULL DEFAULT 0,
  vat_amount DECIMAL(16,2) NOT NULL DEFAULT 0,
  total DECIMAL(16,2) NOT NULL,
  order_id INT NULL,
  status ENUM('ISSUED','VOID') NOT NULL DEFAULT 'ISSUED',
  note VARCHAR(255) NULL,
  created_by_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_invoice_tenant (tenant_id, direction, issue_date),
  KEY idx_invoice_order (order_id)
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
