<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN', 'MANAGER');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('shipments.php');
checkCsrf();

$pdo = db();
$shipmentId = (int) ($_POST['shipment_id'] ?? 0);

$pdo->prepare('UPDATE shipments SET reconciled_at = NOW() WHERE id = ? AND reconciled_at IS NULL')
    ->execute([$shipmentId]);

redirect('shipments.php');
