<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireLogin();

$pdo = db();
$id = (int) ($_GET['id'] ?? 0);

$items = $pdo->prepare(
    'SELECT oi.quantity, oi.line_total, p.name AS product_name, v.name AS variant_name
     FROM order_items oi JOIN products p ON p.id = oi.product_id
     LEFT JOIN product_variants v ON v.id = oi.variant_id
     WHERE oi.order_id = ?'
);
$items->execute([$id]);
$items = $items->fetchAll();

if (!$items) {
    echo '<span class="muted">Không có sản phẩm.</span>';
    exit;
}

foreach ($items as $it) {
    $name = e($it['product_name']) . ($it['variant_name'] ? ' (' . e($it['variant_name']) . ')' : '');
    echo '<div>' . $name . ' × ' . (int) $it['quantity'] . ' — ' . money($it['line_total']) . '</div>';
}
