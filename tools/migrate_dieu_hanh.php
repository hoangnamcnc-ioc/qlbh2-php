<?php
/** Tao bang muc tieu doanh thu, chi dinh ky. Idempotent. Tai len tam thanh _chk_mig.php roi xoa. */
require_once __DIR__ . '/inc_auth.php';
if ((string) ($_GET['k'] ?? '') !== '__KEY__') {
    http_response_code(404);
    exit;
}
$pdo = db();
foreach ([
    <<<'SQL'
CREATE TABLE IF NOT EXISTS sales_targets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  month CHAR(7) NOT NULL,
  amount DECIMAL(16,2) NOT NULL,
  note VARCHAR(255) NULL,
  UNIQUE KEY uq_sales_target (tenant_id, month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,
    <<<'SQL'
CREATE TABLE IF NOT EXISTS recurring_expenses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  branch_id INT NOT NULL,
  name VARCHAR(255) NOT NULL,
  amount DECIMAL(16,2) NOT NULL,
  day_of_month TINYINT NOT NULL DEFAULT 1,
  expense_group VARCHAR(20) NOT NULL DEFAULT 'OTHER',
  payment_method ENUM('CASH','BANK_TRANSFER','CARD','QR_CODE') NOT NULL DEFAULT 'BANK_TRANSFER',
  deductible TINYINT NOT NULL DEFAULT 1,
  is_active TINYINT NOT NULL DEFAULT 1,
  created_by_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_recurring_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,
    <<<'SQL'
CREATE TABLE IF NOT EXISTS recurring_runs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  recurring_id INT NOT NULL,
  month CHAR(7) NOT NULL,
  cashbook_code VARCHAR(50) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_recurring_run (recurring_id, month)
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
