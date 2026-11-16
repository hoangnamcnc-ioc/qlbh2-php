<?php
/** Tao bang tai san co dinh, phan bo, kiem ke quy. Idempotent. Tai len tam thanh _chk_mig.php roi xoa. */
require_once __DIR__ . '/inc_auth.php';
if ((string) ($_GET['k'] ?? '') !== '__KEY__') {
    http_response_code(404);
    exit;
}
$pdo = db();
foreach ([
    <<<'SQL'
CREATE TABLE IF NOT EXISTS fixed_assets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  code VARCHAR(30) NOT NULL,
  name VARCHAR(255) NOT NULL,
  acquired_date DATE NOT NULL,
  in_service_date DATE NOT NULL,
  cost DECIMAL(16,2) NOT NULL,
  salvage DECIMAL(16,2) NOT NULL DEFAULT 0,
  life_months INT NOT NULL,
  disposed_date DATE NULL,
  disposal_amount DECIMAL(16,2) NULL,
  note VARCHAR(255) NULL,
  created_by_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_fixed_asset_code (tenant_id, code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,
    <<<'SQL'
CREATE TABLE IF NOT EXISTS allocation_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  kind ENUM('PREPAID','DEFERRED') NOT NULL,
  name VARCHAR(255) NOT NULL,
  total DECIMAL(16,2) NOT NULL,
  start_month CHAR(7) NOT NULL,
  months INT NOT NULL,
  note VARCHAR(255) NULL,
  created_by_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_alloc_tenant (tenant_id, kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,
    <<<'SQL'
CREATE TABLE IF NOT EXISTS allocation_entries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  source ENUM('ASSET','ITEM') NOT NULL,
  source_id INT NOT NULL,
  period CHAR(7) NOT NULL,
  amount DECIMAL(16,2) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_alloc_entry (source, source_id, period),
  KEY idx_alloc_entry_tenant (tenant_id, period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,
    <<<'SQL'
CREATE TABLE IF NOT EXISTS cash_counts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  branch_id INT NOT NULL,
  count_date DATE NOT NULL,
  book_balance DECIMAL(16,2) NOT NULL,
  counted DECIMAL(16,2) NOT NULL,
  detail TEXT NULL,
  note VARCHAR(255) NULL,
  counted_by_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_cash_count (tenant_id, count_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,
] as $q) {
    try {
        $pdo->exec($q);
        echo 'ok: ' . substr(trim($q), 0, 60) . "
";
    } catch (Throwable $e) {
        echo 'loi: ' . $e->getMessage() . "
";
    }
}
