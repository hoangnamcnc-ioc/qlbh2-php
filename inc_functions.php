<?php
function money($amount): string
{
    return number_format((float) $amount, 0, ',', '.');
}

function e(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

function backupDir(): string
{
    $dir = __DIR__ . '/backups';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    if (!file_exists($dir . '/.htaccess')) {
        file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
    }
    return $dir;
}

/**
 * Sinh SQL dump CHỈ chứa dữ liệu của 1 tenant (dùng chung cho tenant_export.php - tải tay - và
 * daily_cron.php - gửi email định kỳ). Rút ra thành hàm riêng để 2 nơi không có 2 bản sao danh
 * sách ~52 bảng có thể lệch nhau theo thời gian khi thêm bảng mới vào hệ thống.
 */
function generateTenantExportSql(int $tid): string
{
    $pdo = db();
    $branchSub = "SELECT id FROM branches WHERE tenant_id = $tid";
    $orderSub = "SELECT id FROM orders WHERE branch_id IN ($branchSub)";
    $customerSub = "SELECT id FROM customers WHERE tenant_id = $tid";
    $productSub = "SELECT id FROM products WHERE tenant_id = $tid";
    $orderItemSub = "SELECT id FROM order_items WHERE order_id IN ($orderSub)";

    $tables = [
        'tenants' => "id = $tid",
        'branches' => "tenant_id = $tid",
        'users' => "tenant_id = $tid",
        'store_settings' => "tenant_id = $tid",
        'categories' => "tenant_id = $tid",
        'brands' => "tenant_id = $tid",
        'products' => "tenant_id = $tid",
        'product_variants' => "tenant_id = $tid",
        'product_images' => "product_id IN ($productSub)",
        'product_prices' => "product_id IN ($productSub)",
        'product_batches' => "product_id IN ($productSub)",
        'combo_items' => "combo_product_id IN ($productSub)",
        'price_adjustments' => "product_id IN ($productSub)",
        'price_lists' => "tenant_id = $tid",
        'suppliers' => "tenant_id = $tid",
        'customer_groups' => "tenant_id = $tid",
        'customer_tiers' => "tenant_id = $tid",
        'customers' => "tenant_id = $tid",
        'customer_addresses' => "customer_id IN ($customerSub)",
        'customer_notes' => "customer_id IN ($customerSub)",
        'customer_debt_entries' => "customer_id IN ($customerSub)",
        'sales_channels' => "tenant_id = $tid",
        'order_sources' => "tenant_id = $tid",
        'cancel_reasons' => "tenant_id = $tid",
        'tax_rates' => "tenant_id = $tid",
        'coupons' => "tenant_id = $tid",
        'campaigns' => "tenant_id = $tid",
        'promotions' => "tenant_id = $tid",
        'gifts' => "tenant_id = $tid",
        'gift_redemptions' => "gift_id IN (SELECT id FROM gifts WHERE tenant_id = $tid)",
        'inventory' => "branch_id IN ($branchSub)",
        'orders' => "branch_id IN ($branchSub)",
        'order_items' => "order_id IN ($orderSub)",
        'payments' => "order_id IN ($orderSub)",
        'order_status_history' => "order_id IN ($orderSub)",
        'order_returns' => "order_id IN ($orderSub)",
        'order_return_items' => "return_id IN (SELECT id FROM order_returns WHERE order_id IN ($orderSub))",
        'cashbook_entries' => "branch_id IN ($branchSub)",
        'shipments' => "order_id IN ($orderSub)",
        'stock_receipts' => "branch_id IN ($branchSub)",
        'stock_receipt_items' => "receipt_id IN (SELECT id FROM stock_receipts WHERE branch_id IN ($branchSub))",
        'stock_takes' => "branch_id IN ($branchSub)",
        'stock_take_items' => "take_id IN (SELECT id FROM stock_takes WHERE branch_id IN ($branchSub))",
        'stock_transfers' => "from_branch_id IN ($branchSub)",
        'stock_transfer_items' => "transfer_id IN (SELECT id FROM stock_transfers WHERE from_branch_id IN ($branchSub))",
        'purchase_orders' => "branch_id IN ($branchSub)",
        'purchase_order_items' => "po_id IN (SELECT id FROM purchase_orders WHERE branch_id IN ($branchSub))",
        'supplier_returns' => "branch_id IN ($branchSub)",
        'supplier_return_items' => "return_id IN (SELECT id FROM supplier_returns WHERE branch_id IN ($branchSub))",
        'warranty_policies' => "tenant_id = $tid",
        'warranty_cards' => "order_item_id IN ($orderItemSub)",
        'warranty_claims' => "warranty_card_id IN (SELECT id FROM warranty_cards WHERE order_item_id IN ($orderItemSub))",
        'activity_logs' => "tenant_id = $tid",
    ];

    $sql = "-- QLBH-CLOUD - Xuat du lieu tenant #$tid - " . date('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";
    foreach ($tables as $table => $where) {
        $count = (int) $pdo->query("SELECT COUNT(*) FROM `$table` WHERE $where")->fetchColumn();
        if ($count === 0) {
            continue;
        }
        $chunk = 500;
        for ($offset = 0; $offset < $count; $offset += $chunk) {
            $rows = $pdo->query("SELECT * FROM `$table` WHERE $where LIMIT $chunk OFFSET $offset")->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) {
                break;
            }
            $colList = '`' . implode('`,`', array_keys($rows[0])) . '`';
            $valueGroups = [];
            foreach ($rows as $row) {
                $vals = array_map(fn ($v) => $v === null ? 'NULL' : $pdo->quote($v), $row);
                $valueGroups[] = '(' . implode(',', $vals) . ')';
            }
            $sql .= "INSERT INTO `$table` ($colList) VALUES " . implode(',', $valueGroups) . ";\n";
        }
    }
    $sql .= "\nSET FOREIGN_KEY_CHECKS=1;\n";
    return $sql;
}

/**
 * Thư mục sao lưu riêng cho 1 tenant (khác hẳn backupDir() - đó là sao lưu TOÀN HỆ THỐNG chỉ
 * chủ sở hữu KT-SOFT truy cập). Dùng cho sao lưu định kỳ gửi email (daily_cron.php).
 */
function tenantBackupDir(): string
{
    $dir = __DIR__ . '/tenant_backups';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    if (!file_exists($dir . '/.htaccess')) {
        file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
    }
    return $dir;
}

/**
 * Tạo file sao lưu .sql.gz riêng cho 1 tenant, trả về tên file (không kèm đường dẫn thư mục).
 * Không đính kèm file vào email (sendMail() chỉ hỗ trợ text/plain, và file có thể vượt giới hạn
 * đính kèm nhiều nhà cung cấp email) - thay vào đó tenant_backup_download.php phục vụ file này
 * qua 1 token ngẫu nhiên có hạn dùng, gửi kèm trong email.
 */
function createTenantBackupFile(int $tenantId): string
{
    $sql = generateTenantExportSql($tenantId);
    $filename = 'tenant' . $tenantId . '_' . date('Ymd_His') . '.sql.gz';
    file_put_contents(tenantBackupDir() . '/' . $filename, gzencode($sql, 9));
    return $filename;
}

/**
 * Sao lưu 1-click bằng PHP thuần (dump SQL + nén gzip) vì hosting chia sẻ không có SSH/shell
 * để dùng mysqldump thật. Dump từng bảng theo lô 500 dòng để tránh tràn bộ nhớ với bảng lớn.
 * Giữ lại tối đa 20 bản sao lưu gần nhất, tự xóa bản cũ hơn.
 */
function createBackup(): string
{
    $pdo = db();
    $dir = backupDir();
    $filename = 'backup_' . date('Ymd_His') . '.sql.gz';
    $gz = gzopen($dir . '/' . $filename, 'wb9');

    gzwrite($gz, "-- QLBH-CLOUD backup " . date('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        $create = $pdo->query("SHOW CREATE TABLE `$table`")->fetch();
        gzwrite($gz, "DROP TABLE IF EXISTS `$table`;\n" . $create['Create Table'] . ";\n\n");

        $count = (int) $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
        $chunk = 500;
        for ($offset = 0; $offset < $count; $offset += $chunk) {
            $rows = $pdo->query("SELECT * FROM `$table` LIMIT $chunk OFFSET $offset")->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) {
                break;
            }
            $colList = '`' . implode('`,`', array_keys($rows[0])) . '`';
            $valueGroups = [];
            foreach ($rows as $row) {
                $vals = array_map(fn ($v) => $v === null ? 'NULL' : $pdo->quote($v), $row);
                $valueGroups[] = '(' . implode(',', $vals) . ')';
            }
            gzwrite($gz, "INSERT INTO `$table` ($colList) VALUES " . implode(',', $valueGroups) . ";\n");
        }
        gzwrite($gz, "\n");
    }

    gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
    gzclose($gz);

    $files = glob($dir . '/backup_*.sql.gz');
    usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));
    foreach (array_slice($files, 20) as $old) {
        unlink($old);
    }

    return $filename;
}

function listBackups(): array
{
    $files = glob(backupDir() . '/backup_*.sql.gz');
    usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));
    return array_map(fn ($f) => ['name' => basename($f), 'size' => filesize($f), 'time' => filemtime($f)], $files);
}

function redirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}

function genCode(string $prefix): string
{
    return $prefix . strtoupper(base_convert((string) (microtime(true) * 1000), 10, 36));
}

/** Lấy giá trị POST đã trim, hoặc chuỗi rỗng. */
function post(string $key): string
{
    return trim((string) ($_POST[$key] ?? ''));
}

function postFloat(string $key, float $default = 0): float
{
    $v = $_POST[$key] ?? null;
    if ($v === null || $v === '') return $default;
    $n = (float) $v;
    return $n >= 0 ? $n : $default;
}

function postInt(string $key, int $default = 0): int
{
    $v = $_POST[$key] ?? null;
    if ($v === null || $v === '') return $default;
    $n = (int) $v;
    return $n >= 0 ? $n : $default;
}

/**
 * Đọc 1 giá trị số lượng từ POST — cho phép số lẻ (vd 0.35 kg hàng cân) thay vì chỉ số nguyên,
 * làm tròn 3 chữ số thập phân (đủ chính xác tới gram). Dùng cho mọi cột quantity đã đổi sang
 * DECIMAL(12,3): order_items, inventory, stock_receipt_items, stock_transfer_items...
 */
function postQty(string $key, float $default = 0): float
{
    $v = $_POST[$key] ?? null;
    if ($v === null || $v === '') return $default;
    $n = round((float) $v, 3);
    return $n >= 0 ? $n : $default;
}

/** Hiển thị số lượng đẹp: bỏ số 0 thừa ở cuối (1 thay vì 1.000, 0.5 thay vì 0.500). */
function fmtQty($value): string
{
    $n = round((float) $value, 3);
    if ($n == (int) $n) return (string) (int) $n;
    return rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.');
}

/** Chi nhánh đang bán hàng trong phiên POS hiện tại — mặc định là chi nhánh gán cho tài khoản,
 * nhưng ADMIN/MANAGER có thể tạm đổi sang chi nhánh khác qua nút "Đổi chi nhánh" trong POS. */
function effectiveBranchId(array $user): int
{
    if (in_array($user['role'], ['ADMIN', 'MANAGER'], true) && !empty($_SESSION['pos_branch_id'])) {
        // Lop bao ve thu hai: xac nhan lai chi nhanh dang chon trong phien thuc su thuoc tenant
        // hien tai. Can thiet ngay ca khi pos_switch_branch.php da kiem tra, vi phien dang nhap
        // cu (tao truoc khi va loi) co the con giu branch_id cua tenant khac. Chi kiem tra 1 lan
        // moi request nho cache tinh - chi phi them toi da 1 cau SELECT theo khoa chinh.
        static $verified = null;
        $sessionBranchId = (int) $_SESSION['pos_branch_id'];
        if ($verified === null || $verified['id'] !== $sessionBranchId) {
            $stmt = db()->prepare('SELECT id FROM branches WHERE id = ? AND tenant_id = ?');
            $stmt->execute([$sessionBranchId, (int) ($user['tenant_id'] ?? 0)]);
            $verified = ['id' => $sessionBranchId, 'ok' => (bool) $stmt->fetchColumn()];
        }
        if ($verified['ok']) {
            return $sessionBranchId;
        }
        unset($_SESSION['pos_branch_id']);
    }
    return (int) ($user['branch_id'] ?? 0);
}

/** Tenant (công ty/cửa hàng) của tài khoản đang đăng nhập — dùng để lọc mọi bảng "gốc"
 * (sản phẩm, khách hàng, NCC, danh mục...) sao cho các tenant khác nhau không thấy dữ liệu
 * của nhau. Bắt buộc phải có, vì mọi user đều thuộc đúng 1 tenant kể từ khi migrate. */
function currentTenantId(): int
{
    return (int) (currentUser()['tenant_id'] ?? 0);
}

/**
 * VAI TRO TUY CHINH (thu kho, thu quy, nhan vien ban hang...) - cho phep 1 tai khoan CASHIER
 * duoc cap them quyen thao tac (khong chi xem) tren MOT SO khu vuc chuc nang cu the, thay vi
 * phai nang len MANAGER (duoc toan bo moi khu vuc).
 *
 * Co che: moi khu vuc chuc nang ("nhom quyen") tuong ung voi 1 tap file PHP cu the - dung file
 * nao dang bi chan boi requireRole('ADMIN','MANAGER')/hasRole('ADMIN','MANAGER') hien co, dong
 * bo voi cac nhom da hien thi trong menu ($navGroups o inc_header.php). Khi 1 CASHIER co
 * custom_role_id tro toi vai tro co nhom do, hasRole() se coi nhu ho du dieu kien 'MANAGER'
 * NHUNG CHI KHI DANG DUNG DUNG FILE THUOC NHOM DO - xem detectPagePermissionGroup() va phan mo
 * rong trong hasRole() o inc_auth.php.
 *
 * CO Y KHONG dua vao day: users.php (quan ly nhan vien/phan quyen), settings.php, backup.php,
 * super_admin_tenants.php, tenant_export.php, gia_han.php, custom_roles.php - day la nhung dac
 * quyen chi ADMIN moi co (requireRole('ADMIN') dung 1 minh, khong co 'MANAGER'), nen khong nam
 * trong pham vi ma vai tro tuy chinh co the cham toi - tranh 1 "thu kho" tu tao duoc tai khoan
 * ADMIN moi hoac tu nang quyen cho chinh minh.
 */
const PERMISSION_GROUPS = [
    'pos' => 'Bán hàng & vận chuyển',
    'products' => 'Sản phẩm, kho, nhập hàng & nhà cung cấp',
    'customers' => 'Nhóm khách hàng & hạng thành viên',
    'marketing' => 'Marketing & khuyến mại',
    'warranty' => 'Bảo hành',
    'finance' => 'Sổ quỹ, báo cáo & kế toán',
    'hr' => 'Chấm công & bảng lương',
];

const PERMISSION_GROUP_FILES = [
    'pos' => [
        'pos.php', 'pos_checkout.php', 'pos_switch_branch.php', 'orders.php', 'orders_export.php',
        'order_view.php', 'order_edit.php', 'order_advance.php', 'order_cancel.php', 'order_revert.php',
        'order_pay.php', 'order_returns.php', 'order_return_form.php', 'shipments.php',
        'shipment_form.php', 'shipment_reconcile.php', 'shipping_settings.php', 'channels.php',
    ],
    'products' => [
        'inventory.php', 'inventory_save.php', 'inventory_import.php', 'batches.php',
        'categories.php', 'brands.php', 'product_form.php', 'product_copy.php',
        'product_image_upload.php', 'product_image_delete.php', 'variant_save.php',
        'variant_update.php', 'variant_delete.php', 'combo_item_save.php', 'combo_item_delete.php',
        'products.php', 'products_export.php', 'products_import.php', 'price_lists.php',
        'price_adjustments.php', 'price_adjustment_search.php', 'reorder_suggestions.php', 'purchase_orders.php',
        'purchase_order_form.php', 'purchase_order_view.php', 'purchase_order_receive.php',
        'purchase_order_search.php', 'stock_receipts.php', 'stock_receipt_form.php',
        'stock_receipt_view.php', 'stock_receipt_pay.php', 'stock_receipt_search.php',
        'stock_takes.php', 'stock_take_form.php', 'stock_take_view.php', 'stock_take_balance.php',
        'stock_take_search.php', 'stock_transfers.php', 'stock_transfer_form.php',
        'stock_transfer_view.php', 'stock_transfer_receive.php', 'stock_transfer_search.php',
        'suppliers.php', 'supplier_view.php', 'supplier_returns.php', 'supplier_return_form.php',
    ],
    'customers' => ['customers_export.php', 'customers_import.php', 'groups.php', 'customer_tiers.php'],
    'marketing' => [
        'campaigns.php', 'promotions.php', 'promotion_toggle.php', 'coupons.php', 'coupon_toggle.php',
        'gifts.php', 'marketing_settings.php', 'online_shop_settings.php',
    ],
    'warranty' => ['warranty_claim_view.php', 'warranty_policies.php'],
    'finance' => ['accounting.php', 'cashbook.php', 'cashbook_export.php', 'reports.php'],
    'hr' => ['attendance.php', 'payroll.php', 'work_schedules.php'],
];

/** File hien tai (script duoc trinh duyet goi truc tiep) thuoc nhom quyen nao, null neu khong
 * nam trong nhom nao (nghia la file do van chi ADMIN/MANAGER "that" moi vao duoc, khong the
 * giao cho vai tro tuy chinh - mac dinh an toan). */
function detectPagePermissionGroup(): ?string
{
    $file = basename($_SERVER['SCRIPT_NAME'] ?? '');
    foreach (PERMISSION_GROUP_FILES as $group => $files) {
        if (in_array($file, $files, true)) {
            return $group;
        }
    }
    return null;
}

/** Danh sach nhom quyen cua vai tro tuy chinh dang gan cho user hien tai (rong neu khong co
 * vai tro tuy chinh, hoac user la ADMIN/MANAGER that - nhung nguoi do da co toan quyen roi,
 * khong can tra qua bang custom_roles). */
function currentUserPermissionGroups(): array
{
    $user = currentUser();
    if (!$user || empty($user['custom_role_id'])) {
        return [];
    }
    static $cache = [];
    $roleId = (int) $user['custom_role_id'];
    if (!array_key_exists($roleId, $cache)) {
        $stmt = db()->prepare('SELECT permissions FROM custom_roles WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$roleId, currentTenantId()]);
        $perms = $stmt->fetchColumn();
        $cache[$roleId] = $perms ? array_filter(explode(',', $perms)) : [];
    }
    return $cache[$roleId];
}

/** User hien tai co quyen thao tac tren nhom $group hay khong - qua vai tro tuy chinh. */
function userHasPermissionGroup(string $group): bool
{
    return in_array($group, currentUserPermissionGroups(), true);
}

/** ADMIN/MANAGER "that" (khong qua vai tro tuy chinh) - doc lap voi trang dang chay, khac voi
 * hasRole('ADMIN','MANAGER') von phu thuoc SCRIPT_NAME hien tai. Dung khi can duyet NHIEU nhom
 * quyen cung luc (vd dung menu inc_header.php), khong dung duoc cho tung trang rieng le. */
function isManagerTier(): bool
{
    $user = currentUser();
    return $user !== null && in_array($user['role'], ['ADMIN', 'MANAGER'], true);
}

/** Làm tối 1 màu hex đi $percent% (dùng cho trạng thái hover của màu chủ đạo tùy chỉnh). */
function darkenColor(string $hex, int $percent = 15): string
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) !== 6) return '#1d4ed8';
    [$r, $g, $b] = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    $factor = 1 - $percent / 100;
    $r = max(0, (int) round($r * $factor));
    $g = max(0, (int) round($g * $factor));
    $b = max(0, (int) round($b * $factor));
    return sprintf('#%02x%02x%02x', $r, $g, $b);
}

/** Ghi 1 dòng nhật ký hoạt động (Cấu hình > Nhật ký hoạt động). */
function logActivity(string $action, string $detail = ''): void
{
    $user = currentUser();
    db()->prepare('INSERT INTO activity_logs (user_id, user_name, action, detail, tenant_id) VALUES (?,?,?,?,?)')
        ->execute([$user['id'] ?? null, $user['name'] ?? 'Hệ thống', $action, $detail, $user['tenant_id'] ?? null]);
}

/** Đọc 1 giá trị cấu hình chung của cửa hàng (bảng store_settings, dạng key-value, theo tenant). */
function getSetting(string $key, string $default = ''): string
{
    static $cache = [];
    // Trang đặt hàng online công khai (shop.php) không có phiên đăng nhập nên không biết
    // tenant nào — tạm mặc định về tenant #1 (chủ sở hữu) cho tới khi trang shop hỗ trợ multi-
    // tenant thật (vd theo subdomain riêng từng cửa hàng).
    $tenantId = currentTenantId() ?: 1;
    if (!isset($cache[$tenantId])) {
        $cache[$tenantId] = [];
        $stmt = db()->prepare('SELECT setting_key, setting_value FROM store_settings WHERE tenant_id = ?');
        $stmt->execute([$tenantId]);
        foreach ($stmt->fetchAll() as $row) {
            $cache[$tenantId][$row['setting_key']] = $row['setting_value'];
        }
    }
    return $cache[$tenantId][$key] ?? $default;
}

/** Ghi 1 giá trị cấu hình cho tenant hiện tại (bảng store_settings, PRIMARY KEY (tenant_id, setting_key)). */
function setSetting(string $key, string $value): void
{
    db()->prepare(
        'INSERT INTO store_settings (tenant_id, setting_key, setting_value) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    )->execute([currentTenantId(), $key, $value]);
}

/**
 * Hop "Dieu kien de dung duoc tinh nang nay" - hien ngay tren trang cua tinh nang do.
 *
 * Vi sao can: mot so tinh nang chi la KHUNG NOI BO, muon chay that thi khach phai co thu gi do o
 * ben ngoai (hop dong voi hang van chuyen, tai khoan hoa don dien tu, brandname SMS...). Neu
 * khong noi ro ngay tai cho, khach se tuong da dung duoc roi va chi phat hien khi can gap.
 *
 * @param string   $lamDuocGi Phan mem HIEN TAI lam duoc gi (noi truoc, de khong bi hieu la "chua co gi")
 * @param string[] $dieuKien  Nhung thu khach phai co/phai lam de dung that
 * @param string   $ghiChu    Dong ket, tuy chon
 */
function hopDieuKienTrienKhai(string $lamDuocGi, array $dieuKien, string $ghiChu = ''): void
{
    ?>
    <div class="alert alert-warning" style="max-width:760px;">
      <div style="font-weight:600;margin-bottom:6px;">Điều kiện để dùng được tính năng này</div>
      <p style="margin:0 0 8px;"><?= $lamDuocGi ?></p>
      <?php if ($dieuKien): ?>
        <p style="margin:0 0 4px;">Để chạy thật, bạn cần:</p>
        <ol style="margin:0;padding-left:20px;">
          <?php foreach ($dieuKien as $dk): ?><li><?= $dk ?></li><?php endforeach; ?>
        </ol>
      <?php endif; ?>
      <?php if ($ghiChu !== ''): ?>
        <p style="margin:8px 0 0;"><?= $ghiChu ?></p>
      <?php endif; ?>
    </div>
    <?php
}

/** Tự động tạo phiếu bảo hành cho các dòng sản phẩm có bật has_warranty trong 1 đơn hàng đã hoàn thành. */
function createWarrantyCardsForOrder(int $orderId, ?int $customerId, int $createdById): void
{
    $pdo = db();
    $items = $pdo->prepare(
        'SELECT oi.id AS order_item_id, oi.product_id, p.has_warranty, p.warranty_policy_id
         FROM order_items oi JOIN products p ON p.id = oi.product_id
         WHERE oi.order_id = ? AND p.has_warranty = 1'
    );
    $items->execute([$orderId]);

    $warrantyStmt = $pdo->prepare(
        'INSERT INTO warranty_cards (code, order_item_id, product_id, customer_id, policy_id, start_date, end_date, created_by_id) VALUES (?,?,?,?,?,?,?,?)'
    );
    foreach ($items->fetchAll() as $it) {
        $duration = 12;
        if ($it['warranty_policy_id']) {
            $durStmt = $pdo->prepare('SELECT duration_months FROM warranty_policies WHERE id = ?');
            $durStmt->execute([$it['warranty_policy_id']]);
            $duration = (int) ($durStmt->fetchColumn() ?: 12);
        }
        $wCode = 'WR' . strtoupper(base_convert((string) (microtime(true) * 1000 + $it['order_item_id']), 10, 36));
        $wStart = date('Y-m-d');
        $wEnd = date('Y-m-d', strtotime("+$duration months"));
        $warrantyStmt->execute([$wCode, $it['order_item_id'], $it['product_id'], $customerId, $it['warranty_policy_id'], $wStart, $wEnd, $createdById]);
    }
}

/** Tự động ghi 1 phiếu thu/chi vào Sổ quỹ khi có dòng tiền thật phát sinh (bán hàng, thu nợ, trả NCC...). */
function recordCashbookEntry(
    int $branchId,
    string $type,
    float $amount,
    string $reason,
    string $paymentMethod,
    int $createdById,
    ?int $orderId = null,
    ?int $receiptId = null
): void {
    if ($amount <= 0 || !$branchId) {
        return;
    }
    $prefix = $type === 'RECEIPT' ? 'PT' : 'PC';
    $code = genCode($prefix);
    db()->prepare(
        'INSERT INTO cashbook_entries (code, branch_id, type, amount, reason, payment_method, created_by_id, order_id, receipt_id, auto_generated) VALUES (?,?,?,?,?,?,?,?,?,1)'
    )->execute([$code, $branchId, $type, $amount, $reason, $paymentMethod, $createdById, $orderId, $receiptId]);
}

const RATE_LIMIT_MAX = 5;
const RATE_LIMIT_LOCK_SECONDS = 15 * 60;

function clientIp(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

/**
 * Số giây còn lại bị khóa cho 1 hành động (login/forgot_password) theo IP hiện tại — 0 nếu
 * không bị khóa. Lưu ở DB (không phải session) để không thể bypass bằng cách xóa cookie.
 */
function rateLimitSecondsLeft(string $action): int
{
    $stmt = db()->prepare('SELECT locked_until FROM login_attempts WHERE ip_addr = ? AND action = ?');
    $stmt->execute([clientIp(), $action]);
    $lockedUntil = $stmt->fetchColumn();
    if (!$lockedUntil || strtotime($lockedUntil) <= time()) {
        return 0;
    }
    return strtotime($lockedUntil) - time();
}

/** Ghi 1 lần thất bại cho hành động theo IP hiện tại, tự khóa RATE_LIMIT_LOCK_SECONDS khi đạt RATE_LIMIT_MAX lần. */
function rateLimitRecordFailure(string $action): void
{
    $ip = clientIp();
    $stmt = db()->prepare('SELECT attempt_count FROM login_attempts WHERE ip_addr = ? AND action = ?');
    $stmt->execute([$ip, $action]);
    $count = (int) $stmt->fetchColumn() + 1;

    if ($count >= RATE_LIMIT_MAX) {
        $lockedUntil = date('Y-m-d H:i:s', time() + RATE_LIMIT_LOCK_SECONDS);
        db()->prepare(
            'INSERT INTO login_attempts (ip_addr, action, attempt_count, locked_until) VALUES (?,?,0,?)
             ON DUPLICATE KEY UPDATE attempt_count = 0, locked_until = VALUES(locked_until)'
        )->execute([$ip, $action, $lockedUntil]);
    } else {
        db()->prepare(
            'INSERT INTO login_attempts (ip_addr, action, attempt_count, locked_until) VALUES (?,?,?,NULL)
             ON DUPLICATE KEY UPDATE attempt_count = VALUES(attempt_count), locked_until = NULL'
        )->execute([$ip, $action, $count]);
    }
}

/** Xóa bộ đếm thất bại khi thành công (đăng nhập đúng...). */
function rateLimitReset(string $action): void
{
    db()->prepare('DELETE FROM login_attempts WHERE ip_addr = ? AND action = ?')->execute([clientIp(), $action]);
}

// ============================================================================
// CONG KIEM TRA DUNG CHUNG: lay 1 ban ghi theo ID va BAT BUOC thuoc tenant hien tai.
//
// Vi sao can: 3 vong ra soat lien tiep deu tim ra cung 1 loai loi - nhan ID tu nguoi dung
// (POST/GET) roi truy van "WHERE id = ?" ma quen kiem tra ban ghi do thuoc cua hang nao. Hau
// qua that da tai hien duoc tren production: bom don hang gia vao so sach cua hang khac, xoa
// cong no NCC cua ho, ep nhan don dat hang cua ho. Moi lan vet 1 cho thi lan sau lai sot cho
// khac, vi viec kiem tra duoc viet lai thu cong o tung file.
//
// Tu gio MOI cho nhan ID tu nguoi dung deu nen di qua cac ham duoi day thay vi tu viet query -
// chi can nho "layDonHangCuaToi()" thay vi nho phai JOIN branches va so tenant_id.
//
// Cach dung:
//     $order = layDonHangCuaToi($orderId);
//     if (!$order) { redirect('orders.php'); }   // khong ton tai HOAC khong phai cua minh
// ============================================================================

/**
 * Lay 1 ban ghi theo ID, chi tra ve neu ban ghi do thuoc tenant dang dang nhap - nguoc lai
 * tra ve null (khong phan biet "khong ton tai" va "cua nguoi khac", tranh de lo su ton tai
 * cua du lieu tenant khac).
 *
 * $table chi duoc nhan gia tri trong danh sach co dinh ben duoi, khong bao gio ghep truc tiep
 * tu dau vao nguoi dung.
 */
function layBanGhiCuaToi(string $table, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    // Moi bang noi ve tenant theo 1 duong khac nhau - khai bao tap trung o day de khong con
    // phai nho tung truong hop khi viet code moi.
    $scopes = [
        // Bang co cot tenant_id truc tiep
        'branches' => "SELECT t.* FROM branches t WHERE t.id = ? AND t.tenant_id = ?",
        'products' => "SELECT t.* FROM products t WHERE t.id = ? AND t.tenant_id = ?",
        'customers' => "SELECT t.* FROM customers t WHERE t.id = ? AND t.tenant_id = ?",
        'suppliers' => "SELECT t.* FROM suppliers t WHERE t.id = ? AND t.tenant_id = ?",
        'users' => "SELECT t.* FROM users t WHERE t.id = ? AND t.tenant_id = ?",
        'coupons' => "SELECT t.* FROM coupons t WHERE t.id = ? AND t.tenant_id = ?",
        'gifts' => "SELECT t.* FROM gifts t WHERE t.id = ? AND t.tenant_id = ?",
        'promotions' => "SELECT t.* FROM promotions t WHERE t.id = ? AND t.tenant_id = ?",
        'price_lists' => "SELECT t.* FROM price_lists t WHERE t.id = ? AND t.tenant_id = ?",
        'categories' => "SELECT t.* FROM categories t WHERE t.id = ? AND t.tenant_id = ?",
        'brands' => "SELECT t.* FROM brands t WHERE t.id = ? AND t.tenant_id = ?",
        'warranty_policies' => "SELECT t.* FROM warranty_policies t WHERE t.id = ? AND t.tenant_id = ?",

        // Bang ke thua tenant qua chi nhanh
        'orders' => "SELECT t.* FROM orders t JOIN branches b ON b.id = t.branch_id WHERE t.id = ? AND b.tenant_id = ?",
        'stock_receipts' => "SELECT t.* FROM stock_receipts t JOIN branches b ON b.id = t.branch_id WHERE t.id = ? AND b.tenant_id = ?",
        'purchase_orders' => "SELECT t.* FROM purchase_orders t JOIN branches b ON b.id = t.branch_id WHERE t.id = ? AND b.tenant_id = ?",
        'stock_takes' => "SELECT t.* FROM stock_takes t JOIN branches b ON b.id = t.branch_id WHERE t.id = ? AND b.tenant_id = ?",
        'cashbook_entries' => "SELECT t.* FROM cashbook_entries t JOIN branches b ON b.id = t.branch_id WHERE t.id = ? AND b.tenant_id = ?",
        'inventory' => "SELECT t.* FROM inventory t JOIN branches b ON b.id = t.branch_id WHERE t.id = ? AND b.tenant_id = ?",
        // Chuyen hang: kiem tra theo chi nhanh GUI (chi nhanh nhan cung phai cung tenant vi
        // form chi cho chon trong danh sach chi nhanh cua chinh tenant do)
        'stock_transfers' => "SELECT t.* FROM stock_transfers t JOIN branches b ON b.id = t.from_branch_id WHERE t.id = ? AND b.tenant_id = ?",

        // Bang ke thua qua don hang
        'shipments' => "SELECT t.* FROM shipments t JOIN orders o ON o.id = t.order_id JOIN branches b ON b.id = o.branch_id WHERE t.id = ? AND b.tenant_id = ?",
        'order_returns' => "SELECT t.* FROM order_returns t JOIN orders o ON o.id = t.order_id JOIN branches b ON b.id = o.branch_id WHERE t.id = ? AND b.tenant_id = ?",
    ];

    if (!isset($scopes[$table])) {
        // Sai ten bang la loi lap trinh, khong phai loi nguoi dung - bao that to thay vi am tham
        // tra ve null (de khong vo tinh tao ra 1 cho "luon khong tim thay" ma khong ai biet).
        throw new InvalidArgumentException("layBanGhiCuaToi(): chua khai bao cach xac dinh tenant cho bang \"$table\"");
    }

    $stmt = db()->prepare($scopes[$table]);
    $stmt->execute([$id, currentTenantId()]);
    return $stmt->fetch() ?: null;
}

/** Cac ham goi tat cho de doc tai noi su dung. */
function layDonHangCuaToi(int $id): ?array { return layBanGhiCuaToi('orders', $id); }
function layPhieuNhapCuaToi(int $id): ?array { return layBanGhiCuaToi('stock_receipts', $id); }
function layDonDatHangCuaToi(int $id): ?array { return layBanGhiCuaToi('purchase_orders', $id); }
function layPhieuKiemHangCuaToi(int $id): ?array { return layBanGhiCuaToi('stock_takes', $id); }
function layPhieuChuyenHangCuaToi(int $id): ?array { return layBanGhiCuaToi('stock_transfers', $id); }
function laySanPhamCuaToi(int $id): ?array { return layBanGhiCuaToi('products', $id); }
function layKhachHangCuaToi(int $id): ?array { return layBanGhiCuaToi('customers', $id); }
function layNhaCungCapCuaToi(int $id): ?array { return layBanGhiCuaToi('suppliers', $id); }
function layChiNhanhCuaToi(int $id): ?array { return layBanGhiCuaToi('branches', $id); }
