<?php
/** Tao bang san xuat, dinh muc. Idempotent. Tai len tam thanh _chk_mig.php roi xoa. */
require_once __DIR__ . '/inc_auth.php';
if ((string) ($_GET['k'] ?? '') !== '__KEY__') {
    http_response_code(404);
    exit;
}
$pdo = db();
foreach ([
    <<<'SQL'
CREATE TABLE IF NOT EXISTS product_recipes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  product_id INT NOT NULL,
  material_id INT NOT NULL,
  qty_per_unit DECIMAL(16,4) NOT NULL,
  UNIQUE KEY uq_recipe (product_id, material_id),
  KEY idx_recipe_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,
    <<<'SQL'
CREATE TABLE IF NOT EXISTS productions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  branch_id INT NOT NULL,
  code VARCHAR(30) NOT NULL,
  product_id INT NOT NULL,
  qty DECIMAL(16,3) NOT NULL,
  material_cost DECIMAL(16,2) NOT NULL DEFAULT 0,
  extra_cost DECIMAL(16,2) NOT NULL DEFAULT 0,
  unit_cost DECIMAL(16,2) NOT NULL DEFAULT 0,
  note VARCHAR(255) NULL,
  prod_date DATE NOT NULL,
  created_by_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_production_code (tenant_id, code),
  KEY idx_production_tenant (tenant_id, prod_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,
    <<<'SQL'
CREATE TABLE IF NOT EXISTS production_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  production_id INT NOT NULL,
  material_id INT NOT NULL,
  qty DECIMAL(16,4) NOT NULL,
  unit_cost DECIMAL(16,2) NOT NULL,
  amount DECIMAL(16,2) NOT NULL,
  KEY idx_production_items (production_id)
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
