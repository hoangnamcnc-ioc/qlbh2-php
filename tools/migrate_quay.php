<?php
/** Them cot/bang cho danh sach mo/dong cua, ban giao ca. Idempotent. Tai len tam thanh _chk_mig.php roi xoa. */
require_once __DIR__ . '/inc_auth.php';
if ((string) ($_GET['k'] ?? '') !== '__KEY__') {
    http_response_code(404);
    exit;
}
$pdo = db();
foreach ([
    <<<'SQL'
ALTER TABLE pos_shifts ADD COLUMN handover_to_id INT NULL
SQL,
    <<<'SQL'
ALTER TABLE pos_shifts ADD COLUMN handover_note TEXT NULL
SQL,
    <<<'SQL'
CREATE TABLE IF NOT EXISTS checklist_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  kind ENUM('OPEN','CLOSE') NOT NULL,
  label VARCHAR(255) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT NOT NULL DEFAULT 1,
  KEY idx_checklist (tenant_id, kind, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,
    <<<'SQL'
CREATE TABLE IF NOT EXISTS shift_checklist_results (
  id INT AUTO_INCREMENT PRIMARY KEY,
  shift_id INT NOT NULL,
  kind ENUM('OPEN','CLOSE') NOT NULL,
  label VARCHAR(255) NOT NULL,
  done TINYINT NOT NULL,
  note VARCHAR(255) NULL,
  KEY idx_shift_chk (shift_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,
] as $q) {
    try {
        $pdo->exec($q);
        echo 'ok: ' . substr(trim($q), 0, 60) . "\n";
    } catch (Throwable $e) {
        echo 'skip: ' . $e->getMessage() . "\n";
    }
}
