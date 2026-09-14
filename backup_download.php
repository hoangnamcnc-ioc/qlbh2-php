<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireSuperAdmin();

$name = basename($_GET['f'] ?? '');
if (!preg_match('/^backup_\d{8}_\d{6}\.sql\.gz$/', $name)) {
    http_response_code(404);
    exit('Không tìm thấy file.');
}

$path = backupDir() . '/' . $name;
if (!is_file($path)) {
    http_response_code(404);
    exit('Không tìm thấy file.');
}

logActivity('BACKUP_DOWNLOAD', $name);

header('Content-Type: application/gzip');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
