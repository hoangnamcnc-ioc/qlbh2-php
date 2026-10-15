<?php
/**
 * Di tru CSDL cho quy trinh tap hoa (lo tu phieu nhap, xuat huy, han tra NCC, danh gia NCC, chu rut tien).
 * Chi THEM cot/bang, khong xoa hay sua du lieu. Idempotent. Chay 1 lan roi xoa khoi may chu.
 * Tai len thanh _chk_mig.php, goi: _chk_mig.php?k=__KEY__ (tools/deploy-migrate thay __KEY__ bang khoa ngau nhien).
 */
require_once __DIR__ . '/inc_auth.php';
if ((string) ($_GET['k'] ?? '') !== '__KEY__') {
    http_response_code(404);
    exit;
}
$pdo = db();
$sql = [
    'ALTER TABLE product_batches ADD COLUMN receipt_id INT NULL',
    'ALTER TABLE stock_receipts ADD COLUMN due_date DATE NULL',
    'ALTER TABLE cashbook_entries ADD COLUMN category VARCHAR(20) NULL',
    'ALTER TABLE suppliers ADD COLUMN category VARCHAR(100) NULL',
    "CREATE TABLE IF NOT EXISTS stock_write_offs (id INT AUTO_INCREMENT PRIMARY KEY, tenant_id INT NOT NULL, code VARCHAR(50) NOT NULL, branch_id INT NOT NULL, product_id INT NOT NULL, variant_id INT NULL, batch_id INT NULL, quantity DECIMAL(12,3) NOT NULL, cost_price DECIMAL(14,2) NOT NULL DEFAULT 0, reason ENUM('EXPIRED','DAMAGED','LOST','OTHER') NOT NULL DEFAULT 'OTHER', note VARCHAR(255) NULL, created_by_id INT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uniq_writeoff_code (code), KEY idx_writeoff_tenant (tenant_id, created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS supplier_reviews (id INT AUTO_INCREMENT PRIMARY KEY, tenant_id INT NOT NULL, supplier_id INT NOT NULL, kind ENUM('LATE','DEFECT','PRICE_UP','GOOD','OTHER') NOT NULL DEFAULT 'OTHER', note VARCHAR(255) NULL, created_by_id INT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_review_supplier (supplier_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];
foreach ($sql as $q) {
    try {
        $pdo->exec($q);
        echo 'ok: ' . substr($q, 0, 60) . "\n";
    } catch (Throwable $e) {
        echo 'skip: ' . substr($q, 0, 40) . ' -> ' . $e->getMessage() . "\n";
    }
}
