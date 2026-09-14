<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireRole('ADMIN');

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['download'])) {
    checkCsrf();
    $tid = currentTenantId();
    $pdo = db();

    $branchSub = "SELECT id FROM branches WHERE tenant_id = $tid";
    $orderSub = "SELECT id FROM orders WHERE branch_id IN ($branchSub)";
    $customerSub = "SELECT id FROM customers WHERE tenant_id = $tid";
    $productSub = "SELECT id FROM products WHERE tenant_id = $tid";
    $orderItemSub = "SELECT id FROM order_items WHERE order_id IN ($orderSub)";

    $tables = [
        'tenants' => "id = $tid",
        'branches' => "tenant_id = $tid",
        'users' => "tenant_id = $tid",
        'store_settings' => "tenant_id = $tid",
        'categories' => "tenant_id = $tid",
        'brands' => "tenant_id = $tid",
        'products' => "tenant_id = $tid",
        'product_variants' => "tenant_id = $tid",
        'product_images' => "product_id IN ($productSub)",
        'product_prices' => "product_id IN ($productSub)",
        'product_batches' => "product_id IN ($productSub)",
        'combo_items' => "combo_product_id IN ($productSub)",
        'price_adjustments' => "product_id IN ($productSub)",
        'price_lists' => "tenant_id = $tid",
        'suppliers' => "tenant_id = $tid",
        'customer_groups' => "tenant_id = $tid",
        'customer_tiers' => "tenant_id = $tid",
        'customers' => "tenant_id = $tid",
        'customer_addresses' => "customer_id IN ($customerSub)",
        'customer_notes' => "customer_id IN ($customerSub)",
        'customer_debt_entries' => "customer_id IN ($customerSub)",
        'sales_channels' => "tenant_id = $tid",
        'order_sources' => "tenant_id = $tid",
        'cancel_reasons' => "tenant_id = $tid",
        'tax_rates' => "tenant_id = $tid",
        'coupons' => "tenant_id = $tid",
        'campaigns' => "tenant_id = $tid",
        'promotions' => "tenant_id = $tid",
        'gifts' => "tenant_id = $tid",
        'gift_redemptions' => "gift_id IN (SELECT id FROM gifts WHERE tenant_id = $tid)",
        'inventory' => "branch_id IN ($branchSub)",
        'orders' => "branch_id IN ($branchSub)",
        'order_items' => "order_id IN ($orderSub)",
        'payments' => "order_id IN ($orderSub)",
        'order_status_history' => "order_id IN ($orderSub)",
        'order_returns' => "order_id IN ($orderSub)",
        'order_return_items' => "return_id IN (SELECT id FROM order_returns WHERE order_id IN ($orderSub))",
        'cashbook_entries' => "branch_id IN ($branchSub)",
        'shipments' => "order_id IN ($orderSub)",
        'stock_receipts' => "branch_id IN ($branchSub)",
        'stock_receipt_items' => "receipt_id IN (SELECT id FROM stock_receipts WHERE branch_id IN ($branchSub))",
        'stock_takes' => "branch_id IN ($branchSub)",
        'stock_take_items' => "take_id IN (SELECT id FROM stock_takes WHERE branch_id IN ($branchSub))",
        'stock_transfers' => "from_branch_id IN ($branchSub)",
        'stock_transfer_items' => "transfer_id IN (SELECT id FROM stock_transfers WHERE from_branch_id IN ($branchSub))",
        'purchase_orders' => "branch_id IN ($branchSub)",
        'purchase_order_items' => "po_id IN (SELECT id FROM purchase_orders WHERE branch_id IN ($branchSub))",
        'suppliers_returns_placeholder' => null, // bo qua, thay bang ten bang that ben duoi
        'supplier_returns' => "branch_id IN ($branchSub)",
        'supplier_return_items' => "return_id IN (SELECT id FROM supplier_returns WHERE branch_id IN ($branchSub))",
        'warranty_policies' => "tenant_id = $tid",
        'warranty_cards' => "order_item_id IN ($orderItemSub)",
        'warranty_claims' => "warranty_card_id IN (SELECT id FROM warranty_cards WHERE order_item_id IN ($orderItemSub))",
        'activity_logs' => "tenant_id = $tid",
    ];
    unset($tables['suppliers_returns_placeholder']);

    $sql = "-- QLBH2 - Xuat du lieu tenant #$tid - " . date('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";
    foreach ($tables as $table => $where) {
        $count = (int) $pdo->query("SELECT COUNT(*) FROM `$table` WHERE $where")->fetchColumn();
        if ($count === 0) {
            continue;
        }
        $chunk = 500;
        for ($offset = 0; $offset < $count; $offset += $chunk) {
            $rows = $pdo->query("SELECT * FROM `$table` WHERE $where LIMIT $chunk OFFSET $offset")->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) {
                break;
            }
            $colList = '`' . implode('`,`', array_keys($rows[0])) . '`';
            $valueGroups = [];
            foreach ($rows as $row) {
                $vals = array_map(fn ($v) => $v === null ? 'NULL' : $pdo->quote($v), $row);
                $valueGroups[] = '(' . implode(',', $vals) . ')';
            }
            $sql .= "INSERT INTO `$table` ($colList) VALUES " . implode(',', $valueGroups) . ";\n";
        }
    }
    $sql .= "\nSET FOREIGN_KEY_CHECKS=1;\n";

    logActivity('TENANT_EXPORT', 'tenant_id=' . $tid);

    $filename = 'qlbh2_export_' . date('Ymd_His') . '.sql.gz';
    $gz = gzencode($sql, 9);
    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($gz));
    echo $gz;
    exit;
}

require_once __DIR__ . '/inc_header.php';
?>

<h1 style="font-size:24px;font-weight:600;margin-bottom:16px;">Xuất dữ liệu của tôi</h1>

<div class="card" style="max-width:760px;">
  <p class="muted" style="margin:0 0 16px;">
    Tải về toàn bộ dữ liệu của cửa hàng bạn (sản phẩm, khách hàng, đơn hàng, tồn kho, kiểm/chuyển
    hàng, nhập hàng, bảo hành, sổ quỹ...) thành 1 file <code>.sql.gz</code> lưu về máy tính của bạn.
    File chỉ chứa dữ liệu của riêng cửa hàng bạn, không có dữ liệu của khách hàng khác trên hệ thống.
  </p>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <input type="hidden" name="download" value="1">
    <button type="submit" class="btn">⬇️ Tải xuống dữ liệu của tôi</button>
  </form>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
