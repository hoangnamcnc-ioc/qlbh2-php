<?php
/** Them cot ma so thue cho nha cung cap. Idempotent, chi THEM cot. Tai len tam thanh _chk_mig.php roi xoa. */
require_once __DIR__ . '/inc_auth.php';
if ((string) ($_GET['k'] ?? '') !== '__KEY__') {
    http_response_code(404);
    exit;
}
$pdo = db();
foreach (['ALTER TABLE suppliers ADD COLUMN tax_code VARCHAR(20) NULL'] as $q) {
    try {
        $pdo->exec($q);
        echo 'ok: ' . $q . "\n";
    } catch (Throwable $e) {
        echo 'skip: ' . $e->getMessage() . "\n";
    }
}
