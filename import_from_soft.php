<?php
/**
 * Nhap du lieu NEN TANG (danh muc, nha cung cap, nhom khach hang, khach hang, hang hoa + ton
 * kho) tu file JSON do QLBH-SOFT xuat ra (menu Quan ly > Chuyen du lieu len QLBH-CLOUD).
 *
 * Day la 1 phia cua "cau noi QLBH-SOFT -> QLBH-CLOUD": ban mien phi (desktop, chay offline)
 * lam PHEU KHACH HANG cho ban tra phi (cloud) - khach dang dung QLBH-SOFT on dinh, muon mo
 * them chi nhanh 2 quan ly qua trinh duyet thi khong phai nhap tay lai tu dau. Neu khong co
 * cau noi nay, day chinh la luc ho de rơi vao KiotViet nhat.
 *
 * AN TOAN LA UU TIEN SO 1 (day la thao tac chi lam 1-2 lan, sai thi kho sua): CHI THEM MOI,
 * KHONG BAO GIO SUA/XOA du lieu da co. Trung ten/SDT/SKU thi BO QUA dong do va bao lai trong
 * ket qua, khong ghi de "vi lo" du lieu that dang chay.
 *
 * Chi ADMIN duoc vao (khong phai ADMIN+MANAGER): thao tac ghi thang vao gia von/gia ban/danh
 * muc hang loat, dung mau requireRole('ADMIN') nhu settings.php/users.php - va PHAI xu ly xong
 * POST TRUOC khi require inc_header.php (header da tu in HTML ra ngay khi include), dung mau
 * moi trang khac trong du an - include header som se lam mat quyen han che nay.
 */
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireRole('ADMIN');

const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;

$pdo = db();
$tenantId = currentTenantId();
$branchesStmt = $pdo->prepare('SELECT * FROM branches WHERE tenant_id = ? AND is_active = 1 ORDER BY name');
$branchesStmt->execute([$tenantId]);
$branches = $branchesStmt->fetchAll();

$error = null;
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $branchId = (int) ($_POST['branch_id'] ?? 0);
    if ($branchId && !in_array($branchId, array_map('intval', array_column($branches, 'id')), true)) {
        $branchId = 0;
    }

    if (!$branchId) {
        $error = 'Vui lòng chọn chi nhánh để nhận tồn kho.';
    } elseif (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Vui lòng chọn file JSON đã xuất từ QLBH-SOFT.';
    } elseif ($_FILES['file']['size'] > MAX_UPLOAD_BYTES) {
        $error = 'File quá lớn (tối đa 5MB).';
    } else {
        $raw = file_get_contents($_FILES['file']['tmp_name']);
        $data = json_decode($raw, true);

        if (!is_array($data) || ($data['nguon'] ?? '') !== 'QLBH-SOFT' || !isset($data['san_pham'])) {
            $error = 'File không đúng định dạng — hãy dùng đúng file xuất từ QLBH-SOFT (menu Quản lý → Chuyển dữ liệu lên QLBH-CLOUD).';
        } else {
            $result = [
                'danh_muc' => ['them' => 0, 'bo_qua' => 0],
                'nha_cung_cap' => ['them' => 0, 'bo_qua' => 0],
                'nhom_khach_hang' => ['them' => 0, 'bo_qua' => 0],
                'khach_hang' => ['them' => 0, 'bo_qua' => 0],
                'san_pham' => ['them' => 0, 'bo_qua' => 0],
                'chi_tiet_bo_qua' => [],
            ];

            // Danh muc: bo qua neu ten da ton tai trong tenant (khong phan biet hoa/thuong).
            $catMap = []; // ten (lowercase) -> id, dung de gan danh_muc_id cho san pham ben duoi
            $existingCats = $pdo->prepare('SELECT id, name FROM categories WHERE tenant_id = ?');
            $existingCats->execute([$tenantId]);
            foreach ($existingCats->fetchAll() as $c) {
                $catMap[mb_strtolower($c['name'])] = (int) $c['id'];
            }
            foreach ((array) ($data['danh_muc'] ?? []) as $c) {
                $ten = trim((string) ($c['ten'] ?? ''));
                if ($ten === '') continue;
                $key = mb_strtolower($ten);
                if (isset($catMap[$key])) {
                    $result['danh_muc']['bo_qua']++;
                    continue;
                }
                $pdo->prepare('INSERT INTO categories (name, tenant_id) VALUES (?, ?)')->execute([$ten, $tenantId]);
                $catMap[$key] = (int) $pdo->lastInsertId();
                $result['danh_muc']['them']++;
            }

            // Nha cung cap: khong co cot UNIQUE nao rieng - coi trung khi TRUNG TEN (khong phan
            // biet hoa/thuong), du that ra nhieu NCC trung ten van co the la 2 don vi khac nhau -
            // chap nhan danh doi nay de tranh tao trung hang loat khi nhap file nhieu lan.
            $existingSup = $pdo->prepare('SELECT name FROM suppliers WHERE tenant_id = ?');
            $existingSup->execute([$tenantId]);
            $supNames = array_map('mb_strtolower', array_column($existingSup->fetchAll(), 'name'));
            foreach ((array) ($data['nha_cung_cap'] ?? []) as $s) {
                $ten = trim((string) ($s['ten'] ?? ''));
                if ($ten === '') continue;
                if (in_array(mb_strtolower($ten), $supNames, true)) {
                    $result['nha_cung_cap']['bo_qua']++;
                    continue;
                }
                $pdo->prepare('INSERT INTO suppliers (name, phone, address, tenant_id) VALUES (?, ?, ?, ?)')
                    ->execute([$ten, $s['sdt'] ?? null, $s['dia_chi'] ?? null, $tenantId]);
                $supNames[] = mb_strtolower($ten);
                $result['nha_cung_cap']['them']++;
            }

            // Nhom khach hang: UNIQUE(tenant_id, name) that su o DB - bo qua neu trung.
            $groupMap = [];
            $existingGroups = $pdo->prepare('SELECT id, name FROM customer_groups WHERE tenant_id = ?');
            $existingGroups->execute([$tenantId]);
            foreach ($existingGroups->fetchAll() as $g) {
                $groupMap[mb_strtolower($g['name'])] = (int) $g['id'];
            }
            foreach ((array) ($data['nhom_khach_hang'] ?? []) as $g) {
                $ten = trim((string) ($g['ten'] ?? ''));
                if ($ten === '') continue;
                $key = mb_strtolower($ten);
                if (isset($groupMap[$key])) {
                    $result['nhom_khach_hang']['bo_qua']++;
                    continue;
                }
                $pdo->prepare('INSERT INTO customer_groups (name, tenant_id) VALUES (?, ?)')->execute([$ten, $tenantId]);
                $groupMap[$key] = (int) $pdo->lastInsertId();
                $result['nhom_khach_hang']['them']++;
            }

            // Khach hang: trung SDT (da co UNIQUE that o DB theo tenant) thi bo qua - KHONG ghi
            // de cong no/diem tich luy cua khach da co san tren cloud (co the ho da phat sinh
            // giao dich rieng tren do roi).
            $existingPhones = $pdo->prepare("SELECT phone FROM customers WHERE tenant_id = ? AND phone IS NOT NULL AND phone != ''");
            $existingPhones->execute([$tenantId]);
            $phoneSet = array_flip(array_column($existingPhones->fetchAll(), 'phone'));
            foreach ((array) ($data['khach_hang'] ?? []) as $kh) {
                $ten = trim((string) ($kh['ten'] ?? ''));
                $sdt = trim((string) ($kh['sdt'] ?? '')) ?: null;
                if ($ten === '') continue;
                if ($sdt !== null && isset($phoneSet[$sdt])) {
                    $result['khach_hang']['bo_qua']++;
                    $result['chi_tiet_bo_qua'][] = "Khách hàng trùng SĐT: $ten ($sdt)";
                    continue;
                }
                $groupId = isset($kh['nhom']) ? ($groupMap[mb_strtolower(trim((string) $kh['nhom']))] ?? null) : null;
                $code = genCode('KH');
                $pdo->prepare(
                    'INSERT INTO customers (code, name, phone, address, group_id, debt, loyalty_points, tenant_id) VALUES (?,?,?,?,?,?,?,?)'
                )->execute([$code, $ten, $sdt, $kh['dia_chi'] ?? null, $groupId, (float) ($kh['cong_no'] ?? 0), (int) ($kh['diem_tich_luy'] ?? 0), $tenantId]);
                if ($sdt !== null) $phoneSet[$sdt] = true;
                $result['khach_hang']['them']++;
            }

            // Hang hoa: trung SKU HOAC trung ten (khong phan biet hoa/thuong) thi bo qua - SKU
            // cua QLBH-SOFT (vd "SP035") co the trung tinh co voi SKU tu sinh cua cua hang tren
            // cloud, va trung TEN thuong co nghia la cung 1 mat hang da duoc tao truoc do roi
            // (vd chay nhap file 2 lan).
            $existingSkus = $pdo->prepare('SELECT sku, name FROM products WHERE tenant_id = ?');
            $existingSkus->execute([$tenantId]);
            $skuSet = [];
            $nameSet = [];
            foreach ($existingSkus->fetchAll() as $p) {
                $skuSet[$p['sku']] = true;
                $nameSet[mb_strtolower($p['name'])] = true;
            }
            foreach ((array) ($data['san_pham'] ?? []) as $sp) {
                $ten = trim((string) ($sp['ten'] ?? ''));
                if ($ten === '') continue;
                $skuGoc = trim((string) ($sp['ma_sp'] ?? '')) ?: genCode('SP');
                if (isset($skuSet[$skuGoc]) || isset($nameSet[mb_strtolower($ten)])) {
                    $result['san_pham']['bo_qua']++;
                    $result['chi_tiet_bo_qua'][] = "Hàng hóa trùng: $ten ($skuGoc)";
                    continue;
                }
                $catId = isset($sp['danh_muc']) ? ($catMap[mb_strtolower(trim((string) $sp['danh_muc']))] ?? null) : null;
                $packUnit = trim((string) ($sp['don_vi_lon'] ?? '')) ?: null;
                $packSize = $packUnit ? max(1.0, (float) ($sp['he_so_quy_doi'] ?? 1)) : 1.0;

                $pdo->prepare(
                    'INSERT INTO products (sku, barcode, name, unit, pack_unit, pack_size, cost_price, sell_price, category_id, tenant_id) VALUES (?,?,?,?,?,?,?,?,?,?)'
                )->execute([
                    $skuGoc, $sp['ma_vach'] ?? null, $ten, $sp['don_vi_tinh'] ?? null,
                    $packUnit, $packSize, (float) ($sp['gia_von'] ?? 0), (float) ($sp['gia_ban'] ?? 0),
                    $catId, $tenantId,
                ]);
                $productId = (int) $pdo->lastInsertId();
                $tonKho = (float) ($sp['ton_kho_hien_tai'] ?? 0);
                if ($tonKho > 0) {
                    $pdo->prepare('INSERT INTO inventory (branch_id, product_id, quantity, min_stock) VALUES (?,?,?,?)')
                        ->execute([$branchId, $productId, $tonKho, (float) ($sp['ton_kho_toi_thieu'] ?? 0)]);
                }
                $skuSet[$skuGoc] = true;
                $nameSet[mb_strtolower($ten)] = true;
                $result['san_pham']['them']++;
            }

            logActivity('IMPORT_FROM_SOFT', json_encode([
                'danh_muc' => $result['danh_muc'], 'nha_cung_cap' => $result['nha_cung_cap'],
                'khach_hang' => $result['khach_hang'], 'san_pham' => $result['san_pham'],
            ], JSON_UNESCAPED_UNICODE));
        }
    }
}

require_once __DIR__ . '/inc_header.php';
?>

<a href="settings.php" class="muted" style="font-size:14px;">← Cấu hình</a>
<h1 style="font-size:24px;font-weight:600;margin:8px 0 4px;">Nhập dữ liệu từ QLBH-SOFT</h1>
<p class="muted" style="margin:0 0 20px;font-size:13px;max-width:680px;">
  Đang dùng <b>QLBH-SOFT</b> (bản chạy trên máy tính) và muốn mở thêm chi nhánh quản lý qua
  trình duyệt? Xuất dữ liệu bên đó (menu <b>Quản lý → Chuyển dữ liệu lên QLBH-CLOUD</b>), rồi
  tải file <code>.json</code> lên đây.
</p>

<div class="alert alert-warning" style="max-width:680px;">
  Chỉ <b>thêm mới</b> danh mục, nhà cung cấp, nhóm khách hàng, khách hàng, hàng hóa và tồn kho —
  <b>không sửa hay xóa</b> bất kỳ dữ liệu nào đã có trên cửa hàng này. Khách hàng trùng số điện
  thoại hoặc hàng hóa trùng mã/tên sẽ <b>tự động bỏ qua</b> để không ghi đè dữ liệu thật đang
  chạy — an toàn để chạy lại nhiều lần nếu cần.
</div>

<?php if ($error): ?><div class="alert alert-error" style="max-width:680px;"><?= e($error) ?></div><?php endif; ?>

<?php if ($result): ?>
  <div class="alert alert-success" style="max-width:680px;">
    <b>Đã nhập xong.</b>
    <ul style="margin:8px 0 0;padding-left:20px;">
      <li>Danh mục: thêm <?= $result['danh_muc']['them'] ?>, bỏ qua (trùng) <?= $result['danh_muc']['bo_qua'] ?></li>
      <li>Nhà cung cấp: thêm <?= $result['nha_cung_cap']['them'] ?>, bỏ qua (trùng) <?= $result['nha_cung_cap']['bo_qua'] ?></li>
      <li>Nhóm khách hàng: thêm <?= $result['nhom_khach_hang']['them'] ?>, bỏ qua (trùng) <?= $result['nhom_khach_hang']['bo_qua'] ?></li>
      <li>Khách hàng: thêm <?= $result['khach_hang']['them'] ?>, bỏ qua (trùng SĐT) <?= $result['khach_hang']['bo_qua'] ?></li>
      <li>Hàng hóa: thêm <?= $result['san_pham']['them'] ?>, bỏ qua (trùng mã/tên) <?= $result['san_pham']['bo_qua'] ?></li>
    </ul>
    <?php if ($result['chi_tiet_bo_qua']): ?>
      <details style="margin-top:10px;">
        <summary style="cursor:pointer;font-size:13px;">Xem chi tiết các dòng bị bỏ qua (<?= count($result['chi_tiet_bo_qua']) ?>)</summary>
        <ul style="margin:6px 0 0;padding-left:20px;font-size:13px;color:#64748b;">
          <?php foreach (array_slice($result['chi_tiet_bo_qua'], 0, 50) as $line): ?>
            <li><?= e($line) ?></li>
          <?php endforeach; ?>
          <?php if (count($result['chi_tiet_bo_qua']) > 50): ?><li>… và <?= count($result['chi_tiet_bo_qua']) - 50 ?> dòng khác</li><?php endif; ?>
        </ul>
      </details>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="card" style="max-width:520px;">
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <div class="field">
      <label for="f-branch">Nhận tồn kho vào chi nhánh</label>
      <select class="input" id="f-branch" name="branch_id" required>
        <option value="">— Chọn chi nhánh —</option>
        <?php foreach ($branches as $b): ?>
          <option value="<?= (int) $b['id'] ?>"><?= e($b['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="f-file">File xuất từ QLBH-SOFT (.json)</label>
      <input class="input" type="file" id="f-file" name="file" accept=".json" required>
    </div>
    <button type="submit" class="btn">Nhập dữ liệu</button>
  </form>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
