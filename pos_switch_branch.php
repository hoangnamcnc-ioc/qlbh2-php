<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$user = requireRole('ADMIN', 'MANAGER');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('pos.php');
checkCsrf();

$pdo = db();
$branchId = (int) ($_POST['branch_id'] ?? 0);

if ($branchId) {
    $check = $pdo->prepare('SELECT id FROM branches WHERE id = ?');
    $check->execute([$branchId]);
    if ($check->fetch()) {
        $_SESSION['pos_branch_id'] = $branchId;
        logActivity('POS_SWITCH_BRANCH', 'branch_id=' . $branchId);
    }
} else {
    unset($_SESSION['pos_branch_id']);
}

redirect('pos.php');
