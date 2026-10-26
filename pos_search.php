<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$user = requireLogin();
header('Content-Type: application/json; charset=utf-8');

$q = trim($_GET['q'] ?? '');
$browse = !empty($_GET['browse']);

// Go sai chinh ta / thieu dau: goi y cac mat hang co ten gan giong (khong tinh dau, so khop mo). Chi dung khi tim thuong khong ra gi.
if (!empty($_GET['fuzzy']) && $q !== '') {
    require_once __DIR__ . '/inc_thong_minh.php';
    $fb = effectiveBranchId($user);
    $st = db()->prepare(
        "SELECT p.id, p.sku, p.name, p.sell_price, COALESCE(i.quantity, 0) AS qty
         FROM products p LEFT JOIN inventory i ON i.product_id = p.id AND i.branch_id = ? AND i.variant_id IS NULL
         WHERE p.tenant_id = ? AND p.is_active = 1 AND p.product_type = 'PRODUCT'
           AND NOT EXISTS (SELECT 1 FROM product_variants v WHERE v.product_id = p.id AND v.is_active = 1) LIMIT 3000"
    );
    $st->execute([$fb, currentTenantId()]);
    $qa = boDau($q);
    $scored = [];
    foreach ($st->fetchAll() as $r) {
        $na = boDau($r['name']);
        similar_text($qa, $na, $pct);
        $best = $pct;
        foreach (preg_split('/\s+/', $na, -1, PREG_SPLIT_NO_EMPTY) as $w) {   // go gan dung 1 tu trong ten cung tinh
            if (strlen($w) >= 3 && strlen($qa) >= 3) {
                similar_text($qa, $w, $p2);
                $best = max($best, $p2 - 5);
            }
        }
        if ($best >= 55) {
            $scored[] = [$best, ['id' => (int) $r['id'], 'variant_id' => null, 'sku' => $r['sku'], 'name' => $r['name'], 'sell_price' => $r['sell_price'], 'qty' => $r['qty']]];
        }
    }
    usort($scored, static fn($a, $b) => $b[0] <=> $a[0]);
    echo json_encode(array_map(static fn($x) => $x[1], array_slice($scored, 0, 5)), JSON_UNESCAPED_UNICODE);
    exit;
}
if ($q === '' && !$browse) {
    echo '[]';
    exit;
}

$branchId = effectiveBranchId($user);
$tenantId = currentTenantId();
$priceListId = (int) ($_GET['price_list_id'] ?? 0) ?: null;
$pdo = db();
$like = '%' . $q . '%';
$limit = $browse ? 60 : 15;

// Sản phẩm không có biến thể (bán trực tiếp theo product_id)
$stmt = $pdo->prepare(
    "SELECT p.id, NULL AS variant_id, p.sku, p.name, p.sell_price, p.product_type, p.created_at,
            COALESCE(i.quantity, 0) AS qty
     FROM products p
     LEFT JOIN inventory i ON i.product_id = p.id AND i.branch_id = ? AND i.variant_id IS NULL
     WHERE p.tenant_id = ? AND p.is_active = 1 AND p.product_type != 'COMBO' AND (? = 1 OR p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ?)
       AND NOT EXISTS (SELECT 1 FROM product_variants v WHERE v.product_id = p.id AND v.is_active = 1)
     ORDER BY p.name LIMIT $limit"
);
$stmt->execute([$branchId, $tenantId, $browse ? 1 : 0, $like, $like, $like]);
$products = $stmt->fetchAll();

// Combo (bán như 1 dòng, không kiểm tồn kho riêng)
$stmt = $pdo->prepare(
    "SELECT p.id, NULL AS variant_id, p.sku, p.name, p.sell_price, p.product_type, p.created_at, 999 AS qty
     FROM products p
     WHERE p.tenant_id = ? AND p.is_active = 1 AND p.product_type = 'COMBO' AND (? = 1 OR p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ?)
     ORDER BY p.name LIMIT $limit"
);
$stmt->execute([$tenantId, $browse ? 1 : 0, $like, $like, $like]);
$combos = $stmt->fetchAll();

// Biến thể sản phẩm (bán theo variant_id)
$stmt = $pdo->prepare(
    "SELECT p.id, v.id AS variant_id, v.sku, CONCAT(p.name, ' - ', v.name) AS name, v.sell_price, 'PRODUCT' AS product_type, p.created_at,
            COALESCE(i.quantity, 0) AS qty
     FROM product_variants v
     JOIN products p ON p.id = v.product_id
     LEFT JOIN inventory i ON i.variant_id = v.id AND i.branch_id = ?
     WHERE v.tenant_id = ? AND v.is_active = 1 AND p.is_active = 1
       AND (? = 1 OR p.name LIKE ? OR v.name LIKE ? OR v.sku LIKE ? OR v.barcode LIKE ?)
     ORDER BY p.name LIMIT $limit"
);
$stmt->execute([$branchId, $tenantId, $browse ? 1 : 0, $like, $like, $like, $like]);
$variants = $stmt->fetchAll();

$all = array_merge($products, $combos, $variants);

if ($priceListId) {
    $stmt = $pdo->prepare(
        'SELECT pp.product_id, pp.variant_id, pp.price FROM product_prices pp
         JOIN price_lists pl ON pl.id = pp.price_list_id WHERE pp.price_list_id = ? AND pl.tenant_id = ?'
    );
    $stmt->execute([$priceListId, $tenantId]);
    $overrides = [];
    foreach ($stmt->fetchAll() as $row) {
        $overrides[$row['product_id'] . ':' . ($row['variant_id'] ?? '')] = $row['price'];
    }
    foreach ($all as &$item) {
        $key = $item['id'] . ':' . ($item['variant_id'] ?? '');
        if (isset($overrides[$key])) {
            $item['sell_price'] = $overrides[$key];
            $item['price_list_applied'] = true;
        }
    }
    unset($item);
}

if ($browse) {
    $sortOrder = getSetting('product_sort_order', 'name_asc');
    usort($all, function ($a, $b) use ($sortOrder) {
        if ($sortOrder === 'newest') return strcmp($b['created_at'], $a['created_at']);
        $cmp = strcmp($a['name'], $b['name']);
        return $sortOrder === 'name_desc' ? -$cmp : $cmp;
    });
}

foreach ($all as &$item) {
    unset($item['product_type'], $item['created_at']);
}
unset($item);

echo json_encode($all, JSON_UNESCAPED_UNICODE);
