<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$user = requireRole('ADMIN', 'MANAGER');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('pos.php');
checkCsrf();

$pdo = db();
$branchId = (int) ($_POST['branch_id'] ?? 0);

if ($branchId) {
    // BAT BUOC kiem tra chi nhanh thuoc dung tenant hien tai. Truoc day chi kiem tra chi nhanh
    // "co ton tai va dang hoat dong", nen ADMIN/MANAGER cua 1 cua hang co the POST branch_id cua
    // cua hang KHAC roi ban hang - don hang va phieu thu se roi vao so sach cua ho.
    $check = $pdo->prepare('SELECT id FROM branches WHERE id = ? AND is_active = 1 AND tenant_id = ?');
    $check->execute([$branchId, currentTenantId()]);
    if ($check->fetch()) {
        $_SESSION['pos_branch_id'] = $branchId;
        logActivity('POS_SWITCH_BRANCH', 'branch_id=' . $branchId);
    }
} else {
    unset($_SESSION['pos_branch_id']);
}

redirect('pos.php');
