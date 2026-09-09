<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');

$pdo = db();
$gifts = $pdo->query(
    "SELECT id, name, points_required, stock_qty FROM gifts
     WHERE is_active = 1 AND (stock_qty IS NULL OR stock_qty > 0)
     ORDER BY points_required"
)->fetchAll();

echo json_encode($gifts, JSON_UNESCAPED_UNICODE);
