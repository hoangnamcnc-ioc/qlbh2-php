<?php
/** Tao bang ho so nhan su, nghi phep. Idempotent. Tai len tam thanh _chk_mig.php roi xoa. */
require_once __DIR__ . '/inc_auth.php';
if ((string) ($_GET['k'] ?? '') !== '__KEY__') {
    http_response_code(404);
    exit;
}
$pdo = db();
foreach ([
    <<<'SQL'
CREATE TABLE IF NOT EXISTS hr_profiles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  user_id INT NOT NULL,
  contract_type ENUM('PROBATION','FIXED','INDEFINITE','SEASONAL') NOT NULL DEFAULT 'INDEFINITE',
  contract_number VARCHAR(50) NULL,
  contract_start DATE NULL,
  contract_end DATE NULL,
  insured TINYINT NOT NULL DEFAULT 0,
  insured_salary DECIMAL(14,2) NOT NULL DEFAULT 0,
  region TINYINT NOT NULL DEFAULT 1,
  dependents INT NOT NULL DEFAULT 0,
  tax_code VARCHAR(20) NULL,
  annual_leave_days DECIMAL(4,1) NOT NULL DEFAULT 12,
  note VARCHAR(255) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_hr_profile_user (user_id),
  KEY idx_hr_profile_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,
    <<<'SQL'
CREATE TABLE IF NOT EXISTS leave_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  user_id INT NOT NULL,
  leave_type ENUM('ANNUAL','SICK','UNPAID','OTHER') NOT NULL DEFAULT 'ANNUAL',
  from_date DATE NOT NULL,
  to_date DATE NOT NULL,
  days DECIMAL(4,1) NOT NULL,
  reason VARCHAR(255) NULL,
  status ENUM('PENDING','APPROVED','REJECTED') NOT NULL DEFAULT 'PENDING',
  decided_by_id INT NULL,
  decided_at DATETIME NULL,
  decision_note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_leave_tenant (tenant_id, status),
  KEY idx_leave_user (user_id, from_date)
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
