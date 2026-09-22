<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$user = requireRole('ADMIN', 'MANAGER');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('pos.php');
checkCsrf();

$pdo = db();
$branchId = (int) ($_POST['branch_id'] ?? 0);

if ($branchId) {
    // Chi nhanh phai thuoc dung tenant hien tai: neu khong, ADMIN/MANAGER cua 1 cua hang co the
    // POST branch_id cua cua hang KHAC roi ban hang - don hang va phieu thu se roi vao so sach ho.
    $branch = layChiNhanhCuaToi($branchId);
    if ($branch && $branch['is_active']) {
        $_SESSION['pos_branch_id'] = $branchId;
        logActivity('POS_SWITCH_BRANCH', 'branch_id=' . $branchId);
    }
} else {
    unset($_SESSION['pos_branch_id']);
}

redirect('pos.php');
