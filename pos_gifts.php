<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');

$pdo = db();
$giftsStmt = $pdo->prepare(
    "SELECT id, name, points_required, stock_qty FROM gifts
     WHERE is_active = 1 AND (stock_qty IS NULL OR stock_qty > 0) AND tenant_id = ?
     ORDER BY points_required"
);
$giftsStmt->execute([currentTenantId()]);
$gifts = $giftsStmt->fetchAll();

echo json_encode($gifts, JSON_UNESCAPED_UNICODE);
