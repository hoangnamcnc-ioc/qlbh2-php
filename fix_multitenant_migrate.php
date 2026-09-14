<?php
require_once __DIR__ . '/config.php';
$pdo = db();

function colExists(PDO $pdo, string $table, string $col): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $col]);
    return (int) $stmt->fetchColumn() > 0;
}

function idxExists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $stmt->execute([$table, $index]);
    return (int) $stmt->fetchColumn() > 0;
}

/** Thêm tenant_id (FK -> tenants) vào 1 bảng nếu chưa có, bỏ qua an toàn nếu đã chạy trước đó. */
function addTenantId(PDO $pdo, string $table, bool $nullable = false): string
{
    if (colExists($pdo, $table, 'tenant_id')) {
        return "SKIP $table (đã có tenant_id)";
    }
    $def = $nullable ? 'INT NULL' : 'INT NOT NULL DEFAULT 1';
    $pdo->exec("ALTER TABLE `$table` ADD COLUMN tenant_id $def, ADD CONSTRAINT fk_{$table}_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)");
    if ($nullable) {
        $pdo->exec("UPDATE `$table` SET tenant_id = 1");
    }
    return "OK $table";
}

/** DROP 1 UNIQUE INDEX nếu tồn tại (tên index trùng tên cột, theo mặc định MySQL cho UNIQUE inline). */
function dropUniqueIfExists(PDO $pdo, string $table, string $indexName): void
{
    if (idxExists($pdo, $table, $indexName)) {
        $pdo->exec("ALTER TABLE `$table` DROP INDEX `$indexName`");
    }
}

header('Content-Type: text/plain; charset=utf-8');
echo "=== Migrate multi-tenant QLBH2 ===\n\n";

try {
    // 1. Bảng tenants + tenant sở hữu (id=1) cho toàn bộ dữ liệu hiện có
    $pdo->exec("CREATE TABLE IF NOT EXISTS tenants (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        owner_email VARCHAR(255) NULL,
        plan ENUM('TRIAL','PAID') NOT NULL DEFAULT 'TRIAL',
        trial_ends_at DATETIME NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "OK tạo bảng tenants\n";

    $pdo->exec("INSERT INTO tenants (id, name, plan, is_active) VALUES (1, 'KT-SOFT (chủ sở hữu)', 'PAID', 1) ON DUPLICATE KEY UPDATE name = name");
    echo "OK tenant #1 (KT-SOFT chủ sở hữu)\n\n";

    // 2. Các bảng chỉ cần thêm tenant_id (không có UNIQUE cần đổi)
    $simpleTables = [
        'branches', 'users', 'categories', 'suppliers', 'customer_tiers', 'campaigns',
        'promotions', 'warranty_policies', 'tax_rates', 'cancel_reasons', 'order_sources',
        'sales_channels', 'gifts', 'price_lists',
    ];
    foreach ($simpleTables as $t) {
        echo addTenantId($pdo, $t) . "\n";
    }
    echo addTenantId($pdo, 'activity_logs', true) . "\n\n";

    // 3. products: drop UNIQUE toàn cục sku/barcode, thêm tenant_id + UNIQUE (tenant_id, cột)
    if (!colExists($pdo, 'products', 'tenant_id')) {
        dropUniqueIfExists($pdo, 'products', 'sku');
        dropUniqueIfExists($pdo, 'products', 'barcode');
        $pdo->exec("ALTER TABLE products ADD COLUMN tenant_id INT NOT NULL DEFAULT 1, ADD CONSTRAINT fk_products_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)");
        $pdo->exec("ALTER TABLE products ADD UNIQUE KEY uniq_products_tenant_sku (tenant_id, sku)");
        $pdo->exec("ALTER TABLE products ADD UNIQUE KEY uniq_products_tenant_barcode (tenant_id, barcode)");
        echo "OK products\n";
    } else {
        echo "SKIP products (đã có tenant_id)\n";
    }

    // 4. product_variants: drop UNIQUE sku, thêm tenant_id + UNIQUE (tenant_id, sku)
    if (!colExists($pdo, 'product_variants', 'tenant_id')) {
        dropUniqueIfExists($pdo, 'product_variants', 'sku');
        $pdo->exec("ALTER TABLE product_variants ADD COLUMN tenant_id INT NOT NULL DEFAULT 1, ADD CONSTRAINT fk_variants_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)");
        $pdo->exec("ALTER TABLE product_variants ADD UNIQUE KEY uniq_variants_tenant_sku (tenant_id, sku)");
        echo "OK product_variants\n";
    } else {
        echo "SKIP product_variants (đã có tenant_id)\n";
    }

    // 5. brands: drop UNIQUE name, thêm tenant_id + UNIQUE (tenant_id, name)
    if (!colExists($pdo, 'brands', 'tenant_id')) {
        dropUniqueIfExists($pdo, 'brands', 'name');
        $pdo->exec("ALTER TABLE brands ADD COLUMN tenant_id INT NOT NULL DEFAULT 1, ADD CONSTRAINT fk_brands_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)");
        $pdo->exec("ALTER TABLE brands ADD UNIQUE KEY uniq_brands_tenant_name (tenant_id, name)");
        echo "OK brands\n";
    } else {
        echo "SKIP brands (đã có tenant_id)\n";
    }

    // 6. customers: drop UNIQUE phone + code, thêm tenant_id + UNIQUE tương ứng
    if (!colExists($pdo, 'customers', 'tenant_id')) {
        dropUniqueIfExists($pdo, 'customers', 'phone');
        dropUniqueIfExists($pdo, 'customers', 'code');
        $pdo->exec("ALTER TABLE customers ADD COLUMN tenant_id INT NOT NULL DEFAULT 1, ADD CONSTRAINT fk_customers_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)");
        $pdo->exec("ALTER TABLE customers ADD UNIQUE KEY uniq_customers_tenant_phone (tenant_id, phone)");
        $pdo->exec("ALTER TABLE customers ADD UNIQUE KEY uniq_customers_tenant_code (tenant_id, code)");
        echo "OK customers\n";
    } else {
        echo "SKIP customers (đã có tenant_id)\n";
    }

    // 7. customer_groups: drop UNIQUE name + code, thêm tenant_id + UNIQUE tương ứng
    if (!colExists($pdo, 'customer_groups', 'tenant_id')) {
        dropUniqueIfExists($pdo, 'customer_groups', 'name');
        dropUniqueIfExists($pdo, 'customer_groups', 'code');
        $pdo->exec("ALTER TABLE customer_groups ADD COLUMN tenant_id INT NOT NULL DEFAULT 1, ADD CONSTRAINT fk_customer_groups_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)");
        $pdo->exec("ALTER TABLE customer_groups ADD UNIQUE KEY uniq_cg_tenant_name (tenant_id, name)");
        $pdo->exec("ALTER TABLE customer_groups ADD UNIQUE KEY uniq_cg_tenant_code (tenant_id, code)");
        echo "OK customer_groups\n";
    } else {
        echo "SKIP customer_groups (đã có tenant_id)\n";
    }

    // 8. coupons: drop UNIQUE code, thêm tenant_id + UNIQUE (tenant_id, code)
    if (!colExists($pdo, 'coupons', 'tenant_id')) {
        dropUniqueIfExists($pdo, 'coupons', 'code');
        $pdo->exec("ALTER TABLE coupons ADD COLUMN tenant_id INT NOT NULL DEFAULT 1, ADD CONSTRAINT fk_coupons_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)");
        $pdo->exec("ALTER TABLE coupons ADD UNIQUE KEY uniq_coupons_tenant_code (tenant_id, code)");
        echo "OK coupons\n";
    } else {
        echo "SKIP coupons (đã có tenant_id)\n";
    }

    // 9. store_settings: đổi PRIMARY KEY (setting_key) -> (tenant_id, setting_key)
    if (!colExists($pdo, 'store_settings', 'tenant_id')) {
        $pdo->exec("ALTER TABLE store_settings ADD COLUMN tenant_id INT NOT NULL DEFAULT 1");
        $pdo->exec("ALTER TABLE store_settings DROP PRIMARY KEY, ADD PRIMARY KEY (tenant_id, setting_key)");
        $pdo->exec("ALTER TABLE store_settings ADD CONSTRAINT fk_store_settings_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)");
        echo "OK store_settings\n";
    } else {
        echo "SKIP store_settings (đã có tenant_id)\n";
    }

    echo "\n=== XONG. Toàn bộ dữ liệu hiện có thuộc tenant_id = 1 (KT-SOFT chủ sở hữu). ===\n";
} catch (Throwable $e) {
    echo "\n!!! LỖI: " . $e->getMessage() . "\n";
}
