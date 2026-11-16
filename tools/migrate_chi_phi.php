<?php
/** Them cot phan loai chi phi cho so quy. Idempotent, chi THEM cot. Tai len tam thanh _chk_mig.php roi xoa. */
require_once __DIR__ . '/inc_auth.php';
if ((string) ($_GET['k'] ?? '') !== '__KEY__') {
    http_response_code(404);
    exit;
}
$pdo = db();
foreach ([
    'ALTER TABLE cashbook_entries ADD COLUMN expense_group VARCHAR(20) NULL',
    'ALTER TABLE cashbook_entries ADD COLUMN deductible TINYINT NULL',
    'ALTER TABLE cashbook_entries ADD COLUMN has_document TINYINT NOT NULL DEFAULT 0',
] as $q) {
    try {
        $pdo->exec($q);
        echo 'ok: ' . $q . "\n";
    } catch (Throwable $e) {
        echo 'skip: ' . $e->getMessage() . "\n";
    }
}
