<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN', 'MANAGER');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('shipments.php');
checkCsrf();

$pdo = db();
$shipmentId = (int) ($_POST['shipment_id'] ?? 0);

$pdo->prepare(
    'UPDATE shipments s JOIN orders o ON o.id = s.order_id JOIN branches b ON b.id = o.branch_id
     SET s.reconciled_at = NOW() WHERE s.id = ? AND s.reconciled_at IS NULL AND b.tenant_id = ?'
)->execute([$shipmentId, currentTenantId()]);

redirect('shipments.php');
