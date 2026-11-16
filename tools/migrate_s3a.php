<?php
/** Tao bang so theo doi nghia vu thue khac S3a. Idempotent. Tai len tam thanh _chk_mig.php roi xoa. */
require_once __DIR__ . '/inc_auth.php';
if ((string) ($_GET['k'] ?? '') !== '__KEY__') {
    http_response_code(404);
    exit;
}
$pdo = db();
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS other_tax_entries (
      id INT AUTO_INCREMENT PRIMARY KEY,
      tenant_id INT NOT NULL,
      entry_date DATE NOT NULL,
      tax_type VARCHAR(30) NOT NULL,
      description VARCHAR(255) NOT NULL,
      tax_base DECIMAL(16,2) NOT NULL DEFAULT 0,
      amount_payable DECIMAL(16,2) NOT NULL DEFAULT 0,
      amount_paid DECIMAL(16,2) NOT NULL DEFAULT 0,
      created_by_id INT NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_other_tax_tenant (tenant_id, entry_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "ok: other_tax_entries\n";
} catch (Throwable $e) {
    echo 'loi: ' . $e->getMessage() . "\n";
}
