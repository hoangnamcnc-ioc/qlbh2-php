<?php
/**
 * DU LIEU MAU 1 TUAN BAN HANG cho cua hang moi dang ky (tap hoa nho) + xoa sach de bat dau chinh thuc.
 *
 * Du lieu tao ra NHAT QUAN nhu du lieu that: ton kho = nhap - ban, so quy khop tung don, cong no khach/NCC khop
 * lich su, diem tich luy khop doanh so, ca ban hang khop so quy. Rieng 1 ca co co y thieu 20.000d de minh hoa
 * chuc nang "Doi chieu so lieu". Tat ca nhan vien mau co email @demo.invalid (khong dang nhap duoc, Quan tri co the
 * dat lai mat khau neu muon thu).
 */

/** Cua hang nay dang dung du lieu mau? */
function cuaHangDangDungDuLieuMau(int $tenantId): bool
{
    $st = db()->prepare("SELECT setting_value FROM store_settings WHERE tenant_id = ? AND setting_key = 'demo_data'");
    $st->execute([$tenantId]);
    return $st->fetchColumn() === '1';
}

/** Cua hang con trong (chua co san pham/khach/don/phieu nhap) -> moi cho tao du lieu mau. */
function cuaHangDangTrong(int $tenantId): bool
{
    $pdo = db();
    foreach ([
        'SELECT COUNT(*) FROM products WHERE tenant_id = ?',
        'SELECT COUNT(*) FROM customers WHERE tenant_id = ?',
        'SELECT COUNT(*) FROM suppliers WHERE tenant_id = ?',
        'SELECT COUNT(*) FROM orders o JOIN branches b ON b.id = o.branch_id WHERE b.tenant_id = ?',
    ] as $sql) {
        $st = $pdo->prepare($sql);
        $st->execute([$tenantId]);
        if ((int) $st->fetchColumn() > 0) {
            return false;
        }
    }
    return true;
}

/** Tao du lieu mau 1 tuan (7 ngay tron ven, tu 7 ngay truoc den HOM QUA; khong co don o tuong lai). Tu quan ly giao dich; nem exception neu loi. */
function taoDuLieuMau(PDO $pdo, int $tenantId, int $branchId, int $adminId): void
{
    mt_srand($tenantId * 7919 + 17);
    $rand = static fn(int $a, int $b): int => mt_rand($a, $b);
    $pick = static function (array $arr) { return $arr[mt_rand(0, count($arr) - 1)]; };
    $money = static fn(float $v): float => round($v);

    $pdo->beginTransaction();
    try {
        // ---- Danh muc, nhan hieu ----
        $catNames = ['Đồ uống', 'Bánh kẹo & snack', 'Mì, gạo & thực phẩm', 'Gia vị & nước chấm', 'Hóa phẩm & gia dụng'];
        $catIds = [];
        foreach ($catNames as $n) {
            $pdo->prepare('INSERT INTO categories (name, tenant_id) VALUES (?, ?)')->execute([$n, $tenantId]);
            $catIds[] = (int) $pdo->lastInsertId();
        }
        $brandNames = ['Vinamilk', 'Coca-Cola', 'Acecook', 'Masan', 'Orion', 'Unilever', 'Nestlé'];
        $brandIds = [];
        foreach ($brandNames as $n) {
            $pdo->prepare('INSERT INTO brands (name, tenant_id) VALUES (?, ?)')->execute([$n, $tenantId]);
            $brandIds[] = (int) $pdo->lastInsertId();
        }

        // ---- San pham: [sku, ten, don vi, nhom, nhan hieu, gia von, gia ban, ton dau, ton toi thieu, do pho bien] ----
        $catalog = [
            ['DRK01', 'Nước suối Aquafina 500ml', 'chai', 0, 1, 4000, 6000, 140, 30, 10],
            ['DRK02', 'Coca-Cola lon 330ml', 'lon', 0, 1, 7500, 10000, 120, 30, 9],
            ['DRK03', 'Trà xanh 0 độ 455ml', 'chai', 0, 1, 6500, 9000, 100, 24, 7],
            ['DRK04', 'Sữa tươi Vinamilk 180ml', 'hộp', 0, 0, 5200, 7000, 120, 30, 8],
            ['DRK05', 'Bia Tiger lon 330ml', 'lon', 0, 3, 14500, 18000, 60, 24, 6],
            ['DRK06', 'Cà phê hòa tan G7 (hộp 18 gói)', 'hộp', 0, 6, 42000, 55000, 30, 8, 3],
            ['SNK01', 'Bánh Oreo vị vani', 'gói', 1, 4, 7500, 10000, 70, 20, 6],
            ['SNK02', 'Snack Oishi tôm cay', 'gói', 1, 4, 4500, 6000, 90, 24, 7],
            ['SNK03', 'Bánh quy bơ Cosy (hộp)', 'hộp', 1, 4, 28000, 35000, 24, 6, 2],
            ['SNK04', 'Socola KitKat 4 thanh', 'gói', 1, 6, 15500, 20000, 40, 10, 3],
            ['SNK05', 'Kẹo cao su Doublemint', 'gói', 1, 5, 3200, 5000, 80, 20, 4],
            ['FOD01', 'Mì Hảo Hảo tôm chua cay', 'gói', 2, 2, 3200, 4500, 200, 50, 10],
            ['FOD02', 'Mì Omachi sườn hầm', 'gói', 2, 3, 5500, 7500, 100, 24, 6],
            ['FOD03', 'Gạo ST25 túi 5kg', 'túi', 2, 3, 98000, 120000, 20, 6, 3],
            ['FOD04', 'Trứng gà ta (vỉ 10 quả)', 'vỉ', 2, 3, 28000, 35000, 30, 10, 6],
            ['FOD05', 'Dầu ăn Simply 1 lít', 'chai', 2, 5, 46000, 55000, 24, 8, 3],
            ['FOD06', 'Đường trắng 1kg', 'gói', 2, 3, 19000, 24000, 30, 10, 3],
            ['FOD07', 'Xúc xích Vissan (gói 5 cây)', 'gói', 2, 3, 22000, 29000, 25, 8, 3],
            ['SPC01', 'Nước mắm Nam Ngư 500ml', 'chai', 3, 3, 28000, 36000, 30, 8, 4],
            ['SPC02', 'Nước tương Chinsu 500ml', 'chai', 3, 3, 17000, 22000, 30, 8, 3],
            ['SPC03', 'Hạt nêm Knorr 900g', 'gói', 3, 5, 52000, 65000, 18, 6, 2],
            ['HOU01', 'Nước rửa chén Sunlight 750g', 'chai', 4, 5, 24000, 30000, 24, 8, 3],
            ['HOU02', 'Bột giặt OMO 3kg', 'túi', 4, 5, 118000, 145000, 12, 4, 2],
            ['HOU03', 'Giấy vệ sinh Pulppy (lốc 10 cuộn)', 'lốc', 4, 5, 45000, 58000, 20, 6, 3],
        ];
        $prod = []; // idx => [id, price, cost, name]
        $stock = []; $minStock = [];
        foreach ($catalog as $i => [$sku, $name, $unit, $cat, $brand, $cost, $price, $qty0, $min, $w]) {
            $pdo->prepare(
                "INSERT INTO products (sku, name, unit, pack_size, cost_price, sell_price, category_id, brand_id, product_type, tenant_id)
                 VALUES (?,?,?,1,?,?,?,?,'PRODUCT',?)"
            )->execute([$sku, $name, $unit, $cost, $price, $catIds[$cat], $brandIds[$brand], $tenantId]);
            $prod[$i] = ['id' => (int) $pdo->lastInsertId(), 'price' => $price, 'cost' => $cost, 'name' => $name, 'w' => $w];
            $stock[$i] = 0.0;
            $minStock[$i] = $min;
        }

        // ---- Nha cung cap ----
        $supplierDefs = [
            ['Công ty Phân phối Hưng Phát', '0901000111', 'Q.7, TP.HCM', [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10]],
            ['Đại lý Tạp hóa Minh Tâm', '0902000222', 'Q.Bình Tân, TP.HCM', [11, 12, 13, 14, 15, 16, 17, 18, 19, 20]],
            ['Nhà phân phối Hóa phẩm An Khang', '0903000333', 'TP.Thủ Đức, TP.HCM', [21, 22, 23]],
        ];
        $suppliers = [];
        foreach ($supplierDefs as [$sname, $sphone, $saddr, $items]) {
            $pdo->prepare('INSERT INTO suppliers (name, phone, address, tenant_id) VALUES (?,?,?,?)')->execute([$sname, $sphone, $saddr, $tenantId]);
            $suppliers[] = ['id' => (int) $pdo->lastInsertId(), 'items' => $items, 'debt' => 0.0];
        }

        // ---- Nhan vien mau ----
        $staff = [['id' => $adminId, 'name' => 'Quản trị', 'in' => '08:00', 'out' => '17:00']];
        foreach ([['Thu ngân Lan', 'lan', '07:30', '15:30'], ['Thu ngân Minh', 'minh', '14:00', '21:45']] as [$sn, $slug, $in, $out]) {
            $pdo->prepare(
                "INSERT INTO users (name, email, password_hash, role, branch_id, tenant_id, is_active, hourly_wage, commission_percent)
                 VALUES (?, ?, ?, 'CASHIER', ?, ?, 1, 25000, 1)"
            )->execute([$sn, "demo-$tenantId-$slug@demo.invalid", password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT), $branchId, $tenantId]);
            $staff[] = ['id' => (int) $pdo->lastInsertId(), 'name' => $sn, 'in' => $in, 'out' => $out];
        }
        $lanId = $staff[1]['id'];
        $sellers = [$staff[1]['id'], $staff[1]['id'], $staff[2]['id'], $staff[2]['id'], $adminId];

        // ---- Khach hang ----
        $custDefs = [
            ['Nguyễn Thị Hoa', '0900000101', 'Q.1, TP.HCM'], ['Trần Văn Bình', '0900000102', 'Q.3, TP.HCM'],
            ['Lê Thị Mai', '0900000103', 'Q.10, TP.HCM'], ['Phạm Quốc Huy', '0900000104', 'Q.Tân Bình, TP.HCM'],
            ['Võ Thị Lan', '0900000105', 'Q.Phú Nhuận, TP.HCM'], ['Đặng Minh Tuấn', '0900000106', 'Q.Gò Vấp, TP.HCM'],
            ['Bùi Thị Thu', '0900000107', 'Q.5, TP.HCM'], ['Hoàng Văn Nam', '0900000108', 'Q.7, TP.HCM'],
            ['Ngô Thị Kim', '0900000109', 'Q.Bình Thạnh, TP.HCM'], ['Đỗ Thanh Sơn', '0900000110', 'Q.12, TP.HCM'],
            ['Huỳnh Thị Yến', '0900000111', 'TP.Thủ Đức, TP.HCM'], ['Dương Văn Khoa', '0900000112', 'Q.4, TP.HCM'],
            ['Lý Thị Ngọc', '0900000113', 'Q.8, TP.HCM'], ['Cao Văn Long', '0900000114', 'Q.6, TP.HCM'],
        ];
        $customers = [];
        foreach ($custDefs as $i => [$cn, $cp, $ca]) {
            $pdo->prepare('INSERT INTO customers (code, name, phone, address, tenant_id) VALUES (?,?,?,?,?)')
                ->execute([sprintf('KHM%d-%02d', $tenantId, $i + 1), $cn, $cp, $ca, $tenantId]);
            $customers[] = ['id' => (int) $pdo->lastInsertId(), 'debt' => 0.0, 'points' => 0];
        }

        // ---- Ma giam gia ----
        $pdo->prepare(
            "INSERT INTO coupons (code, discount_type, discount_value, min_order_amount, max_uses, used_count, is_active, start_date, end_date, tenant_id)
             VALUES (?, 'PERCENT', 10, 100000, 100, 0, 1, ?, ?, ?)"
        )->execute(['GIAM10', date('Y-m-d', strtotime('-7 days')), date('Y-m-d', strtotime('+90 days')), $tenantId]);
        $couponId = (int) $pdo->lastInsertId();

        // ---- Bo sinh ma / ghi so ----
        $seq = ['pt' => 0, 'pc' => 0, 'dh' => 0, 'pn' => 0, 'tr' => 0];
        $cash = static function (string $type, float $amount, string $reason, string $method, int $userId, string $ts, ?int $orderId = null, ?int $receiptId = null, int $auto = 1) use ($pdo, $branchId, $tenantId, &$seq): void {
            $k = $type === 'RECEIPT' ? 'pt' : 'pc';
            $seq[$k]++;
            $code = ($type === 'RECEIPT' ? 'PTM' : 'PCM') . $tenantId . '-' . $seq[$k];
            $pdo->prepare(
                'INSERT INTO cashbook_entries (code, branch_id, type, amount, reason, payment_method, created_by_id, order_id, receipt_id, auto_generated, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([$code, $branchId, $type, $amount, $reason, $method, $userId, $orderId, $receiptId, $auto, $ts]);
        };
        $dayCash = []; // [dayIdx => ['in' => , 'out' => ]] tien mat de dong ca
        $addDayCash = static function (int $d, string $type, float $amount, string $method) use (&$dayCash): void {
            if ($method !== 'CASH') return;
            $dayCash[$d] ??= ['in' => 0.0, 'out' => 0.0];
            $dayCash[$d][$type === 'RECEIPT' ? 'in' : 'out'] += $amount;
        };

        $baseDay = strtotime('today');
        $dayTs = static fn(int $d, string $hm): string => date('Y-m-d', $baseDay - (8 - $d) * 86400) . ' ' . $hm . ':00';

        // ---- Phieu nhap ----
        $makeReceipt = function (array $supplier, array $lines, string $ts, float $paidRatio, string $method, int $dayIdx, string $ref) use ($pdo, $branchId, $adminId, $tenantId, &$seq, &$stock, $prod, $cash, $addDayCash, &$suppliers): void {
            $total = 0.0;
            foreach ($lines as [$pi, $qty]) {
                $total += $qty * $prod[$pi]['cost'];
            }
            $paid = round($total * $paidRatio / 1000) * 1000;
            $seq['pn']++;
            $pdo->prepare(
                'INSERT INTO stock_receipts (code, branch_id, supplier_id, created_by_id, invoice_date, reference_no, discount_amount, extra_cost, paid_amount, total_amount, note, created_at) VALUES (?,?,?,?,?,?,0,0,?,?,?,?)'
            )->execute(['PNM' . $tenantId . '-' . $seq['pn'], $branchId, $supplier['id'], $adminId, substr($ts, 0, 10), $ref, $paid, $total, 'Dữ liệu mẫu', $ts]);
            $rid = (int) $pdo->lastInsertId();
            foreach ($lines as [$pi, $qty]) {
                $pdo->prepare('INSERT INTO stock_receipt_items (receipt_id, product_id, quantity, cost_price) VALUES (?,?,?,?)')
                    ->execute([$rid, $prod[$pi]['id'], $qty, $prod[$pi]['cost']]);
                $stock[$pi] += $qty;
            }
            if ($paid > 0) {
                $cash('PAYMENT', $paid, 'Trả tiền phiếu nhập PNM' . $tenantId . '-' . $seq['pn'], $method, $adminId, $ts, null, $rid);
                $addDayCash($dayIdx, 'PAYMENT', $paid, $method);
            }
            foreach ($suppliers as &$s) {
                if ($s['id'] === $supplier['id']) {
                    $s['debt'] += $total - $paid;
                }
            }
            unset($s);
        };

        // Nhap hang dau tuan (truoc khi ban): 3 phieu, tra het bang chuyen khoan
        foreach ($suppliers as $si => $supplier) {
            $lines = [];
            foreach ($supplier['items'] as $pi) {
                $lines[] = [$pi, (float) $catalog[$pi][7]];
            }
            $makeReceipt($supplier, $lines, $dayTs(0, '07:00'), 1.0, 'BANK_TRANSFER', 0, 'HD-DAUTUAN-' . ($si + 1));
        }

        // ---- Ban hang theo ngay ----
        $weights = [];
        foreach ($prod as $i => $p) { $weights = array_merge($weights, array_fill(0, $p['w'], $i)); }
        $methods = ['CASH', 'CASH', 'CASH', 'CASH', 'CASH', 'CASH', 'CASH', 'BANK_TRANSFER', 'BANK_TRANSFER', 'QR_CODE', 'CARD'];
        $debtOrders = [];   // don con no, cho khach tra o ngay sau
        $doneOrders = [];   // don hoan thanh du tien (co the dung de tra hang)
        $couponUsed = 0;
        $recentQuota = ['cancel' => [2 => 1, 5 => 1], 'return' => [3 => 1, 4 => 1, 6 => 1], 'collect' => [4 => 1, 6 => 1]];

        for ($d = 1; $d <= 7; $d++) {
            $date = date('Y-m-d', $baseDay - (8 - $d) * 86400);
            $dow = (int) date('N', strtotime($date));
            $n = $rand(10, 15) + ($dow >= 6 ? 5 : 0);

            // nhap bo sung giua tuan (ngay 4, 8h): mat hang sap het
            if ($d === 4) {
                foreach ($suppliers as $supplier) {
                    $lines = [];
                    foreach ($supplier['items'] as $pi) {
                        if ($stock[$pi] < $minStock[$pi] * 2.2) {
                            $lines[] = [$pi, (float) max(30, (int) $catalog[$pi][7] * 0.6)];
                        }
                    }
                    if ($lines) {
                        $makeReceipt($supplier, $lines, $dayTs($d, '08:05'), 0.7, 'CASH', $d, 'HD-BOSUNG-' . $d);
                    }
                }
            }

            $times = [];
            for ($k = 0; $k < $n; $k++) {
                $times[] = $rand(8 * 60, 21 * 60 + 30);
            }
            sort($times);

            foreach ($times as $minuteOfDay) {
                $hm = sprintf('%02d:%02d', intdiv($minuteOfDay, 60), $minuteOfDay % 60);
                $ts = $date . ' ' . $hm . ':' . sprintf('%02d', $rand(0, 59));

                // chon mat hang
                $nLines = $pick([1, 1, 2, 2, 3, 3, 4]);
                $chosen = [];
                for ($tries = 0; $tries < 12 && count($chosen) < $nLines; $tries++) {
                    $pi = $pick($weights);
                    if (isset($chosen[$pi])) continue;
                    $qty = $pick([1, 1, 1, 2, 2, 3, 4]);
                    if ($catalog[$pi][2] === 'gói' && $catalog[$pi][5] < 6000) $qty += $rand(0, 3);
                    if ($stock[$pi] - $qty < 0) continue;
                    $chosen[$pi] = $qty;
                }
                if (!$chosen) continue;

                $sub = 0.0;
                foreach ($chosen as $pi => $qty) { $sub += $qty * $prod[$pi]['price']; }
                $discount = 0.0; $couponCode = null;
                if ($couponUsed < 4 && $sub >= 100000 && $rand(1, 3) === 1) {
                    $discount = round($sub * 0.10); $couponCode = 'GIAM10'; $couponUsed++;
                }
                $total = $sub - $discount;
                $custIdx = $rand(1, 100) <= 55 ? $rand(0, count($customers) - 1) : null;
                $method = $pick($methods);
                $paid = $total; $debt = 0.0;
                if ($custIdx !== null && $total >= 150000 && $rand(1, 100) <= 25 && count($debtOrders) < 5) {
                    $paid = round($total * 0.5 / 1000) * 1000; $debt = $total - $paid;
                }
                $seller = $pick($sellers);
                $seq['dh']++;
                $code = 'DHM' . $tenantId . '-' . $seq['dh'];
                $payStatus = $paid >= $total ? 'PAID' : 'PARTIAL';
                $pdo->prepare(
                    "INSERT INTO orders (code, branch_id, customer_id, sold_by_id, source, status, payment_status, sub_total, discount, total_amount, paid_amount, coupon_code, created_at, updated_at)
                     VALUES (?,?,?,?, 'POS', 'COMPLETED', ?,?,?,?,?,?,?,?)"
                )->execute([$code, $branchId, $custIdx !== null ? $customers[$custIdx]['id'] : null, $seller, $payStatus, $sub, $discount, $total, $paid, $couponCode, $ts, $ts]);
                $oid = (int) $pdo->lastInsertId();
                $itemIds = [];
                foreach ($chosen as $pi => $qty) {
                    $pdo->prepare('INSERT INTO order_items (order_id, product_id, quantity, unit_price, line_total, cost_price) VALUES (?,?,?,?,?,?)')
                        ->execute([$oid, $prod[$pi]['id'], $qty, $prod[$pi]['price'], $qty * $prod[$pi]['price'], $prod[$pi]['cost']]);
                    $itemIds[$pi] = (int) $pdo->lastInsertId();
                    $stock[$pi] -= $qty;
                }
                $pdo->prepare('INSERT INTO order_status_history (order_id, from_status, to_status, changed_by_id, changed_at) VALUES (?, NULL, ?, ?, ?)')
                    ->execute([$oid, 'COMPLETED', $seller, $ts]);
                if ($paid > 0) {
                    $pdo->prepare('INSERT INTO payments (order_id, method, amount, paid_at) VALUES (?,?,?,?)')->execute([$oid, $method, $paid, $ts]);
                    $cash('RECEIPT', $paid, 'Thu tiền bán hàng ' . $code, $method, $seller, $ts, $oid);
                    $addDayCash($d, 'RECEIPT', $paid, $method);
                }
                if ($custIdx !== null) {
                    $customers[$custIdx]['points'] += (int) floor($total / 10000);
                    if ($debt > 0) {
                        $customers[$custIdx]['debt'] += $debt;
                        $pdo->prepare('INSERT INTO customer_debt_entries (customer_id, order_id, amount, note, created_by_id, created_at) VALUES (?,?,?,?,?,?)')
                            ->execute([$customers[$custIdx]['id'], $oid, $debt, 'Bán hàng chưa thanh toán đủ', $seller, $ts]);
                        $debtOrders[] = ['oid' => $oid, 'cust' => $custIdx, 'debt' => $debt, 'day' => $d, 'code' => $code, 'total' => $total];
                    }
                }
                if ($debt == 0.0) {
                    $doneOrders[] = ['oid' => $oid, 'cust' => $custIdx, 'day' => $d, 'items' => $itemIds, 'sub' => $sub, 'total' => $total, 'method' => $method, 'code' => $code, 'lanSeller' => $seller];
                }
            }

            // Don bi huy (hoan lai du tien): tao 1 don roi huy trong ngay
            if (!empty($recentQuota['cancel'][$d])) {
                $pi = $pick([0, 1, 7, 11]);
                $price = $prod[$pi]['price'] * 3; $ts = $date . ' 15:20:00';
                $seq['dh']++;
                $code = 'DHM' . $tenantId . '-' . $seq['dh'];
                $pdo->prepare(
                    "INSERT INTO orders (code, branch_id, sold_by_id, source, status, payment_status, sub_total, discount, total_amount, paid_amount, note, created_at, updated_at)
                     VALUES (?,?,?, 'POS', 'CANCELLED', 'PAID', ?,0,?,?, 'Khách đổi ý, hủy đơn', ?, ?)"
                )->execute([$code, $branchId, $lanId, $price, $price, $price, $ts, $ts]);
                $oid = (int) $pdo->lastInsertId();
                $pdo->prepare('INSERT INTO order_items (order_id, product_id, quantity, unit_price, line_total, cost_price) VALUES (?,?,3,?,?,?)')
                    ->execute([$oid, $prod[$pi]['id'], $prod[$pi]['price'], $price, $prod[$pi]['cost']]);
                $pdo->prepare('INSERT INTO payments (order_id, method, amount, paid_at) VALUES (?, "CASH", ?, ?)')->execute([$oid, $price, $ts]);
                $cash('RECEIPT', $price, 'Thu tiền bán hàng ' . $code, 'CASH', $lanId, $ts, $oid);
                $cash('PAYMENT', $price, 'Hoàn tiền hủy đơn ' . $code, 'CASH', $lanId, $date . ' 15:32:00', $oid);
                $addDayCash($d, 'RECEIPT', $price, 'CASH'); $addDayCash($d, 'PAYMENT', $price, 'CASH');
                $pdo->prepare('INSERT INTO order_status_history (order_id, from_status, to_status, note, changed_by_id, changed_at) VALUES (?, "COMPLETED", "CANCELLED", ?, ?, ?)')
                    ->execute([$oid, 'Khách đổi ý', $adminId, $date . ' 15:32:00']);
            }

            // Tra hang: 1 san pham cua mot don hoan thanh trong ngay truoc do
            if (!empty($recentQuota['return'][$d])) {
                $cands = array_values(array_filter($doneOrders, static fn($o) => $o['day'] <= $d && !isset($o['returned']) && $o['sub'] > 0));
                if ($cands) {
                    $o = $cands[$rand(0, count($cands) - 1)];
                    foreach ($doneOrders as &$dn) { if ($dn['oid'] === $o['oid']) { $dn['returned'] = true; } }
                    unset($dn);
                    $pi = array_key_first($o['items']);
                    $ratio = $o['sub'] > 0 ? $o['total'] / $o['sub'] : 1;
                    $refund = round($prod[$pi]['price'] * $ratio);
                    $seq['tr']++;
                    $ts = $date . ' 17:10:00';
                    $pdo->prepare("INSERT INTO order_returns (code, order_id, customer_id, reason, refund_amount, status, created_by_id, created_at) VALUES (?,?,?,?,?, 'RECEIVED', ?, ?)")
                        ->execute(['SRNM' . $tenantId . '-' . $seq['tr'], $o['oid'], $o['cust'] !== null ? $customers[$o['cust']]['id'] : null, 'Hàng lỗi / khách đổi ý', $refund, $adminId, $ts]);
                    $rid = (int) $pdo->lastInsertId();
                    $pdo->prepare('INSERT INTO order_return_items (return_id, order_item_id, product_id, quantity, unit_price, line_total) VALUES (?,?,?,1,?,?)')
                        ->execute([$rid, $o['items'][$pi], $prod[$pi]['id'], $prod[$pi]['price'], $prod[$pi]['price']]);
                    $stock[$pi] += 1;
                    $cash('PAYMENT', $refund, 'Hoàn tiền trả hàng SRNM' . $tenantId . '-' . $seq['tr'], $o['method'], $adminId, $ts, $o['oid']);
                    $addDayCash($d, 'PAYMENT', $refund, $o['method']);
                    if ($o['cust'] !== null) {
                        $customers[$o['cust']]['points'] = max(0, $customers[$o['cust']]['points'] - (int) floor($refund / 10000));
                    }
                }
            }

            // Khach tra no (don con no tu ngay truoc do)
            if (!empty($recentQuota['collect'][$d])) {
                foreach ($debtOrders as &$dbo) {
                    if (!isset($dbo['paid']) && $dbo['day'] < $d) {
                        $dbo['paid'] = true;
                        $ts = $date . ' 10:40:00';
                        $method = $pick(['CASH', 'BANK_TRANSFER']);
                        $cid = $customers[$dbo['cust']]['id'];
                        $pdo->prepare("UPDATE orders SET paid_amount = total_amount, payment_status = 'PAID' WHERE id = ?")->execute([$dbo['oid']]);
                        $pdo->prepare('INSERT INTO payments (order_id, method, amount, paid_at) VALUES (?,?,?,?)')->execute([$dbo['oid'], $method, $dbo['debt'], $ts]);
                        $pdo->prepare('INSERT INTO customer_debt_entries (customer_id, order_id, amount, note, created_by_id, created_at) VALUES (?,?,?,?,?,?)')
                            ->execute([$cid, $dbo['oid'], -$dbo['debt'], 'Thu nợ đơn hàng', $adminId, $ts]);
                        $customers[$dbo['cust']]['debt'] -= $dbo['debt'];
                        $cash('RECEIPT', $dbo['debt'], 'Thu nợ đơn hàng ' . $dbo['code'], $method, $adminId, $ts, $dbo['oid']);
                        $addDayCash($d, 'RECEIPT', $dbo['debt'], $method);
                        break;
                    }
                }
                unset($dbo);
            }
        }

        // ---- Thu chi thu cong ----
        foreach ([[2, 'PAYMENT', 1200000, 'BANK_TRANSFER', 'Tiền thuê mặt bằng (tuần này)'], [3, 'PAYMENT', 450000, 'CASH', 'Tiền điện nước'],
                  [5, 'PAYMENT', 120000, 'CASH', 'Mua túi nilon, giấy in hóa đơn'], [6, 'RECEIPT', 300000, 'CASH', 'Thu tiền thanh lý thùng carton']] as [$d, $type, $amount, $method, $reason]) {
            $cash($type, $amount, $reason, $method, $adminId, $dayTs($d, '12:00'), null, null, 0);
            $addDayCash($d, $type, $amount, $method);
        }

        // ---- Ca ban hang + cham cong ----
        for ($d = 1; $d <= 7; $d++) {
            $in = $dayCash[$d]['in'] ?? 0.0; $out = $dayCash[$d]['out'] ?? 0.0;
            $opening = 500000.0;
            $expected = $opening + $in - $out;
            $diff = $d === 5 ? -20000.0 : 0.0;
            $pdo->prepare(
                "INSERT INTO pos_shifts (branch_id, opened_by_id, opened_at, opening_cash, status, closed_by_id, closed_at, cash_receipts, cash_payments, expected_cash, counted_cash, difference, open_note, close_note)
                 VALUES (?,?,?,?, 'CLOSED', ?,?,?,?,?,?,?, ?, ?)"
            )->execute([$branchId, $lanId, $dayTs($d, '07:25'), $opening, $lanId, $dayTs($d, '21:50'), $in, $out, $expected, $expected + $diff, $diff,
                'Mở ca (dữ liệu mẫu)', $diff != 0 ? 'Thiếu 20.000đ do thối nhầm tiền (ví dụ minh họa lệch ca)' : null]);
            foreach ($staff as $si => $st) {
                if ($si === 0 && in_array($d, [3, 6], true)) continue; // quan tri nghi 2 ngay
                $pdo->prepare('INSERT INTO attendance (tenant_id, user_id, work_date, time_in, time_out, note) VALUES (?,?,?,?,?,?)')
                    ->execute([$tenantId, $st['id'], substr($dayTs($d, '00:00'), 0, 10), $st['in'] . ':00', $st['out'] . ':00', 'Dữ liệu mẫu']);
            }
        }

        // ---- Kiem kho cuoi tuan: phat hien thieu hut 2 mat hang -> ton duoi dinh muc de minh hoa "Goi y nhap hang" ----
        $adjust = [];
        foreach ([20, 22] as $pi) { // Hat nem Knorr, Bot giat OMO
            $target = max(0.0, (float) $minStock[$pi] - 2);
            if ($stock[$pi] > $target) {
                $adjust[$pi] = [$stock[$pi], $target];
            }
        }
        if ($adjust) {
            $tsKk = $dayTs(7, '21:30');
            $pdo->prepare("INSERT INTO stock_takes (code, branch_id, created_by_id, status, balanced_by_id, balanced_at, note, created_at) VALUES (?,?,?, 'BALANCED', ?, ?, ?, ?)")
                ->execute(['KKM' . $tenantId . '-1', $branchId, $adminId, $adminId, $tsKk, 'Kiểm kê cuối tuần (dữ liệu mẫu): phát hiện thiếu hụt', $tsKk]);
            $kkId = (int) $pdo->lastInsertId();
            foreach ($adjust as $pi => [$sys, $counted]) {
                $pdo->prepare('INSERT INTO stock_take_items (take_id, product_id, system_qty, counted_qty) VALUES (?,?,?,?)')
                    ->execute([$kkId, $prod[$pi]['id'], $sys, $counted]);
                $stock[$pi] = $counted;
            }
        }

        // ---- Chot ton kho, cong no, diem, ma giam gia ----
        foreach ($prod as $i => $p) {
            $pdo->prepare('INSERT INTO inventory (branch_id, product_id, quantity, min_stock) VALUES (?,?,?,?)')
                ->execute([$branchId, $p['id'], $stock[$i], $minStock[$i]]);
        }
        foreach ($customers as $c) {
            $pdo->prepare('UPDATE customers SET debt = ?, loyalty_points = ? WHERE id = ?')->execute([max(0, $c['debt']), $c['points'], $c['id']]);
        }
        foreach ($suppliers as $s) {
            $pdo->prepare('UPDATE suppliers SET debt = ? WHERE id = ?')->execute([$s['debt'], $s['id']]);
        }
        $pdo->prepare('UPDATE coupons SET used_count = ? WHERE id = ?')->execute([$couponUsed, $couponId]);

        $pdo->prepare("INSERT INTO store_settings (tenant_id, setting_key, setting_value) VALUES (?, 'demo_data', '1') ON DUPLICATE KEY UPDATE setting_value = '1'")->execute([$tenantId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** So lieu tom tat de hien truoc khi xoa. */
function demThongKeDuLieuCuaHang(int $tenantId): array
{
    $pdo = db();
    $q = static function (string $sql) use ($pdo, $tenantId): int {
        $st = $pdo->prepare($sql);
        $st->execute([$tenantId]);
        return (int) $st->fetchColumn();
    };
    return [
        'Sản phẩm' => $q('SELECT COUNT(*) FROM products WHERE tenant_id = ?'),
        'Khách hàng' => $q('SELECT COUNT(*) FROM customers WHERE tenant_id = ?'),
        'Nhà cung cấp' => $q('SELECT COUNT(*) FROM suppliers WHERE tenant_id = ?'),
        'Đơn hàng' => $q('SELECT COUNT(*) FROM orders o JOIN branches b ON b.id = o.branch_id WHERE b.tenant_id = ?'),
        'Phiếu nhập' => $q('SELECT COUNT(*) FROM stock_receipts r JOIN branches b ON b.id = r.branch_id WHERE b.tenant_id = ?'),
        'Phiếu thu/chi' => $q('SELECT COUNT(*) FROM cashbook_entries c JOIN branches b ON b.id = c.branch_id WHERE b.tenant_id = ?'),
    ];
}

/**
 * XOA TOAN BO du lieu kinh doanh cua cua hang (san pham, khach, NCC, don, kho, so quy, ca, cham cong, khuyen mai...)
 * de bat dau chinh thuc. GIU: cua hang, chi nhanh, tai khoan Quan tri dang dung va cac tai khoan nhan vien that,
 * cau hinh. Xoa cac nhan vien mau (@demo.invalid). Chay trong 1 giao dich - loi thi khong xoa gi.
 */
function xoaToanBoDuLieuKinhDoanh(PDO $pdo, int $tenantId): void
{
    $bl = $pdo->prepare('SELECT id FROM branches WHERE tenant_id = ?');
    $bl->execute([$tenantId]);
    $bl = array_map('intval', $bl->fetchAll(PDO::FETCH_COLUMN));
    if (!$bl) {
        throw new RuntimeException('Cửa hàng chưa có chi nhánh.');
    }
    $in = implode(',', array_fill(0, count($bl), '?'));

    // File anh san pham (lay ten truoc khi xoa dong)
    $imgs = $pdo->prepare('SELECT pi.filename FROM product_images pi JOIN products p ON p.id = pi.product_id WHERE p.tenant_id = ?');
    $imgs->execute([$tenantId]);
    $files = $imgs->fetchAll(PDO::FETCH_COLUMN);

    $orders = "SELECT id FROM orders WHERE branch_id IN ($in)";
    $items = "SELECT id FROM order_items WHERE order_id IN ($orders)";
    $prods = 'SELECT id FROM products WHERE tenant_id = ?';
    $custs = 'SELECT id FROM customers WHERE tenant_id = ?';
    $stmts = [ // [sql, so lan lap danh sach chi nhanh, so lan tenant]
        ["DELETE FROM order_item_batches WHERE order_item_id IN ($items)", 1, 0],
        ["DELETE FROM warranty_claims WHERE warranty_card_id IN (SELECT id FROM warranty_cards WHERE order_item_id IN ($items))", 1, 0],
        ["DELETE FROM warranty_cards WHERE order_item_id IN ($items)", 1, 0],
        ["DELETE FROM order_return_items WHERE return_id IN (SELECT id FROM order_returns WHERE order_id IN ($orders))", 1, 0],
        ["DELETE FROM order_returns WHERE order_id IN ($orders)", 1, 0],
        ["DELETE FROM customer_debt_entries WHERE order_id IN ($orders)", 1, 0],
        ["DELETE FROM customer_debt_entries WHERE customer_id IN ($custs)", 0, 1],
        ["DELETE FROM shipments WHERE order_id IN ($orders)", 1, 0],
        ["DELETE FROM order_status_history WHERE order_id IN ($orders)", 1, 0],
        ["DELETE FROM payments WHERE order_id IN ($orders)", 1, 0],
        ["DELETE FROM order_items WHERE order_id IN ($orders)", 1, 0],
        ["DELETE FROM orders WHERE branch_id IN ($in)", 1, 0],
        ["DELETE FROM cashbook_entries WHERE branch_id IN ($in)", 1, 0],
        ["DELETE FROM pos_shifts WHERE branch_id IN ($in)", 1, 0],
        ["DELETE FROM inventory WHERE branch_id IN ($in)", 1, 0],
        ["DELETE FROM product_batches WHERE branch_id IN ($in)", 1, 0],
        ["DELETE FROM supplier_return_items WHERE return_id IN (SELECT id FROM supplier_returns WHERE branch_id IN ($in))", 1, 0],
        ["DELETE FROM supplier_returns WHERE branch_id IN ($in)", 1, 0],
        ["DELETE FROM stock_receipt_items WHERE receipt_id IN (SELECT id FROM stock_receipts WHERE branch_id IN ($in))", 1, 0],
        ["DELETE FROM stock_receipts WHERE branch_id IN ($in)", 1, 0],
        ["DELETE FROM purchase_order_items WHERE po_id IN (SELECT id FROM purchase_orders WHERE branch_id IN ($in))", 1, 0],
        ["DELETE FROM purchase_orders WHERE branch_id IN ($in)", 1, 0],
        ["DELETE FROM stock_take_items WHERE take_id IN (SELECT id FROM stock_takes WHERE branch_id IN ($in))", 1, 0],
        ["DELETE FROM stock_takes WHERE branch_id IN ($in)", 1, 0],
        ["DELETE FROM stock_transfer_items WHERE transfer_id IN (SELECT id FROM stock_transfers WHERE from_branch_id IN ($in))", 1, 0],
        ["DELETE FROM stock_transfers WHERE from_branch_id IN ($in)", 1, 0],
        ["DELETE FROM gift_redemptions WHERE customer_id IN ($custs)", 0, 1],
        ["DELETE FROM price_adjustments WHERE product_id IN ($prods)", 0, 1],
        ["DELETE FROM product_images WHERE product_id IN ($prods)", 0, 1],
        ["DELETE FROM product_prices WHERE product_id IN ($prods)", 0, 1],
        ["DELETE FROM combo_items WHERE combo_product_id IN ($prods)", 0, 1],
        ["DELETE FROM product_variants WHERE product_id IN ($prods)", 0, 1],
    ];

    $keep = ['tenants', 'users', 'branches', 'store_settings', 'custom_roles', 'cancel_reasons', 'order_sources', 'sales_channels', 'tax_rates'];
    $tables = $pdo->prepare("SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'tenant_id'");
    $tables->execute();
    $tables = array_values(array_diff($tables->fetchAll(PDO::FETCH_COLUMN), $keep));

    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    $pdo->beginTransaction();
    try {
        foreach ($stmts as [$sql, $nb, $nt]) {
            $args = [];
            for ($k = 0; $k < $nb; $k++) { $args = array_merge($args, $bl); }
            // cac cau lenh long nhau co the lap danh sach chi nhanh nhieu lan: dem lai theo so dau ?
            if ($nb > 0) {
                $args = [];
                $reps = (int) (substr_count($sql, '?') / count($bl));
                for ($k = 0; $k < $reps; $k++) { $args = array_merge($args, $bl); }
            }
            for ($k = 0; $k < $nt; $k++) { $args[] = $tenantId; }
            $pdo->prepare($sql)->execute($args);
        }
        foreach ($tables as $t) {
            $pdo->prepare("DELETE FROM `$t` WHERE tenant_id = ?")->execute([$tenantId]);
        }
        // Nhan vien mau (khong dang nhap duoc) - giu nhan vien that
        $pdo->prepare("DELETE FROM users WHERE tenant_id = ? AND email LIKE 'demo-%@demo.invalid'")->execute([$tenantId]);
        $pdo->prepare("DELETE FROM store_settings WHERE tenant_id = ? AND setting_key = 'demo_data'")->execute([$tenantId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        throw $e;
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

    foreach ($files as $f) {
        $path = __DIR__ . '/uploads/products/' . basename((string) $f);
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
