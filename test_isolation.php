<?php
/**
 * KIEM THU TU DONG: cach ly du lieu giua cac cua hang (tenant).
 *
 * Vi sao co file nay: 3 vong ra soat lien tiep deu tim ra cung 1 loai loi - nhan ID tu nguoi dung
 * roi thao tac ma quen kiem tra ban ghi thuoc cua hang nao. Hau qua that da tai hien duoc: bom don
 * hang gia vao so sach cua hang khac, xoa cong no NCC cua ho, ep nhan don dat hang cua ho. Ra soat
 * tay bat duoc nhung chi bat duoc nhung gi nguoi ra soat NGHI RA. File nay bat tu dong, va bat ca
 * nhung cho phat sinh ve sau.
 *
 * Cach chay: mo https://app.kt-soft.vn/test_isolation.php?key=<TEST_SECRET>
 * Nen chay truoc moi lan deploy thay doi lien quan den truy van du lieu.
 *
 * Script tu tao 2 cua hang tam (A va B), dang nhap that bang HTTP nhu nguoi dung that, thu moi
 * kieu truy cap cheo, roi TU DON SACH. Chi xoa dung 2 tenant no vua tao (nho lai id ngay tu dau),
 * khong bao gio dung toi du lieu khach hang that.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inc_functions.php';

header('Content-Type: text/plain; charset=utf-8');

if (!defined('TEST_SECRET') || TEST_SECRET === '' || !hash_equals(TEST_SECRET, $_GET['key'] ?? '')) {
    http_response_code(403);
    exit("Forbidden. Can ?key=<TEST_SECRET> (khai bao trong config.php).\n");
}

$baseUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'app.kt-soft.vn');
$pdo = db();
$marker = 'isolationtest_' . bin2hex(random_bytes(4));
$results = [];
$createdTenantIds = [];

function ok(string $name, bool $pass, string $detail = ''): void
{
    global $results;
    $results[] = ['name' => $name, 'pass' => $pass, 'detail' => $detail];
}

/** Tao 1 cua hang tam kem du lieu mau day du de thu moi kieu truy cap. */
function taoCuaHangTam(PDO $pdo, string $nhan, string $marker): array
{
    $email = "$nhan.$marker@example.invalid";
    $pdo->prepare("INSERT INTO tenants (name, owner_email, plan, trial_ends_at) VALUES (?, ?, 'TRIAL', DATE_ADD(NOW(), INTERVAL 1 DAY))")
        ->execute(["KIEMTHU-$nhan", $email]);
    $t = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO branches (tenant_id, name) VALUES (?, ?)")->execute([$t, "CN $nhan"]);
    $branch = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO users (tenant_id, branch_id, name, email, password_hash, role, is_active) VALUES (?, ?, ?, ?, ?, 'ADMIN', 1)")
        ->execute([$t, $branch, "Chu $nhan", $email, password_hash('KiemThu@12345', PASSWORD_DEFAULT)]);
    $user = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO products (tenant_id, sku, name, unit, cost_price, sell_price, is_active) VALUES (?, ?, ?, 'Cai', 5000, 10000, 1)")
        ->execute([$t, "SKU-$nhan-$marker", "San pham $nhan"]);
    $product = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO inventory (branch_id, product_id, quantity) VALUES (?, ?, 100)")->execute([$branch, $product]);

    $pdo->prepare("INSERT INTO customers (tenant_id, code, name, phone, debt) VALUES (?, ?, ?, ?, 10000)")
        ->execute([$t, "KH-$nhan-$marker", "Khach $nhan", '09' . random_int(10000000, 99999999)]);
    $customer = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO suppliers (tenant_id, name, debt) VALUES (?, ?, 300000)")->execute([$t, "NCC $nhan"]);
    $supplier = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO stock_receipts (code, branch_id, supplier_id, created_by_id, total_amount, paid_amount) VALUES (?, ?, ?, ?, 300000, 0)")
        ->execute(["PN-$nhan-$marker", $branch, $supplier, $user]);
    $receipt = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO purchase_orders (code, branch_id, supplier_id, created_by_id, status) VALUES (?, ?, ?, ?, 'PENDING')")
        ->execute(["DDH-$nhan-$marker", $branch, $supplier, $user]);
    $po = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO purchase_order_items (po_id, product_id, quantity, cost_price) VALUES (?, ?, 10, 10000)")->execute([$po, $product]);

    // Ban chiu: con no 10.000 - de phep thu "thu tien ho don hang cua nguoi khac" co the gay
    // ra thay doi that neu hang rao tenant bi lot (don da tra du thi se khong the hien duoc gi).
    $pdo->prepare("INSERT INTO orders (code, branch_id, customer_id, sold_by_id, status, payment_status, sub_total, total_amount, paid_amount) VALUES (?, ?, ?, ?, 'COMPLETED', 'UNPAID', 10000, 10000, 0)")
        ->execute(["DH-$nhan-$marker", $branch, $customer, $user]);
    $order = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO order_items (order_id, product_id, quantity, unit_price, line_total, cost_price) VALUES (?, ?, 1, 10000, 10000, 5000)")
        ->execute([$order, $product]);

    $pdo->prepare("INSERT INTO stock_takes (code, branch_id, created_by_id, status) VALUES (?, ?, ?, 'DRAFT')")
        ->execute(["KH-$nhan-$marker", $branch, $user]);
    $take = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO gifts (tenant_id, name, points_required, is_active) VALUES (?, ?, 10, 1)")->execute([$t, "Qua rieng cua $nhan"]);
    $pdo->prepare("INSERT INTO promotions (tenant_id, name, min_order_amount, discount_percent, is_active) VALUES (?, ?, 0, 5, 1)")
        ->execute([$t, "Khuyen mai rieng cua $nhan"]);
    $pdo->prepare("INSERT INTO price_lists (tenant_id, name) VALUES (?, ?)")->execute([$t, "Bang gia rieng cua $nhan"]);

    return compact('t', 'branch', 'user', 'product', 'customer', 'supplier', 'receipt', 'po', 'order', 'take') + ['email' => $email];
}

/** Dang nhap that qua HTTP, tra ve duong dan file cookie de dung cho cac request sau. */
function dangNhap(string $baseUrl, string $email): ?string
{
    $jar = tempnam(sys_get_temp_dir(), 'isotest');
    $html = httpGet($baseUrl . '/login.php', $jar);
    if (!preg_match('/name="csrf" value="([a-f0-9]+)"/', $html, $m)) {
        return null;
    }
    httpPost($baseUrl . '/login.php', ['csrf' => $m[1], 'email' => $email, 'password' => 'KiemThu@12345'], $jar);
    // Xac nhan da vao duoc ben trong: mo 1 trang can dang nhap va kiem tra khong bi day ve
    // form dang nhap. Khong dua vao ma HTTP cua index.php - cua hang moi tao bi chuyen huong
    // sang trang huong dan khoi tao, van la da dang nhap thanh cong.
    $html = httpGet($baseUrl . '/orders.php', $jar);
    return str_contains($html, 'type="password"') ? null : $jar;
}

/** LiteSpeed chan user-agent mac dinh cua cURL bang 403, nen moi request phai khai bao UA that. */
const TEST_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

function httpGet(string $url, string $jar): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 20, CURLOPT_USERAGENT => TEST_UA]);
    $out = (string) curl_exec($ch);
    curl_close($ch);
    return $out;
}

function httpPost(string $url, array $fields, string $jar): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields), CURLOPT_TIMEOUT => 20, CURLOPT_USERAGENT => TEST_UA]);
    $out = (string) curl_exec($ch);
    curl_close($ch);
    return $out;
}

/** Lay token CSRF tu 1 trang bat ky (de gui POST hop le - phai chan boi kiem tra tenant, khong phai boi CSRF). */
function layCsrf(string $baseUrl, string $path, string $jar): string
{
    $html = httpGet($baseUrl . '/' . $path, $jar);
    return preg_match('/name="csrf" value="([a-f0-9]+)"/', $html, $m) ? $m[1] : '';
}

// ============================================================================
echo "KIEM THU CACH LY DU LIEU GIUA CAC CUA HANG\n";
echo str_repeat('=', 78) . "\n";
echo "Marker: $marker\n\n";

try {
    $A = taoCuaHangTam($pdo, 'A', $marker);
    $B = taoCuaHangTam($pdo, 'B', $marker);
    $createdTenantIds = [$A['t'], $B['t']];
    echo "Da tao 2 cua hang tam: A=#{$A['t']}, B=#{$B['t']}\n\n";

    $jarA = dangNhap($baseUrl, $A['email']);
    if (!$jarA) {
        throw new RuntimeException('Khong dang nhap duoc bang tai khoan tam (kiem tra ket noi HTTP noi bo).');
    }
    $csrf = layCsrf($baseUrl, 'orders.php', $jarA);
    if ($csrf === '') {
        $csrf = layCsrf($baseUrl, 'pos.php', $jarA);
    }

    // ---- NHOM 1: cac thao tac GHI len du lieu cua cua hang B ----
    // Doi chieu HIEU UNG THUC TE len CSDL, khong tin ma HTTP (nhieu endpoint luon tra 302).

    httpPost($baseUrl . '/pos_switch_branch.php', ['csrf' => $csrf, 'branch_id' => $B['branch']], $jarA);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM orders o JOIN branches b ON b.id = o.branch_id WHERE b.tenant_id = ?");
    // Chuyen chi nhanh xong thi ban thu 1 don - neu lot, don se roi vao so sach cua B
    $csrfPos = layCsrf($baseUrl, 'pos.php', $jarA);
    $stmt->execute([$B['t']]);
    $donBTruoc = (int) $stmt->fetchColumn();
    $ch = curl_init($baseUrl . '/pos_checkout.php');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jarA, CURLOPT_COOKIEFILE => $jarA,
        CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 20, CURLOPT_USERAGENT => TEST_UA,
        CURLOPT_POSTFIELDS => json_encode(['csrf' => $csrfPos, 'payment_method' => 'CASH',
            'items' => [['product_id' => $A['product'], 'variant_id' => null, 'quantity' => 1, 'unit_price' => 10000]]])]);
    curl_exec($ch);
    curl_close($ch);
    $stmt->execute([$B['t']]);
    ok('Chuyen POS sang chi nhanh cua hang khac roi ban hang', (int) $stmt->fetchColumn() === $donBTruoc,
        'so don cua B phai khong doi');

    httpPost($baseUrl . '/stock_receipt_pay.php', ['csrf' => $csrf, 'receipt_id' => $B['receipt'], 'amount' => 300000], $jarA);
    $q = $pdo->prepare("SELECT paid_amount FROM stock_receipts WHERE id = ?");
    $q->execute([$B['receipt']]);
    ok('Tra no phieu nhap cua cua hang khac', (float) $q->fetchColumn() == 0.0, 'phieu nhap cua B phai chua tra dong nao');

    $q = $pdo->prepare("SELECT debt FROM suppliers WHERE id = ?");
    $q->execute([$B['supplier']]);
    ok('Xoa cong no NCC cua cua hang khac', (float) $q->fetchColumn() == 300000.0, 'cong no NCC cua B phai giu nguyen');

    httpPost($baseUrl . '/purchase_order_receive.php', ['csrf' => $csrf, 'po_id' => $B['po']], $jarA);
    $q = $pdo->prepare("SELECT status FROM purchase_orders WHERE id = ?");
    $q->execute([$B['po']]);
    ok('Ep nhan don dat hang cua cua hang khac', $q->fetchColumn() === 'PENDING', 'don dat hang cua B phai con PENDING');

    httpPost($baseUrl . '/order_revert.php', ['csrf' => $csrf, 'order_id' => $B['order']], $jarA);
    httpPost($baseUrl . '/order_cancel.php', ['csrf' => $csrf, 'order_id' => $B['order'], 'reason' => 'test'], $jarA);
    $q = $pdo->prepare("SELECT status FROM orders WHERE id = ?");
    $q->execute([$B['order']]);
    ok('Hoan tac / huy don hang cua cua hang khac', $q->fetchColumn() === 'COMPLETED', 'don cua B phai con COMPLETED');

    httpPost($baseUrl . '/order_pay.php', ['csrf' => $csrf, 'order_id' => $B['order'], 'amount' => 50000], $jarA);
    $q = $pdo->prepare("SELECT paid_amount FROM orders WHERE id = ?");
    $q->execute([$B['order']]);
    ok('Thu them tien vao don hang cua cua hang khac', (float) $q->fetchColumn() == 0.0, 'don cua B phai chua thu dong nao');

    httpPost($baseUrl . '/stock_take_balance.php', ['csrf' => $csrf, 'take_id' => $B['take']], $jarA);
    $q = $pdo->prepare("SELECT status FROM stock_takes WHERE id = ?");
    $q->execute([$B['take']]);
    ok('Can bang phieu kiem hang cua cua hang khac', $q->fetchColumn() === 'DRAFT', 'phieu kiem hang cua B phai con DRAFT');

    // ---- NHOM 2: cac trang XEM chi tiet theo ID cua cua hang B ----
    // Moi trang xem duoc thu 2 lan: mo ban ghi cua B (phai KHONG thay) va mo ban ghi cung loai
    // cua chinh A (phai THAY). Ve trai bat ro ri; ve phai bat tinh huong nguy hiem hon nhieu:
    // trang loi/trang trang khien moi phep thu "khong thay" deu dat mot cach vo nghia.
    $xemTrang = [
        'Xem chi tiet don hang' => ['order_view.php?id=', 'order', "DH-%s-$marker"],
        'Xem chi tiet san pham' => ['product_form.php?id=', 'product', 'San pham %s'],
        'Xem chi tiet khach hang' => ['customer_view.php?id=', 'customer', 'Khach %s'],
        'Xem chi tiet NCC' => ['supplier_view.php?id=', 'supplier', 'NCC %s'],
        'Xem chi tiet phieu nhap' => ['stock_receipt_view.php?id=', 'receipt', "PN-%s-$marker"],
    ];
    foreach ($xemTrang as $ten => [$path, $khoa, $mau]) {
        $html = httpGet($baseUrl . '/' . $path . $B[$khoa], $jarA);
        ok($ten . ' cua cua hang khac', !str_contains($html, sprintf($mau, 'B')), 'khong duoc hien du lieu cua B');
        $html = httpGet($baseUrl . '/' . $path . $A[$khoa], $jarA);
        ok('[Doi chieu] ' . $ten . ' CUA CHINH MINH', str_contains($html, sprintf($mau, 'A')),
            'phai xem duoc ban ghi cua chinh minh - neu hong thi cac phep thu tren khong dang tin');
    }

    // ---- NHOM 3: cac endpoint liet ke danh sach ----
    // Tuong tu: moi danh sach vua phai vang bong du lieu cua B, vua phai co du lieu cua chinh A.
    // Cot thu 3 la null khi trang khong the hien du lieu cua A (vd nhat ky ghi ten cua hang).
    $lietKe = [
        'Danh sach qua tang (pos_gifts)' => ['pos_gifts.php', 'Qua rieng cua B', 'Qua rieng cua A'],
        'Danh sach khuyen mai (pos_promotions)' => ['pos_promotions.php', 'Khuyen mai rieng cua B', 'Khuyen mai rieng cua A'],
        'Danh sach bang gia' => ['price_lists.php', 'Bang gia rieng cua B', 'Bang gia rieng cua A'],
        'Nhat ky hoat dong' => ['activity_log.php', 'KIEMTHU-B', null],
        'Nhat ky nhap/xuat file' => ['file_logs.php', 'KIEMTHU-B', null],
        'Danh sach don hang' => ['orders.php', "DH-B-$marker", "DH-A-$marker"],
        'Danh sach san pham' => ['products.php', 'San pham B', 'San pham A'],
        'Danh sach khach hang' => ['customers.php', 'Khach B', 'Khach A'],
        'Danh sach NCC' => ['suppliers.php', 'NCC B', 'NCC A'],
        'Quan ly kho' => ['inventory.php', 'San pham B', 'San pham A'],
        'So quy' => ['cashbook.php', "DH-B-$marker", null],
    ];
    foreach ($lietKe as $ten => [$path, $canhBao, $phaiCo]) {
        $html = httpGet($baseUrl . '/' . $path, $jarA);
        ok($ten, !str_contains($html, $canhBao), 'khong duoc hien du lieu cua B');
        if ($phaiCo !== null) {
            ok('[Doi chieu] ' . $ten . ' hien du lieu CUA CHINH MINH', str_contains($html, $phaiCo),
                'phai thay du lieu cua chinh minh - neu hong thi phep thu tren khong dang tin');
        }
    }

    // ---- NHOM 4: doi chieu nguoc - thao tac tren du lieu CUA CHINH MINH phai VAN CHAY ----
    // (neu chi kiem tra "chan duoc" thi 1 ban va qua tay chan luon ca chinh chu cung se "pass")
    httpPost($baseUrl . '/stock_receipt_pay.php', ['csrf' => $csrf, 'receipt_id' => $A['receipt'], 'amount' => 50000], $jarA);
    $q = $pdo->prepare("SELECT paid_amount FROM stock_receipts WHERE id = ?");
    $q->execute([$A['receipt']]);
    ok('[Doi chieu] Tra no phieu nhap CUA CHINH MINH van chay', (float) $q->fetchColumn() == 50000.0,
        'phai ghi nhan du 50.000 - neu that bai la ban va qua tay, chan nham ca chu so huu');

    httpPost($baseUrl . '/order_pay.php', ['csrf' => $csrf, 'order_id' => $A['order'], 'amount' => 10000], $jarA);
    $q = $pdo->prepare("SELECT paid_amount FROM orders WHERE id = ?");
    $q->execute([$A['order']]);
    ok('[Doi chieu] Thu tien don hang CUA CHINH MINH van chay', (float) $q->fetchColumn() == 10000.0,
        'phai thu duoc du 10.000 cho don cua chinh minh');

    if (is_string($jarA)) {
        @unlink($jarA);
    }
} catch (Throwable $e) {
    echo "LOI KHI CHAY KIEM THU: " . $e->getMessage() . "\n\n";
} finally {
    // ---- DON SACH: chi xoa dung 2 tenant vua tao ----
    if ($createdTenantIds) {
        $ids = implode(',', array_map('intval', $createdTenantIds));
        $steps = [
            "DELETE FROM payments WHERE order_id IN (SELECT id FROM orders WHERE branch_id IN (SELECT id FROM branches WHERE tenant_id IN ($ids)))",
            "DELETE FROM order_items WHERE order_id IN (SELECT id FROM orders WHERE branch_id IN (SELECT id FROM branches WHERE tenant_id IN ($ids)))",
            "DELETE FROM order_status_history WHERE order_id IN (SELECT id FROM orders WHERE branch_id IN (SELECT id FROM branches WHERE tenant_id IN ($ids)))",
            "DELETE FROM cashbook_entries WHERE branch_id IN (SELECT id FROM branches WHERE tenant_id IN ($ids))",
            "DELETE FROM orders WHERE branch_id IN (SELECT id FROM branches WHERE tenant_id IN ($ids))",
            "DELETE FROM stock_takes WHERE branch_id IN (SELECT id FROM branches WHERE tenant_id IN ($ids))",
            "DELETE FROM stock_receipt_items WHERE receipt_id IN (SELECT id FROM stock_receipts WHERE branch_id IN (SELECT id FROM branches WHERE tenant_id IN ($ids)))",
            "DELETE FROM stock_receipts WHERE branch_id IN (SELECT id FROM branches WHERE tenant_id IN ($ids))",
            "DELETE FROM purchase_order_items WHERE po_id IN (SELECT id FROM purchase_orders WHERE branch_id IN (SELECT id FROM branches WHERE tenant_id IN ($ids)))",
            "DELETE FROM purchase_orders WHERE branch_id IN (SELECT id FROM branches WHERE tenant_id IN ($ids))",
            "DELETE FROM inventory WHERE branch_id IN (SELECT id FROM branches WHERE tenant_id IN ($ids))",
            "DELETE FROM product_prices WHERE price_list_id IN (SELECT id FROM price_lists WHERE tenant_id IN ($ids))",
            "DELETE FROM price_lists WHERE tenant_id IN ($ids)",
            "DELETE FROM gifts WHERE tenant_id IN ($ids)",
            "DELETE FROM promotions WHERE tenant_id IN ($ids)",
            "DELETE FROM products WHERE tenant_id IN ($ids)",
            "DELETE FROM customers WHERE tenant_id IN ($ids)",
            "DELETE FROM suppliers WHERE tenant_id IN ($ids)",
            "DELETE FROM store_settings WHERE tenant_id IN ($ids)",
            "DELETE FROM activity_logs WHERE tenant_id IN ($ids)",
            "DELETE FROM users WHERE tenant_id IN ($ids)",
            "DELETE FROM branches WHERE tenant_id IN ($ids)",
            "DELETE FROM tenants WHERE id IN ($ids)",
        ];
        $loiDon = [];
        foreach ($steps as $sql) {
            try { $pdo->exec($sql); } catch (Throwable $e) { $loiDon[] = substr($e->getMessage(), 0, 90); }
        }
        $con = $pdo->query("SELECT COUNT(*) FROM tenants WHERE id IN ($ids)")->fetchColumn();
        echo "\nDon dep: " . ((int) $con === 0 ? "da xoa sach 2 cua hang tam" : "!!! CON SOT $con cua hang tam, can xoa tay") . "\n";
        foreach ($loiDon as $l) {
            echo "  (buoc don gap loi: $l)\n";
        }
    }
}

// ---- BAO CAO ----
echo "\n" . str_repeat('=', 78) . "\n";
$fail = 0;
foreach ($results as $r) {
    $tag = $r['pass'] ? '  DAT  ' : '* HONG *';
    echo "$tag  {$r['name']}\n";
    if (!$r['pass']) {
        $fail++;
        echo "          -> {$r['detail']}\n";
    }
}
echo str_repeat('=', 78) . "\n";
$total = count($results);
if ($total === 0) {
    http_response_code(500);
    echo "KET QUA: KHONG CHAY DUOC PHEP THU NAO - xem loi o tren. Khong duoc coi la DAT.
";
} elseif ($fail === 0) {
    echo "KET QUA: DAT TAT CA $total phep thu - khong co ro ri du lieu giua cac cua hang.\n";
} else {
    http_response_code(500);
    echo "KET QUA: HONG $fail/$total phep thu - CO RO RI DU LIEU, KHONG DUOC DEPLOY.\n";
}
