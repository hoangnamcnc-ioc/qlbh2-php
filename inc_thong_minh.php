<?php
/**
 * Tinh nang "thong minh" cua QLBH-CLOUD - tat ca chay tren du lieu cua chinh cua hang, khong gui du lieu ra ngoai:
 *   1. duBaoNhapHang()   - du bao toc do ban (uu tien gan day), ngay het hang, xu huong, canh bao hang se het han truoc khi ban het
 *   2. goiYXaHang()      - hang can date: du bao ban duoc bao nhieu truoc han, de xuat % giam (khong thap hon gia von) + hang ban kem
 *   3. phatHienBatThuong() - huy don nhieu, chiet khau cao, chenh lech ca, hao hut tang, no qua han, hang het han chua xu ly
 *   4. traLoiCauHoi()    - hoi dap tieng Viet ("hom nay ban bao nhieu", "mat hang nao ban chay"...), tinh so tai cho
 */

require_once __DIR__ . '/inc_functions.php';

/** Bo dau tieng Viet + chu thuong de so khop tu khoa. */
function boDau(string $s): string
{
    static $map = null;
    if ($map === null) {
        $groups = [
            'a' => 'àáảãạăằắẳẵặâầấẩẫậ', 'e' => 'èéẻẽẹêềếểễệ', 'i' => 'ìíỉĩị', 'o' => 'òóỏõọôồốổỗộơờớởỡợ',
            'u' => 'ùúủũụưừứửữự', 'y' => 'ỳýỷỹỵ', 'd' => 'đ',
        ];
        $map = [];
        foreach ($groups as $to => $chars) {
            foreach (mb_str_split($chars) as $c) {
                $map[$c] = $to;
            }
        }
    }
    return strtr(mb_strtolower($s, 'UTF-8'), $map);
}

/** Dieu kien phan pham vi chi nhanh cho cac truy van co alias bang branches la `b` (0 = ca cua hang). */
function phamViChiNhanh(int $branchId, array &$params, string $col = 'b.id'): string
{
    if ($branchId > 0) {
        $params[] = $branchId;
        return " AND $col = ?";
    }
    return '';
}

/** Toc do ban theo san pham: [product_id => ['q7' => so luong 7 ngay, 'q30' => 30 ngay]] cho 1 chi nhanh. */
function layTocDoBanChiNhanh(PDO $pdo, int $branchId): array
{
    $stmt = $pdo->prepare(
        "SELECT oi.product_id,
                SUM(CASE WHEN o.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN oi.quantity ELSE 0 END) AS q7,
                SUM(oi.quantity) AS q30
         FROM order_items oi JOIN orders o ON o.id = oi.order_id
         WHERE o.branch_id = ? AND o.status NOT IN ('CANCELLED','DRAFT') AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
         GROUP BY oi.product_id"
    );
    $stmt->execute([$branchId]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[(int) $r['product_id']] = ['q7' => (float) $r['q7'], 'q30' => (float) $r['q30']];
    }
    return $out;
}

/** Toc do ban/ngay uu tien gan day: 60% theo 7 ngay + 40% theo 30 ngay (hang moi ban: chi theo 7 ngay). */
function tocDoBanMuot(float $q7, float $q30): float
{
    $v7 = $q7 / 7;
    $v30 = $q30 / 30;
    if ($q30 <= 0) {
        return 0.0;
    }
    return $q30 - $q7 <= 0 ? $v7 : 0.6 * $v7 + 0.4 * $v30;
}

/**
 * Goi y nhap hang thong minh cho 1 chi nhanh. Moi dong: san pham, ton, toc do (so/ngay), xu huong (+/- %), ngay het hang du bao,
 * so luong de nghi, canh bao hang se het han truoc khi ban het.
 */
function duBaoNhapHang(PDO $pdo, int $tenantId, int $branchId, int $mucTieuNgay): array
{
    $stmt = $pdo->prepare(
        "SELECT p.id, p.sku, p.name, p.unit, COALESCE(i.quantity, 0) AS ton, COALESCE(i.min_stock, 0) AS dinh_muc
         FROM products p
         JOIN inventory i ON i.product_id = p.id AND i.branch_id = ? AND i.variant_id IS NULL
         WHERE p.tenant_id = ? AND p.is_active = 1 AND p.product_type = 'PRODUCT'"
    );
    $stmt->execute([$branchId, $tenantId]);
    $products = $stmt->fetchAll();
    if (!$products) {
        return [];
    }
    $sales = layTocDoBanChiNhanh($pdo, $branchId);

    // Lo sap het han: so luong con va han gan nhat cua tung san pham
    $minBs = $pdo->prepare(
        "SELECT product_id, expiry_date, SUM(quantity) AS qty FROM product_batches
         WHERE branch_id = ? AND quantity > 0 AND expiry_date IS NOT NULL GROUP BY product_id, expiry_date ORDER BY expiry_date"
    );
    $minBs->execute([$branchId]);
    $firstBatch = [];
    foreach ($minBs->fetchAll() as $r) {
        $pid = (int) $r['product_id'];
        if (!isset($firstBatch[$pid])) {
            $firstBatch[$pid] = $r;
        }
    }

    $out = [];
    foreach ($products as $row) {
        $pid = (int) $row['id'];
        $ton = (float) $row['ton'];
        $dinhMuc = (float) $row['dinh_muc'];
        $q7 = $sales[$pid]['q7'] ?? 0.0;
        $q30 = $sales[$pid]['q30'] ?? 0.0;
        $v = tocDoBanMuot($q7, $q30);
        $v30 = $q30 / 30;
        $xuHuong = ($v30 > 0 && $q30 - $q7 > 0) ? (($q7 / 7) / $v30 - 1) * 100 : null;   // +% / -% so voi trung binh 30 ngay
        $soNgay = $v > 0 ? $ton / $v : null;

        $goiY = 0.0;
        if ($v > 0 && ($soNgay === null || $soNgay <= $mucTieuNgay)) {
            $goiY = max($goiY, ceil($v * $mucTieuNgay - $ton));
        }
        if ($dinhMuc > 0 && $ton <= $dinhMuc) {
            $goiY = max($goiY, $dinhMuc - $ton);
        }

        // Co lo se het han truoc khi ban het (du bao theo toc do hien tai)?
        $canhBaoHan = null;
        if (isset($firstBatch[$pid])) {
            $han = $firstBatch[$pid]['expiry_date'];
            $ngayConLai = (int) floor((strtotime($han) - strtotime('today')) / 86400);
            $daBanDuoc = $v * max(0, $ngayConLai);
            $du = (float) $firstBatch[$pid]['qty'] - $daBanDuoc;
            if ($du > 0.0005) {
                $canhBaoHan = ['han' => $han, 'con_lai' => $ngayConLai, 'du' => ceil($du)];
            }
        }
        if ($goiY > 0 || $canhBaoHan) {
            $out[] = $row + [
                'toc_do' => $v, 'xu_huong' => $xuHuong, 'so_ngay' => $soNgay,
                'het_hang_du_kien' => $soNgay !== null ? date('Y-m-d', strtotime('+' . (int) floor($soNgay) . ' days')) : null,
                'goi_y' => $goiY, 'canh_bao_han' => $canhBaoHan, 'ban_30' => $q30,
            ];
        }
    }
    usort($out, static function (array $a, array $b): int {
        return ($a['so_ngay'] ?? PHP_INT_MAX) <=> ($b['so_ngay'] ?? PHP_INT_MAX);
    });
    return $out;
}

/**
 * Goi y xa hang: cac lo can date / het han. Du bao so luong ban duoc truoc han theo toc do hien tai; phan du la phan can xa.
 * De xuat % giam theo ti le du (khong dua gia ban xuong thap hon gia von) + 2 mon hay duoc mua kem de ban kem.
 */
function goiYXaHang(PDO $pdo, int $tenantId, int $branchId, int $days, int $limit = 30): array
{
    $batches = layLoCanHan($pdo, $tenantId, $branchId, $days, 300);
    if (!$batches) {
        return [];
    }
    $velocity = [];
    foreach ($batches as $b) {
        $bid = (int) $b['branch_id'];
        if (!isset($velocity[$bid])) {
            $velocity[$bid] = layTocDoBanChiNhanh($pdo, $bid);
        }
    }
    // Gom theo san pham + chi nhanh (nhieu lo cung san pham: tinh luy ke theo han)
    $accum = [];
    $out = [];
    foreach ($batches as $b) {   // layLoCanHan da sap theo han gan nhat
        $pid = (int) $b['product_id'];
        $bid = (int) $b['branch_id'];
        $key = $bid . ':' . $pid;
        $v = tocDoBanMuot($velocity[$bid][$pid]['q7'] ?? 0.0, $velocity[$bid][$pid]['q30'] ?? 0.0);
        $daysLeft = max(0, (int) floor((strtotime($b['expiry_date']) - strtotime('today')) / 86400));
        $already = $accum[$key] ?? 0.0;                          // hang lo truoc se duoc ban truoc
        $canSell = max(0.0, $v * $daysLeft - $already);
        $qty = (float) $b['quantity'];
        $accum[$key] = $already + $qty;
        $excess = $daysLeft <= 0 ? $qty : max(0.0, $qty - $canSell);
        if ($excess < 0.0005) {
            continue;   // du kien ban kip - khong can xa
        }
        $ratio = $excess / $qty;
        $discount = $daysLeft <= 0 ? 50 : ($ratio > 0.75 ? 40 : ($ratio > 0.5 ? 30 : ($ratio > 0.25 ? 20 : 10)));
        $sell = (float) $b['sell_price'];
        $cost = (float) $b['cost_price'];
        $price = floor($sell * (1 - $discount / 100) / 500) * 500;
        $atCost = false;
        if ($cost > 0 && $price < $cost) {
            $price = $cost;
            $atCost = true;
            $discount = $sell > 0 ? max(0, (int) round((1 - $cost / $sell) * 100)) : 0;
        }
        $out[] = $b + [
            'toc_do' => $v, 'ngay_con_lai' => $daysLeft, 'du_can_xa' => ceil($excess), 'giam_goi_y' => $discount,
            'gia_goi_y' => $price, 'xa_gia_von' => $atCost, 'ban_kem' => [],
        ];
        if (count($out) >= $limit) {
            break;
        }
    }
    // Hang hay mua kem (90 ngay) cho tung san pham xuat hien
    $co = $pdo->prepare(
        "SELECT p2.name, COUNT(*) AS c
         FROM order_items a
         JOIN orders o ON o.id = a.order_id
         JOIN order_items b2 ON b2.order_id = a.order_id AND b2.product_id <> a.product_id
         JOIN products p2 ON p2.id = b2.product_id
         WHERE a.product_id = ? AND o.status NOT IN ('CANCELLED','DRAFT') AND o.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
           AND p2.tenant_id = ? AND p2.is_active = 1
         GROUP BY p2.id, p2.name ORDER BY c DESC LIMIT 2"
    );
    $cache = [];
    foreach ($out as &$o) {
        $pid = (int) $o['product_id'];
        if (!isset($cache[$pid])) {
            $co->execute([$pid, $tenantId]);
            $cache[$pid] = array_column($co->fetchAll(), 'name');
        }
        $o['ban_kem'] = $cache[$pid];
    }
    unset($o);
    return $out;
}

/** Hang con ton nhung khong ban duoc cai nao trong $days ngay: [{id,name,sku,qty,von_chon,last_sold}], sap theo von chon. */
function layHangTonLau(PDO $pdo, int $tenantId, int $branchId, int $days, int $limit = 50): array
{
    $params = [];
    $ordBranch = '';
    $invBranch = '';
    if ($branchId) {
        $ordBranch = ' AND o.branch_id = ?';
        $invBranch = ' AND i.branch_id = ?';
    }
    $sql = "SELECT p.id, p.name, p.sku, p.cost_price, inv.qty,
                   (SELECT MAX(o.created_at) FROM order_items oi JOIN orders o ON o.id = oi.order_id
                     WHERE oi.product_id = p.id AND o.status NOT IN ('DRAFT','CANCELLED')$ordBranch) AS last_sold,
                   (SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi JOIN orders o ON o.id = oi.order_id
                     WHERE oi.product_id = p.id AND o.status NOT IN ('DRAFT','CANCELLED')
                       AND o.created_at >= DATE_SUB(NOW(), INTERVAL " . (int) $days . " DAY)$ordBranch) AS sold_n
            FROM products p
            JOIN (SELECT i.product_id, SUM(i.quantity) AS qty FROM inventory i JOIN branches b ON b.id = i.branch_id
                  WHERE b.tenant_id = ?$invBranch GROUP BY i.product_id HAVING SUM(i.quantity) > 0) inv ON inv.product_id = p.id
            WHERE p.tenant_id = ? AND p.is_active = 1 AND p.product_type = 'PRODUCT'
            HAVING sold_n = 0
            ORDER BY inv.qty * p.cost_price DESC
            LIMIT " . (int) $limit;
    if ($branchId) { $params[] = $branchId; $params[] = $branchId; }
    $params[] = $tenantId;
    if ($branchId) { $params[] = $branchId; }
    $params[] = $tenantId;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['von_chon'] = (float) $r['qty'] * (float) $r['cost_price'];
    }
    unset($r);
    return $rows;
}

/**
 * Cac canh bao bat thuong trong 30 ngay qua. Moi canh bao: muc (cao|trung|thap), tieu_de, chi_tiet, link.
 * Quy tac don gian, minh bach (khong "hop den") de chu cua hang hieu vi sao co canh bao.
 */
function phatHienBatThuong(PDO $pdo, int $tenantId, int $branchId = 0): array
{
    $alerts = [];
    $add = static function (string $muc, string $title, string $detail, string $link) use (&$alerts): void {
        $alerts[] = ['muc' => $muc, 'tieu_de' => $title, 'chi_tiet' => $detail, 'link' => $link];
    };

    $bp = [$tenantId];
    $bw = phamViChiNhanh($branchId, $bp, 'o.branch_id');

    // 1. Nhan vien huy don nhieu
    $stmt = $pdo->prepare(
        "SELECT u.name, COUNT(*) AS tong, SUM(o.status = 'CANCELLED') AS huy
         FROM orders o JOIN users u ON u.id = o.sold_by_id JOIN branches b ON b.id = o.branch_id
         WHERE b.tenant_id = ? AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)$bw
         GROUP BY u.id, u.name HAVING tong >= 10 AND huy >= 3 AND huy / tong >= 0.15"
    );
    $stmt->execute($bp);
    foreach ($stmt->fetchAll() as $r) {
        $pct = round((float) $r['huy'] / (float) $r['tong'] * 100);
        $add($pct >= 30 ? 'cao' : 'trung', 'Huỷ đơn nhiều: ' . $r['name'],
            (int) $r['huy'] . '/' . (int) $r['tong'] . ' đơn bị huỷ trong 30 ngày (' . $pct . '%). Nên xem lại lý do huỷ.', 'orders.php?status=CANCELLED');
    }

    // 2. Chiet khau cao
    $stmt = $pdo->prepare(
        "SELECT u.name, COUNT(*) AS so_don, SUM(o.discount) AS tong_giam
         FROM orders o JOIN users u ON u.id = o.sold_by_id JOIN branches b ON b.id = o.branch_id
         WHERE b.tenant_id = ? AND o.status <> 'CANCELLED' AND o.sub_total > 0 AND o.discount >= 20000 AND o.discount / o.sub_total >= 0.25
           AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)$bw
         GROUP BY u.id, u.name HAVING so_don >= 3"
    );
    $stmt->execute($bp);
    foreach ($stmt->fetchAll() as $r) {
        $add('trung', 'Chiết khấu cao: ' . $r['name'], (int) $r['so_don'] . ' đơn giảm từ 25% trở lên (tổng giảm ' . money($r['tong_giam']) . ') trong 30 ngày.', 'orders.php');
    }

    // 3. Chenh lech ca ban hang
    $sp = [$tenantId];
    $sw = phamViChiNhanh($branchId, $sp, 's.branch_id');
    $stmt = $pdo->prepare(
        "SELECT s.id, s.closed_at, s.difference, br.name AS branch_name, u.name AS user_name
         FROM pos_shifts s JOIN branches br ON br.id = s.branch_id LEFT JOIN users u ON u.id = s.closed_by_id
         WHERE br.tenant_id = ? AND s.status = 'CLOSED' AND s.closed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND ABS(s.difference) >= 20000$sw
         ORDER BY ABS(s.difference) DESC LIMIT 5"
    );
    $stmt->execute($sp);
    foreach ($stmt->fetchAll() as $r) {
        $d = (float) $r['difference'];
        $add(abs($d) >= 100000 ? 'cao' : 'trung', 'Chênh lệch tiền mặt ca ' . date('d/m', strtotime($r['closed_at'])),
            ($d < 0 ? 'Thiếu ' : 'Thừa ') . money(abs($d)) . ' — ' . ($r['user_name'] ?? '?') . ', ' . $r['branch_name'] . '.', 'ca_ban_hang.php');
    }

    // 4. Hao hut (xuat huy) tuan nay tang dot bien so voi trung binh 4 tuan truoc
    $wp = [$tenantId, $tenantId];
    $ww = '';
    if ($branchId) { $ww = ' AND branch_id = ?'; $wp = [$tenantId, $branchId, $tenantId, $branchId]; }
    $stmt = $pdo->prepare(
        "SELECT
           (SELECT COALESCE(SUM(quantity * cost_price), 0) FROM stock_write_offs WHERE tenant_id = ?$ww AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS tuan_nay,
           (SELECT COALESCE(SUM(quantity * cost_price), 0) / 4 FROM stock_write_offs WHERE tenant_id = ?$ww AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY) AND created_at >= DATE_SUB(NOW(), INTERVAL 35 DAY)) AS tb_4_tuan"
    );
    $stmt->execute($wp);
    $h = $stmt->fetch();
    if ($h && (float) $h['tuan_nay'] >= 100000 && (float) $h['tuan_nay'] >= 2 * (float) $h['tb_4_tuan']) {
        $add('trung', 'Hao hụt tăng', 'Xuất huỷ tuần này ' . money($h['tuan_nay']) . ' (trung bình 4 tuần trước: ' . money($h['tb_4_tuan']) . ').', 'xuat_huy.php');
    }

    // 5. Khach no qua 30 ngay
    $cp = [$tenantId];
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS so_khach, COALESCE(SUM(c.debt), 0) AS tong_no FROM customers c
         WHERE c.tenant_id = ? AND c.debt > 0
           AND (SELECT MIN(e.created_at) FROM customer_debt_entries e WHERE e.customer_id = c.id AND e.amount > 0) < DATE_SUB(NOW(), INTERVAL 30 DAY)"
    );
    $stmt->execute($cp);
    $c = $stmt->fetch();
    if ($c && (int) $c['so_khach'] > 0) {
        $add('trung', 'Khách nợ quá 30 ngày', (int) $c['so_khach'] . ' khách, tổng ' . money($c['tong_no']) . '.', 'customer_debt_reminders.php');
    }

    // 6. Phieu nhap qua han tra NCC
    $due = layPhieuNhapDenHan($pdo, $tenantId, $branchId, -1, 200);   // -1: chi qua han (han < hom nay)
    if ($due) {
        $tong = array_sum(array_map(static fn($r) => (float) $r['con_no'], $due));
        $add('cao', 'Quá hạn trả nhà cung cấp', count($due) . ' phiếu nhập, tổng nợ ' . money($tong) . '.', 'cong_no.php');
    }

    // 7. Hang da het han van con ton
    $ep = [$tenantId];
    $ew = phamViChiNhanh($branchId, $ep, 'b.branch_id');
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS so_lo, COALESCE(SUM(b.quantity * p.cost_price), 0) AS gia_tri
         FROM product_batches b JOIN products p ON p.id = b.product_id JOIN branches br ON br.id = b.branch_id
         WHERE br.tenant_id = ? AND b.quantity > 0 AND b.expiry_date < CURDATE()$ew"
    );
    $stmt->execute($ep);
    $e = $stmt->fetch();
    if ($e && (int) $e['so_lo'] > 0) {
        $add('cao', 'Hàng đã hết hạn chưa xử lý', (int) $e['so_lo'] . ' lô, giá trị vốn khoảng ' . money($e['gia_tri']) . '. Cần xuất huỷ.', 'batches.php?f=het');
    }

    // 8. Tra hang nhieu
    $rp = [$tenantId];
    $rw = phamViChiNhanh($branchId, $rp, 'o.branch_id');
    $stmt = $pdo->prepare(
        "SELECT u.name, COUNT(*) AS so_phieu, COALESCE(SUM(r.refund_amount), 0) AS tong_hoan
         FROM order_returns r JOIN orders o ON o.id = r.order_id JOIN branches b ON b.id = o.branch_id JOIN users u ON u.id = r.created_by_id
         WHERE b.tenant_id = ? AND r.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)$rw
         GROUP BY u.id, u.name HAVING so_phieu >= 5"
    );
    $stmt->execute($rp);
    foreach ($stmt->fetchAll() as $r) {
        $add('thap', 'Trả hàng nhiều: ' . $r['name'], (int) $r['so_phieu'] . ' phiếu trả hàng (hoàn ' . money($r['tong_hoan']) . ') trong 30 ngày.', 'order_returns.php');
    }

    // 9. Gio la: huy don / giam gia lon vao khung gio rat it khach (gio chiem <3% tong don trong 60 ngay)
    $hp = [$tenantId];
    $hw = phamViChiNhanh($branchId, $hp, 'o.branch_id');
    $stmt = $pdo->prepare(
        "SELECT HOUR(o.created_at) AS h, COUNT(*) AS c FROM orders o JOIN branches b ON b.id = o.branch_id
         WHERE b.tenant_id = ? AND o.status NOT IN ('CANCELLED','DRAFT') AND o.created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY)$hw
         GROUP BY HOUR(o.created_at)"
    );
    $stmt->execute($hp);
    $perHour = [];
    $totalOrders = 0;
    foreach ($stmt->fetchAll() as $r) {
        $perHour[(int) $r['h']] = (int) $r['c'];
        $totalOrders += (int) $r['c'];
    }
    if ($totalOrders >= 50) {
        $normal = array_keys(array_filter($perHour, static fn($c) => $c / $totalOrders >= 0.03));
        $odd = array_values(array_diff(range(0, 23), $normal));
        if ($normal && $odd) {
            $in = implode(',', array_map('intval', $odd));
            $stmt = $pdo->prepare(
                "SELECT u.name, COUNT(*) AS n, GROUP_CONCAT(DISTINCT HOUR(h.changed_at) ORDER BY HOUR(h.changed_at)) AS gio
                 FROM order_status_history h JOIN orders o ON o.id = h.order_id JOIN branches b ON b.id = o.branch_id JOIN users u ON u.id = h.changed_by_id
                 WHERE b.tenant_id = ? AND h.to_status = 'CANCELLED' AND h.changed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                   AND HOUR(h.changed_at) IN ($in)$hw
                 GROUP BY u.id, u.name HAVING n >= 2"
            );
            $stmt->execute($hp);
            foreach ($stmt->fetchAll() as $r) {
                $add('trung', 'Huỷ đơn vào giờ lạ: ' . $r['name'], (int) $r['n'] . ' lần huỷ đơn lúc ' . $r['gio'] . 'h (khung giờ rất ít khách) trong 30 ngày.', 'orders.php?status=CANCELLED');
            }
            $stmt = $pdo->prepare(
                "SELECT u.name, COUNT(*) AS n, SUM(o.discount) AS giam
                 FROM orders o JOIN branches b ON b.id = o.branch_id JOIN users u ON u.id = o.sold_by_id
                 WHERE b.tenant_id = ? AND o.status <> 'CANCELLED' AND o.sub_total > 0 AND o.discount >= 20000 AND o.discount / o.sub_total >= 0.25
                   AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND HOUR(o.created_at) IN ($in)$hw
                 GROUP BY u.id, u.name HAVING n >= 2"
            );
            $stmt->execute($hp);
            foreach ($stmt->fetchAll() as $r) {
                $add('trung', 'Giảm giá lớn vào giờ lạ: ' . $r['name'], (int) $r['n'] . ' đơn giảm từ 25% (tổng giảm ' . money($r['giam']) . ') vào khung giờ rất ít khách.', 'orders.php');
            }
        }
    }

    // 10. Mot nguoi huy phan lon don (>=70% cua tat ca lan huy, tu 6 lan huy, co tu 2 nguoi ban tro len)
    $stmt = $pdo->prepare(
        "SELECT u.name, COUNT(*) AS huy
         FROM order_status_history h JOIN orders o ON o.id = h.order_id JOIN branches b ON b.id = o.branch_id JOIN users u ON u.id = h.changed_by_id
         WHERE b.tenant_id = ? AND h.to_status = 'CANCELLED' AND h.changed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)$hw
         GROUP BY u.id, u.name ORDER BY huy DESC"
    );
    $stmt->execute($hp);
    $huyRows = $stmt->fetchAll();
    $tongHuy = array_sum(array_map(static fn($r) => (int) $r['huy'], $huyRows));
    if ($tongHuy >= 6 && count($huyRows) >= 2 && (int) $huyRows[0]['huy'] / $tongHuy >= 0.7) {
        $add('trung', 'Một người huỷ phần lớn đơn: ' . $huyRows[0]['name'], (int) $huyRows[0]['huy'] . '/' . $tongHuy . ' lần huỷ đơn trong 30 ngày do cùng 1 người thực hiện.', 'orders.php?status=CANCELLED');
    }

    // 11. Ket lech lap lai: cung 1 nguoi dong ca >=3 lan thieu tien (>=10.000d moi lan) trong 30 ngay
    $kp = [$tenantId];
    $kw = phamViChiNhanh($branchId, $kp, 's.branch_id');
    $stmt = $pdo->prepare(
        "SELECT u.name, COUNT(*) AS so_ca, SUM(-s.difference) AS tong_thieu
         FROM pos_shifts s JOIN branches br ON br.id = s.branch_id JOIN users u ON u.id = s.closed_by_id
         WHERE br.tenant_id = ? AND s.status = 'CLOSED' AND s.closed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND s.difference <= -10000$kw
         GROUP BY u.id, u.name HAVING so_ca >= 3"
    );
    $stmt->execute($kp);
    foreach ($stmt->fetchAll() as $r) {
        $add('cao', 'Két thiếu tiền lặp lại: ' . $r['name'], (int) $r['so_ca'] . ' ca đóng bị thiếu tiền mặt, tổng thiếu ' . money($r['tong_thieu']) . ' trong 30 ngày.', 'ca_ban_hang.php');
    }

    // 12. Hang "mat dan": kiem kho nhieu lan deu thieu (>=2 phieu da can bang trong 90 ngay, tong gia tri thieu >=50.000d)
    $mp = [$tenantId];
    $mw = phamViChiNhanh($branchId, $mp, 'st.branch_id');
    $stmt = $pdo->prepare(
        "SELECT p.name, COUNT(DISTINCT st.id) AS lan, SUM(si.system_qty - si.counted_qty) AS thieu, SUM((si.system_qty - si.counted_qty) * p.cost_price) AS gia_tri
         FROM stock_take_items si JOIN stock_takes st ON st.id = si.take_id AND st.status = 'BALANCED'
         JOIN products p ON p.id = si.product_id JOIN branches br ON br.id = st.branch_id
         WHERE br.tenant_id = ? AND st.balanced_at >= DATE_SUB(NOW(), INTERVAL 90 DAY) AND si.counted_qty < si.system_qty$mw
         GROUP BY p.id, p.name HAVING lan >= 2 AND gia_tri >= 50000 ORDER BY gia_tri DESC LIMIT 3"
    );
    $stmt->execute($mp);
    foreach ($stmt->fetchAll() as $r) {
        $add('cao', 'Hàng hao hụt lặp lại: ' . $r['name'], 'Thiếu ' . fmtQty($r['thieu']) . ' qua ' . (int) $r['lan'] . ' lần kiểm kho (giá trị vốn ' . money($r['gia_tri']) . ') — nghi mất cắp, hỏng hoặc nhập/bán sai.', 'stock_takes.php');
    }

    // 13. Ban duoi gia von: dong hang co don gia < gia von luc ban (>=3 dong hoac thiet >=50.000d trong 30 ngay), theo nguoi ban
    $bp2 = [$tenantId];
    $bw2 = phamViChiNhanh($branchId, $bp2, 'o.branch_id');
    $stmt = $pdo->prepare(
        "SELECT u.name, COUNT(*) AS so_dong, SUM((oi.cost_price - oi.unit_price) * oi.quantity) AS thiet
         FROM order_items oi JOIN orders o ON o.id = oi.order_id JOIN branches b ON b.id = o.branch_id JOIN users u ON u.id = o.sold_by_id
         WHERE b.tenant_id = ? AND o.status NOT IN ('CANCELLED','DRAFT') AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
           AND oi.cost_price IS NOT NULL AND oi.cost_price > 0 AND oi.unit_price < oi.cost_price$bw2
         GROUP BY u.id, u.name HAVING so_dong >= 3 OR thiet >= 50000 ORDER BY thiet DESC"
    );
    $stmt->execute($bp2);
    foreach ($stmt->fetchAll() as $r) {
        $add('trung', 'Bán dưới giá vốn: ' . $r['name'], (int) $r['so_dong'] . ' dòng hàng bán thấp hơn giá vốn trong 30 ngày, thiệt khoảng ' . money($r['thiet']) . '. Xem lại giá bán / chiết khấu.', 'orders.php');
    }

    // 14. Hang bi tra nhieu: >=3 lan tra hoac (>=2 lan va >=10% so luong ban) trong 60 ngay
    $tp = [$tenantId];
    $tw = phamViChiNhanh($branchId, $tp, 'o.branch_id');
    $stmt = $pdo->prepare(
        "SELECT p.name, COUNT(*) AS lan, SUM(ri.quantity) AS sl_tra,
                (SELECT COALESCE(SUM(oi2.quantity), 0) FROM order_items oi2 JOIN orders o2 ON o2.id = oi2.order_id
                  WHERE oi2.product_id = p.id AND o2.status NOT IN ('CANCELLED','DRAFT') AND o2.created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY)) AS sl_ban
         FROM order_return_items ri JOIN order_returns r ON r.id = ri.return_id JOIN orders o ON o.id = r.order_id
         JOIN branches b ON b.id = o.branch_id JOIN products p ON p.id = ri.product_id
         WHERE b.tenant_id = ? AND r.created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY)$tw
         GROUP BY p.id, p.name HAVING lan >= 3 OR (lan >= 2 AND sl_ban > 0 AND sl_tra / sl_ban >= 0.1)
         ORDER BY sl_tra DESC LIMIT 3"
    );
    $stmt->execute($tp);
    foreach ($stmt->fetchAll() as $r) {
        $add('thap', 'Hàng bị trả nhiều: ' . $r['name'], (int) $r['lan'] . ' lần trả, ' . fmtQty($r['sl_tra']) . '/' . fmtQty($r['sl_ban']) . ' đã bán trong 60 ngày — kiểm tra chất lượng hoặc nhà cung cấp.', 'order_returns.php');
    }

    $rank = ['cao' => 0, 'trung' => 1, 'thap' => 2];
    usort($alerts, static fn($a, $b) => $rank[$a['muc']] <=> $rank[$b['muc']]);
    return $alerts;
}

/** Khoang thoi gian tu cau hoi (da bo dau). Mac dinh hom nay. Tra [from, to, nhan]. */
function khoangThoiGianTuCauHoi(string $ascii): array
{
    $today = strtotime('today');
    $fmt = static fn(int $t): string => date('Y-m-d H:i:s', $t);
    if (preg_match('/hom qua/', $ascii)) {
        return [$fmt($today - 86400), $fmt($today - 1), 'hôm qua'];
    }
    if (preg_match('/tuan truoc/', $ascii)) {
        $mon = strtotime('monday this week') - 7 * 86400;
        return [$fmt($mon), $fmt($mon + 7 * 86400 - 1), 'tuần trước'];
    }
    if (preg_match('/tuan nay|tuan/', $ascii)) {
        return [$fmt(strtotime('monday this week')), $fmt(time()), 'tuần này'];
    }
    if (preg_match('/thang truoc/', $ascii)) {
        $first = strtotime('first day of last month midnight');
        return [$fmt($first), $fmt(strtotime('first day of this month midnight') - 1), 'tháng trước'];
    }
    if (preg_match('/thang nay|thang/', $ascii)) {
        return [$fmt(strtotime('first day of this month midnight')), $fmt(time()), 'tháng này'];
    }
    if (preg_match('/(\d{1,3})\s*ngay/', $ascii, $m) && (int) $m[1] > 0) {
        $n = min(365, (int) $m[1]);
        return [$fmt(time() - $n * 86400), $fmt(time()), $n . ' ngày qua'];
    }
    return [$fmt($today), $fmt(time()), 'hôm nay'];
}

/**
 * Hoi dap tieng Viet ve so lieu cua hang. Hieu cau hoi bang tu khoa (khong gui du lieu ra ngoai).
 * Tra ['answer' => [dong, ...], 'links' => [[nhan, url], ...], 'intent' => ten].
 */
function traLoiCauHoi(PDO $pdo, int $tenantId, int $branchId, string $question): array
{
    $q = trim($question);
    $a = boDau($q);
    $links = [];
    [$from, $to, $label] = khoangThoiGianTuCauHoi($a);
    $bp = [$tenantId, $from, $to];
    $bw = phamViChiNhanh($branchId, $bp, 'o.branch_id');
    $salesBase = "FROM orders o JOIN branches b ON b.id = o.branch_id WHERE b.tenant_id = ? AND o.status NOT IN ('CANCELLED','DRAFT') AND o.created_at BETWEEN ? AND ?$bw";
    $res = static fn(string $intent, array $lines, array $lk = []) => ['intent' => $intent, 'answer' => $lines, 'links' => $lk];

    // Bat thuong / canh bao
    if (preg_match('/bat thuong|canh bao|co van de|gian lan|that thoat/', $a)) {
        $al = phatHienBatThuong($pdo, $tenantId, $branchId);
        if (!$al) {
            return $res('bat_thuong', ['Không phát hiện điều gì bất thường trong 30 ngày qua.']);
        }
        $lines = ['Có ' . count($al) . ' cảnh báo, nổi bật:'];
        foreach (array_slice($al, 0, 4) as $x) {
            $lines[] = '• ' . $x['tieu_de'] . ' — ' . $x['chi_tiet'];
        }
        return $res('bat_thuong', $lines, [['Xem tất cả cảnh báo', 'canh_bao.php']]);
    }
    // Nhip ban hom nay
    if (preg_match('/hom nay (co )?(on|te|thap|cao)|so voi moi khi|nhip ban|ban cham hon|dang ban the nao|hom nay the nao/', $a)) {
        $n = nhipBanHomNay($pdo, $tenantId, $branchId);
        if ($n === null) {
            return $res('nhip_ban', ['Chưa đủ dữ liệu để so sánh (cần sau 9h và ít nhất 2 tuần có bán cùng khung giờ).']);
        }
        $pct = $n['pct'] !== null ? round($n['pct']) : 0;
        $lines = ['Đến giờ này hôm nay bán ' . money($n['hom_nay']) . '; cùng giờ các ' . $n['so_tuan'] . ' tuần trước (cùng thứ) trung bình ' . money($n['tb']) . ' (' . ($pct >= 0 ? '+' : '') . $pct . '%).'];
        $lines[] = $pct <= -30 ? 'Hôm nay đang bán chậm hơn thường khá nhiều.' : ($pct >= 30 ? 'Hôm nay đang bán tốt hơn thường.' : 'Nhịp bán đang bình thường.');
        return $res('nhip_ban', $lines, [['Giờ cao điểm', 'phan_tich.php#gio-cao-diem']]);
    }
    // Du bao doanh thu thang
    if (preg_match('/cuoi thang|du bao doanh thu|thang nay se|du kien thang|thang nay dat/', $a)) {
        $m = duBaoThang($pdo, $tenantId, $branchId);
        $lines = ['Tháng này đến nay: ' . money($m['mtd']) . ' (' . $m['so_ngay'] . '/' . $m['ngay_trong_thang'] . ' ngày). Theo tốc độ hiện tại, dự báo cả tháng khoảng ' . money($m['du_bao']) . ', lãi gộp ước tính ' . money($m['du_bao_lai']) . '.'];
        if ($m['pct'] !== null) {
            $lines[] = 'So với tháng trước (' . money($m['thang_truoc']) . '): ' . ($m['pct'] >= 0 ? '+' : '') . round($m['pct']) . '%.';
        }
        $lines[] = 'Đây là ước tính thô theo trung bình ngày, chưa tính cuối tuần/ngày lễ.';
        return $res('du_bao_thang', $lines, [['Phân tích kinh doanh', 'phan_tich.php']]);
    }
    // Kiem tra du lieu
    if (preg_match('/kiem tra du lieu|du lieu (loi|sai|thieu)|san pham trung|ban duoi gia von|gia ban thap hon|ton kho am/', $a)) {
        $secs = kiemTraDuLieu($pdo, $tenantId);
        $bad = array_filter($secs, static fn($s) => $s['total'] > 0);
        if (!$bad) {
            return $res('du_lieu', ['Dữ liệu sạch: không phát hiện vấn đề nào.'], [['Kiểm tra dữ liệu', 'kiem_tra_du_lieu.php']]);
        }
        $lines = ['Phát hiện vấn đề dữ liệu:'];
        foreach ($bad as $s) {
            $lines[] = '• ' . $s['title'] . ': ' . $s['total'];
        }
        return $res('du_lieu', $lines, [['Xem và sửa', 'kiem_tra_du_lieu.php']]);
    }
    // Suc khoe cua hang
    if (preg_match('/suc khoe|diem cua hang|cua hang the nao|tinh hinh chung|danh gia cua hang/', $a)) {
        $sk = diemSucKhoe($pdo, $tenantId, $branchId);
        $lines = ['Điểm sức khỏe cửa hàng: ' . $sk['diem'] . '/100 — ' . $sk['xep_loai'] . '.'];
        foreach ($sk['muc'] as [$ten, $d, $max, $ct, $tip]) {
            if ($d < $max * 0.6) {
                $lines[] = '• ' . $ten . ' (' . round($d) . '/' . $max . '): ' . $ct . ($tip !== '' ? ' — ' . $tip : '');
            }
        }
        return $res('suc_khoe', $lines, [['Chi tiết điểm sức khỏe', 'phan_tich.php#suc-khoe']]);
    }
    // Gio cao diem
    if (preg_match('/gio cao diem|dong khach|gio dong|may gio dong|xep ca|can may nguoi|can bao nhieu nhan vien/', $a)) {
        $c = gioCaoDiem($pdo, $tenantId, $branchId, 12);
        if (!$c['top']) {
            return $res('gio_cao_diem', ['Chưa có dữ liệu đơn hàng 8 tuần để xác định giờ cao điểm.']);
        }
        $tt = ['Chủ nhật', 'Thứ 2', 'Thứ 3', 'Thứ 4', 'Thứ 5', 'Thứ 6', 'Thứ 7'];
        $lines = ['Khung giờ đông khách nhất (trung bình 8 tuần):'];
        foreach (array_slice($c['top'], 0, 4) as $t) {
            $lines[] = '• ' . $tt[$t['thu']] . ' ' . $t['gio'] . 'h — khoảng ' . number_format($t['don'], 1) . ' đơn, cần ~' . ($c['nv'][$t['thu']][$t['gio']] ?? 1) . ' nhân viên';
        }
        return $res('gio_cao_diem', $lines, [['Biểu đồ giờ cao điểm', 'phan_tich.php#gio-cao-diem']]);
    }
    // Ban tin / viec can lam
    if (preg_match('/ban tin|tom tat|lam gi hom nay|viec can lam|(can|phai|nen) lam gi|viec hom nay/', $a)) {
        $bt = banTinSang($pdo, $tenantId, $branchId);
        $h = $bt['hom_qua'];
        $t = $bt['tuan_truoc'];
        $so = $t['doanh_thu'] > 0 ? ' (' . ($h['doanh_thu'] >= $t['doanh_thu'] ? '+' : '') . round(($h['doanh_thu'] / $t['doanh_thu'] - 1) * 100) . '% so với cùng thứ tuần trước)' : '';
        $lines = ['Hôm qua: doanh thu ' . money($h['doanh_thu']) . ' từ ' . $h['so_don'] . ' đơn, lãi gộp ước tính ' . money($h['lai']) . $so . '.'];
        $lines[] = $bt['viec'] ? 'Việc nên làm hôm nay:' : 'Hôm nay chưa có việc gấp nào.';
        foreach ($bt['viec'] as $v) {
            $lines[] = '• ' . $v[0] . ' — ' . $v[1];
        }
        return $res('ban_tin', $lines, [['Phân tích kinh doanh', 'phan_tich.php']]);
    }
    // Dong tien
    if (preg_match('/dong tien|thieu tien|du tien|quy tien|tien mat sap|het tien/', $a)) {
        $dt = duBaoDongTien($pdo, $tenantId, $branchId, 14);
        $lines = ['Quỹ hiện tại ' . money($dt['quy_hien_tai']) . '. Trong 14 ngày tới phải trả nhà cung cấp ' . money($dt['ncc_tong']) . '.'];
        $lines[] = $dt['thang_am'] !== null
            ? 'Cảnh báo: dự kiến THIẾU TIỀN từ ngày ' . date('d/m', strtotime($dt['thang_am'])) . ' (thấp nhất ~' . money($dt['thap_nhat']) . ').'
            : 'Quỹ dự kiến không âm (thấp nhất ~' . money($dt['thap_nhat']) . ').';
        return $res('dong_tien', $lines, [['Dự báo dòng tiền', 'phan_tich.php#dong-tien']]);
    }
    // Khach sap bo
    if (preg_match('/khach (sap )?bo|khach lau khong|khach quen|khach vang|giu chan/', $a)) {
        $rows = khachSapBo($pdo, $tenantId, 5);
        if (!$rows) {
            return $res('khach_bo', ['Chưa có khách quen nào có dấu hiệu bỏ đi.']);
        }
        $lines = ['Khách quen đang vắng lâu bất thường:'];
        foreach ($rows as $r) {
            $lines[] = '• ' . $r['name'] . ($r['phone'] ? ' (' . $r['phone'] . ')' : '') . ' — vắng ' . (int) $r['ngay_vang'] . ' ngày, thường mua ~' . (int) round((float) $r['khoang_tb']) . ' ngày/lần';
        }
        return $res('khach_bo', $lines, [['Danh sách đầy đủ', 'phan_tich.php#khach-bo']]);
    }
    // ABC
    if (preg_match('/nhom a|abc|hang a\b|nen dau tu|nen tap trung/', $a)) {
        $ab = phanTichABC($pdo, $tenantId, $branchId, 90);
        if (!$ab['rows']) {
            return $res('abc', ['Chưa có dữ liệu bán hàng 90 ngày để phân tích ABC.']);
        }
        $lines = ['Phân tích ABC 90 ngày: nhóm A ' . $ab['dem']['A'] . ' mặt hàng (tạo ~80% lãi), B ' . $ab['dem']['B'] . ', C ' . $ab['dem']['C'] . '.', 'Nhóm A nên luôn đủ hàng:'];
        foreach (array_slice(array_filter($ab['rows'], static fn($r) => $r['nhom'] === 'A'), 0, 5) as $r) {
            $lines[] = '• ' . $r['name'] . ' — lãi ' . money($r['lai']) . ' (' . number_format($r['ty_le'], 1) . '%)';
        }
        return $res('abc', $lines, [['Phân tích ABC', 'phan_tich.php#abc']]);
    }
    // Combo
    if (preg_match('/combo|ban kem|mua chung|ban cung/', $a)) {
        $cb = comboGoiY($pdo, $tenantId, 4);
        if (!$cb) {
            return $res('combo', ['Chưa có cặp hàng nào được mua chung đủ nhiều (từ 3 đơn trong 90 ngày).']);
        }
        $lines = ['Combo gợi ý (hay mua chung):'];
        foreach ($cb as $c) {
            $lines[] = '• ' . $c['a'] . ' + ' . $c['b'] . ' — ' . $c['so_don'] . ' đơn, giá combo ~' . money($c['gia_combo']) . ' (giảm ' . $c['giam_pct'] . '%)';
        }
        return $res('combo', $lines, [['Combo gợi ý', 'phan_tich.php#combo']]);
    }
    // Hang can date / het han
    if (preg_match('/can date|can han|sap het han|het han|han su dung/', $a)) {
        $rows = layLoCanHan($pdo, $tenantId, $branchId, expiryWarnDays(), 6);
        if (!$rows) {
            return $res('can_han', ['Hiện không có lô hàng nào hết hạn hoặc sắp hết hạn (trong ' . expiryWarnDays() . ' ngày).']);
        }
        $lines = ['Hàng hết hạn / sắp hết hạn:'];
        foreach ($rows as $r) {
            $het = strtotime($r['expiry_date']) < strtotime('today');
            $lines[] = '• ' . $r['product_name'] . ' (lô ' . $r['lot_number'] . ', còn ' . fmtQty($r['quantity']) . ') — ' . ($het ? 'ĐÃ HẾT HẠN ' : 'hạn ') . date('d/m/Y', strtotime($r['expiry_date']));
        }
        return $res('can_han', $lines, [['Gợi ý xả hàng', 'ton_cham.php'], ['Quản lý lô hàng', 'batches.php']]);
    }
    // Hang e / ton lau
    if (preg_match('/hang e|ton lau|ban cham|khong ban duoc|chon von/', $a)) {
        $rows = layHangTonLau($pdo, $tenantId, $branchId, 30, 5);
        if (!$rows) {
            return $res('ton_lau', ['Không có mặt hàng nào ế trong 30 ngày qua.']);
        }
        $lines = ['Hàng tồn mà 30 ngày qua không bán được cái nào (vốn đang chôn):'];
        $tong = 0.0;
        foreach ($rows as $r) {
            $lines[] = '• ' . $r['name'] . ' — tồn ' . fmtQty($r['qty']) . ', vốn ' . money($r['von_chon']);
            $tong += $r['von_chon'];
        }
        return $res('ton_lau', $lines, [['Xem đầy đủ', 'ton_cham.php']]);
    }
    // Sap het hang / can nhap
    if (preg_match('/sap het hang|can nhap|nen nhap|het hang|thieu hang|nhap them/', $a) && !preg_match('/ton kho cua/', $a)) {
        $bid = $branchId ?: (int) $pdo->query('SELECT id FROM branches WHERE tenant_id = ' . (int) $tenantId . ' ORDER BY id LIMIT 1')->fetchColumn();
        $rows = array_filter(duBaoNhapHang($pdo, $tenantId, $bid, 14), static fn($r) => $r['goi_y'] > 0);
        if (!$rows) {
            return $res('can_nhap', ['Tồn kho đang đủ bán cho 14 ngày tới, chưa cần nhập thêm.']);
        }
        $lines = ['Nên nhập thêm (đủ bán 14 ngày):'];
        foreach (array_slice($rows, 0, 6) as $r) {
            $lines[] = '• ' . $r['name'] . ' — tồn ' . fmtQty($r['ton']) . ($r['so_ngay'] !== null ? ', đủ bán ~' . (int) $r['so_ngay'] . ' ngày' : '') . ', nên nhập ' . fmtQty($r['goi_y']);
        }
        return $res('can_nhap', $lines, [['Gợi ý nhập hàng', 'reorder_suggestions.php']]);
    }
    // Khach no
    if (preg_match('/khach (nao |hang )?(dang )?no(?![a-z])|ai (dang )?no(?![a-z])|cong no phai thu|no cua khach/', $a)) {
        $stmt = $pdo->prepare('SELECT name, debt FROM customers WHERE tenant_id = ? AND debt > 0 ORDER BY debt DESC LIMIT 5');
        $stmt->execute([$tenantId]);
        $rows = $stmt->fetchAll();
        $tong = (float) $pdo->query('SELECT COALESCE(SUM(debt),0) FROM customers WHERE debt > 0 AND tenant_id = ' . (int) $tenantId)->fetchColumn();
        if (!$rows) {
            return $res('khach_no', ['Hiện không có khách nào đang nợ.']);
        }
        $lines = ['Tổng khách đang nợ: ' . money($tong) . '. Nợ nhiều nhất:'];
        foreach ($rows as $r) {
            $lines[] = '• ' . $r['name'] . ' — ' . money($r['debt']);
        }
        return $res('khach_no', $lines, [['Công nợ phải thu', 'cong_no.php'], ['Nhắc nợ', 'customer_debt_reminders.php']]);
    }
    // No NCC
    if (preg_match('/no nha cung cap|no ncc|phai tra|cong no phai tra|no nguoi ban/', $a)) {
        $stmt = $pdo->prepare('SELECT name, debt FROM suppliers WHERE tenant_id = ? AND debt > 0 ORDER BY debt DESC LIMIT 5');
        $stmt->execute([$tenantId]);
        $rows = $stmt->fetchAll();
        if (!$rows) {
            return $res('no_ncc', ['Hiện không còn nợ nhà cung cấp nào.']);
        }
        $lines = ['Nợ nhà cung cấp nhiều nhất:'];
        foreach ($rows as $r) {
            $lines[] = '• ' . $r['name'] . ' — ' . money($r['debt']);
        }
        $due = layPhieuNhapDenHan($pdo, $tenantId, $branchId, 7, 5);
        if ($due) {
            $lines[] = 'Sắp/đã đến hạn trả: ' . count($due) . ' phiếu (gần nhất ' . date('d/m/Y', strtotime($due[0]['due_date'])) . ').';
        }
        return $res('no_ncc', $lines, [['Công nợ phải trả', 'cong_no.php']]);
    }
    // Ban chay / lai nhat
    if (preg_match('/ban chay|nhieu nhat|lai nhat|loi nhuan nhat|top/', $a) && preg_match('/mat hang|san pham|hang|mon|nao/', $a)) {
        $byProfit = (bool) preg_match('/lai|loi nhuan/', $a);
        $sql = "SELECT p.name, SUM(oi.quantity) AS sl, SUM(oi.line_total) AS dt, SUM(oi.line_total - oi.quantity * COALESCE(oi.cost_price, 0)) AS lai
                FROM order_items oi JOIN orders o ON o.id = oi.order_id JOIN branches b ON b.id = o.branch_id JOIN products p ON p.id = oi.product_id
                WHERE b.tenant_id = ? AND o.status NOT IN ('CANCELLED','DRAFT') AND o.created_at BETWEEN ? AND ?$bw
                GROUP BY p.id, p.name ORDER BY " . ($byProfit ? 'lai' : 'sl') . ' DESC LIMIT 5';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bp);
        $rows = $stmt->fetchAll();
        if (!$rows) {
            return $res('ban_chay', ["Chưa có dữ liệu bán hàng $label."]);
        }
        $lines = [($byProfit ? 'Mặt hàng lãi nhất ' : 'Mặt hàng bán chạy nhất ') . $label . ':'];
        foreach ($rows as $i => $r) {
            $lines[] = ($i + 1) . '. ' . $r['name'] . ' — ' . fmtQty($r['sl']) . ' bán, doanh thu ' . money($r['dt']) . ($byProfit ? ', lãi gộp ~' . money($r['lai']) : '');
        }
        return $res('ban_chay', $lines, [['Báo cáo', 'reports.php']]);
    }
    // Ton kho cua <san pham>
    if (preg_match('/ton kho|con bao nhieu|con hang|con may|bao nhieu cai/', $a)) {
        $stop = ['ton', 'kho', 'cua', 'con', 'bao', 'nhieu', 'hang', 'may', 'cai', 'san', 'pham', 'mat', 'la', 'khong', 'co', 'the', 'nao', 'cho', 'toi', 'hoi', 'gi', 'so', 'luong', 'dang', 'o', 'tai', 'ban', 'thi', 'duoc'];
        $tokens = [];
        foreach (preg_split('/[\s,.?!]+/u', mb_strtolower($q, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY) as $t) {
            if (!in_array(boDau($t), $stop, true)) {
                $tokens[] = $t;
            }
        }
        if (!$tokens) {
            return $res('ton_kho', ['Bạn muốn xem tồn kho của mặt hàng nào? Ví dụ: "tồn kho sữa tươi còn bao nhiêu".']);
        }
        $where = ['p.tenant_id = ?', "p.product_type = 'PRODUCT'"];
        $params = [$tenantId];
        foreach (array_slice($tokens, 0, 4) as $t) {
            $where[] = '(p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ?)';
            $like = '%' . $t . '%';
            array_push($params, $like, $like, $like);
        }
        $ib = '';
        if ($branchId) {
            $ib = ' AND i.branch_id = ?';
            array_splice($params, 0, 0, [$branchId]);
        }
        $stmt = $pdo->prepare(
            'SELECT p.name, p.unit, COALESCE(SUM(i.quantity), 0) AS ton FROM products p
             LEFT JOIN inventory i ON i.product_id = p.id' . $ib . ' WHERE ' . implode(' AND ', $where) . ' GROUP BY p.id, p.name, p.unit ORDER BY p.name LIMIT 6'
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        if (!$rows) {
            return $res('ton_kho', ['Không tìm thấy mặt hàng nào khớp "' . implode(' ', $tokens) . '".']);
        }
        $lines = [];
        foreach ($rows as $r) {
            $lines[] = '• ' . $r['name'] . ' — còn ' . fmtQty($r['ton']) . ' ' . $r['unit'];
        }
        return $res('ton_kho', $lines, [['Quản lý kho', 'inventory.php']]);
    }
    // Lai gop
    if (preg_match('/lai|loi nhuan/', $a)) {
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(oi.line_total - oi.quantity * COALESCE(oi.cost_price, 0)), 0) AS lai_hang,
                    COALESCE(SUM(oi.line_total), 0) AS dt_hang
             FROM order_items oi JOIN orders o ON o.id = oi.order_id JOIN branches b ON b.id = o.branch_id
             WHERE b.tenant_id = ? AND o.status NOT IN ('CANCELLED','DRAFT') AND o.created_at BETWEEN ? AND ?$bw"
        );
        $stmt->execute($bp);
        $r = $stmt->fetch();
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(o.discount), 0) $salesBase");
        $stmt->execute($bp);
        $giam = (float) $stmt->fetchColumn();
        $lai = (float) $r['lai_hang'] - $giam;
        return $res('lai_gop', ["Lãi gộp ước tính $label: " . money($lai) . ' (doanh thu hàng ' . money($r['dt_hang']) . ', đã trừ chiết khấu đơn ' . money($giam) . ' và giá vốn).',
            'Đây là ước tính từ giá vốn lúc bán, chưa tính chi phí vận hành.'], [['Báo cáo chi tiết', 'reports.php']]);
    }
    // So don
    if (preg_match('/bao nhieu don|so don|may don/', $a)) {
        $stmt = $pdo->prepare("SELECT COUNT(*) $salesBase");
        $stmt->execute($bp);
        return $res('so_don', ["Số đơn bán $label: " . (int) $stmt->fetchColumn() . '.'], [['Danh sách đơn hàng', 'orders.php']]);
    }
    // Doanh thu
    if (preg_match('/doanh thu|doanh so|ban duoc|ban bao nhieu|thu duoc|tien ban|ban hang/', $a)) {
        $stmt = $pdo->prepare("SELECT COUNT(*) AS so_don, COALESCE(SUM(o.sub_total - o.discount), 0) AS dt $salesBase");
        $stmt->execute($bp);
        $r = $stmt->fetch();
        $tb = (int) $r['so_don'] > 0 ? money((float) $r['dt'] / (int) $r['so_don']) : '—';
        return $res('doanh_thu', ["Doanh thu $label: " . money($r['dt']) . ' từ ' . (int) $r['so_don'] . ' đơn (trung bình ' . $tb . '/đơn).'], [['Báo cáo', 'reports.php']]);
    }

    return $res('khong_hieu', [
        'Tôi chưa hiểu câu hỏi này. Bạn thử hỏi, ví dụ:',
        '• "Hôm nay bán được bao nhiêu?" / "Doanh thu tuần này"',
        '• "Mặt hàng nào bán chạy tháng này?" / "Hàng nào lãi nhất?"',
        '• "Tồn kho sữa tươi còn bao nhiêu?"',
        '• "Hàng nào sắp hết, cần nhập?" / "Hàng nào cận date?"',
        '• "Khách nào đang nợ?" / "Nợ nhà cung cấp?"',
        '• "Có gì bất thường không?" / "Hàng nào tồn lâu?"',
    ]);
}

// ======================================================================================================
// Dot 2: phan tich ABC, du bao dong tien 14 ngay, khach sap bo, combo goi y, ban tin sang
// ======================================================================================================

/** Doanh thu / lai gop uoc tinh / so don trong khoang thoi gian ($branchId = 0: ca cua hang). */
function doanhThuLai(PDO $pdo, int $tenantId, int $branchId, string $from, string $to): array
{
    $p = [$tenantId, $from, $to];
    $bw = phamViChiNhanh($branchId, $p, 'o.branch_id');
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS so_don, COALESCE(SUM(o.sub_total - o.discount), 0) AS dt
         FROM orders o JOIN branches b ON b.id = o.branch_id
         WHERE b.tenant_id = ? AND o.status NOT IN ('CANCELLED','DRAFT') AND o.created_at BETWEEN ? AND ?$bw"
    );
    $stmt->execute($p);
    $r = $stmt->fetch();
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(oi.line_total - oi.quantity * COALESCE(oi.cost_price, 0)), 0)
         FROM order_items oi JOIN orders o ON o.id = oi.order_id JOIN branches b ON b.id = o.branch_id
         WHERE b.tenant_id = ? AND o.status NOT IN ('CANCELLED','DRAFT') AND o.created_at BETWEEN ? AND ?$bw"
    );
    $stmt->execute($p);
    $laiHang = (float) $stmt->fetchColumn();
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(o.discount), 0) FROM orders o JOIN branches b ON b.id = o.branch_id
         WHERE b.tenant_id = ? AND o.status NOT IN ('CANCELLED','DRAFT') AND o.created_at BETWEEN ? AND ?$bw"
    );
    $stmt->execute($p);
    $giam = (float) $stmt->fetchColumn();
    return ['so_don' => (int) $r['so_don'], 'doanh_thu' => (float) $r['dt'], 'lai' => $laiHang - $giam];
}

/**
 * Phan tich ABC theo LAI GOP trong $days ngay: nhom A = nhom hang nho tao ~80% lai, B = ~15% tiep theo, C = phan con lai
 * (ke ca hang con ton nhung khong ban duoc cai nao). Tra ['rows' => [...], 'tong_lai' => x, 'dem' => [A=>n,B=>n,C=>n]].
 */
function phanTichABC(PDO $pdo, int $tenantId, int $branchId, int $days = 90): array
{
    $p = [$tenantId, $days];
    $bw = phamViChiNhanh($branchId, $p, 'o.branch_id');
    $stmt = $pdo->prepare(
        "SELECT p.id, p.name, p.sku, SUM(oi.quantity) AS sl,
                SUM(oi.line_total - oi.quantity * COALESCE(oi.cost_price, 0)) AS lai
         FROM order_items oi JOIN orders o ON o.id = oi.order_id JOIN branches b ON b.id = o.branch_id JOIN products p ON p.id = oi.product_id
         WHERE b.tenant_id = ? AND o.status NOT IN ('CANCELLED','DRAFT') AND o.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)$bw
         GROUP BY p.id, p.name, p.sku ORDER BY lai DESC"
    );
    $stmt->execute($p);
    $rows = $stmt->fetchAll();
    $tong = 0.0;
    foreach ($rows as $r) {
        $tong += max(0.0, (float) $r['lai']);
    }
    $cum = 0.0;
    $dem = ['A' => 0, 'B' => 0, 'C' => 0];
    foreach ($rows as &$r) {
        $lai = (float) $r['lai'];
        $before = $tong > 0 ? $cum / $tong : 1.0;   // ti le lai da tich luy TRUOC mat hang nay
        $r['nhom'] = ($lai <= 0 || $before >= 0.95) ? 'C' : ($before >= 0.80 ? 'B' : 'A');
        $cum += max(0.0, $lai);
        $r['ty_le'] = $tong > 0 ? max(0.0, $lai) / $tong * 100 : 0;
        $dem[$r['nhom']]++;
    }
    unset($r);
    return ['rows' => $rows, 'tong_lai' => $tong, 'dem' => $dem];
}

/**
 * Du bao dong tien $days ngay toi (mac dinh 14): quy hien tai (so quy) + thu du kien theo thu trong tuan (trung binh 8 tuan)
 * - chi co dinh (chi thu cong trung binh 30 ngay, khong tinh chu rut) - chi nhap hang tra ngay (TB 30 ngay) - tien tra NCC dung han.
 * Tra ['ngay' => [[date, thu, chi, chi_ncc, so_du]...], 'quy_hien_tai', 'thang_am' => ngay dau tien am hoac null, 'thap_nhat'].
 */
function duBaoDongTien(PDO $pdo, int $tenantId, int $branchId, int $days = 14): array
{
    $p = [$tenantId];
    $bw = phamViChiNhanh($branchId, $p, 'ce.branch_id');
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(CASE WHEN ce.type = 'RECEIPT' THEN ce.amount ELSE -ce.amount END), 0)
         FROM cashbook_entries ce JOIN branches b ON b.id = ce.branch_id WHERE b.tenant_id = ?$bw"
    );
    $stmt->execute($p);
    $quy = (float) $stmt->fetchColumn();

    // Thu trung binh theo thu trong tuan (1=CN..7=T7) tren 56 ngay: tong thu cua thu do / 8 tuan
    $p = [$tenantId];
    $bw = phamViChiNhanh($branchId, $p, 'ce.branch_id');
    $stmt = $pdo->prepare(
        "SELECT DAYOFWEEK(ce.created_at) AS dow, SUM(ce.amount) / 8 AS tb
         FROM cashbook_entries ce JOIN branches b ON b.id = ce.branch_id
         WHERE b.tenant_id = ? AND ce.type = 'RECEIPT' AND ce.category IS NULL AND ce.created_at >= DATE_SUB(CURDATE(), INTERVAL 56 DAY) AND ce.created_at < CURDATE()$bw
         GROUP BY DAYOFWEEK(ce.created_at)"
    );
    $stmt->execute($p);
    $thuTheoThu = [];
    foreach ($stmt->fetchAll() as $r) {
        $thuTheoThu[(int) $r['dow']] = (float) $r['tb'];
    }

    // Chi trung binh/ngay trong 30 ngay: chi thu cong (khong chu rut) va chi nhap hang tra ngay
    $p = [$tenantId];
    $bw = phamViChiNhanh($branchId, $p, 'ce.branch_id');
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(CASE WHEN ce.auto_generated = 0 AND ce.category IS NULL THEN ce.amount ELSE 0 END), 0) / 30 AS co_dinh,
                COALESCE(SUM(CASE WHEN ce.receipt_id IS NOT NULL THEN ce.amount ELSE 0 END), 0) / 30 AS nhap_hang
         FROM cashbook_entries ce JOIN branches b ON b.id = ce.branch_id
         WHERE b.tenant_id = ? AND ce.type = 'PAYMENT' AND ce.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)$bw"
    );
    $stmt->execute($p);
    $c = $stmt->fetch();
    $chiNgay = (float) $c['co_dinh'] + (float) $c['nhap_hang'];

    // Tien tra NCC theo han (qua han tinh vao hom nay)
    $ncc = layPhieuNhapDenHan($pdo, $tenantId, $branchId, $days, 500);
    $nccTheoNgay = [];
    foreach ($ncc as $r) {
        $d = max(date('Y-m-d'), $r['due_date']);
        $nccTheoNgay[$d] = ($nccTheoNgay[$d] ?? 0.0) + (float) $r['con_no'];
    }

    $ngay = [];
    $soDu = $quy;
    $thapNhat = $quy;
    $thangAm = null;
    for ($i = 0; $i < $days; $i++) {
        $t = strtotime("+$i days", strtotime('today'));
        $d = date('Y-m-d', $t);
        $thu = $thuTheoThu[(int) date('w', $t) + 1] ?? 0.0;
        $chiNcc = $nccTheoNgay[$d] ?? 0.0;
        $soDu += $thu - $chiNgay - $chiNcc;
        $thapNhat = min($thapNhat, $soDu);
        if ($soDu < 0 && $thangAm === null) {
            $thangAm = $d;
        }
        $ngay[] = ['date' => $d, 'thu' => $thu, 'chi' => $chiNgay, 'chi_ncc' => $chiNcc, 'so_du' => $soDu];
    }
    return ['ngay' => $ngay, 'quy_hien_tai' => $quy, 'thang_am' => $thangAm, 'thap_nhat' => $thapNhat,
        'thu_tb' => $thuTheoThu ? array_sum($thuTheoThu) / 7 : 0.0, 'chi_tb' => $chiNgay, 'ncc_tong' => array_sum($nccTheoNgay)];
}

/** Khach quen sap "bo": it nhat 3 don, da lau hon 2x khoang cach mua trung binh cua chinh ho (toi thieu 21 ngay), trong 365 ngay. */
function khachSapBo(PDO $pdo, int $tenantId, int $limit = 40): array
{
    $stmt = $pdo->prepare(
        "SELECT c.id, c.name, c.phone, c.debt, COUNT(*) AS so_don, SUM(o.total_amount) AS tong_mua,
                MAX(o.created_at) AS lan_cuoi, MIN(o.created_at) AS lan_dau,
                DATEDIFF(NOW(), MAX(o.created_at)) AS ngay_vang,
                DATEDIFF(MAX(o.created_at), MIN(o.created_at)) / (COUNT(*) - 1) AS khoang_tb
         FROM customers c JOIN orders o ON o.customer_id = c.id AND o.status NOT IN ('CANCELLED','DRAFT')
         WHERE c.tenant_id = ?
         GROUP BY c.id, c.name, c.phone, c.debt
         HAVING so_don >= 3 AND ngay_vang >= 21 AND ngay_vang <= 365 AND ngay_vang >= 2 * khoang_tb
         ORDER BY tong_mua DESC LIMIT " . (int) $limit
    );
    $stmt->execute([$tenantId]);
    return $stmt->fetchAll();
}

/** Combo goi y tu cac cap hang hay duoc mua chung (90 ngay, tu 3 don): gia combo giam nhe 5%, khong thap hon tong gia von. */
function comboGoiY(PDO $pdo, int $tenantId, int $limit = 10): array
{
    $stmt = $pdo->prepare(
        "SELECT a.product_id AS pa, b.product_id AS pb, COUNT(DISTINCT a.order_id) AS so_don
         FROM order_items a
         JOIN order_items b ON b.order_id = a.order_id AND b.product_id > a.product_id
         JOIN orders o ON o.id = a.order_id JOIN branches br ON br.id = o.branch_id
         WHERE br.tenant_id = ? AND o.status NOT IN ('CANCELLED','DRAFT') AND o.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
         GROUP BY a.product_id, b.product_id HAVING so_don >= 3
         ORDER BY so_don DESC LIMIT " . (int) $limit
    );
    $stmt->execute([$tenantId]);
    $pairs = $stmt->fetchAll();
    if (!$pairs) {
        return [];
    }
    $ids = [];
    foreach ($pairs as $r) {
        $ids[(int) $r['pa']] = true;
        $ids[(int) $r['pb']] = true;
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge(array_keys($ids), [$tenantId]);
    $stmt = $pdo->prepare("SELECT id, name, sell_price, cost_price FROM products WHERE id IN ($in) AND tenant_id = ?");
    $stmt->execute($params);
    $prod = [];
    foreach ($stmt->fetchAll() as $r) {
        $prod[(int) $r['id']] = $r;
    }
    $out = [];
    foreach ($pairs as $r) {
        $x = $prod[(int) $r['pa']] ?? null;
        $y = $prod[(int) $r['pb']] ?? null;
        if (!$x || !$y) {
            continue;
        }
        $le = (float) $x['sell_price'] + (float) $y['sell_price'];
        $von = (float) $x['cost_price'] + (float) $y['cost_price'];
        $gia = max($von, floor($le * 0.95 / 500) * 500);
        $out[] = ['a' => $x['name'], 'b' => $y['name'], 'so_don' => (int) $r['so_don'], 'gia_le' => $le, 'gia_combo' => $gia,
            'lai' => $gia - $von, 'giam_pct' => $le > 0 ? (int) round((1 - $gia / $le) * 100) : 0];
    }
    return $out;
}

/**
 * Ban tin sang: tom tat hom qua (doanh thu, lai, so voi cung thu tuan truoc) va toi da 3-4 viec nen lam hom nay.
 * Tra ['hom_qua' => [...], 'tuan_truoc' => [...], 'viec' => [[tieu_de, chi_tiet, link], ...]].
 */
function banTinSang(PDO $pdo, int $tenantId, int $branchId): array
{
    $yd = strtotime('yesterday');
    $ld = strtotime('-8 days');   // cung thu cua hom qua, tuan truoc
    $fmt = static fn(int $t, string $e): string => date('Y-m-d', $t) . $e;
    $homQua = doanhThuLai($pdo, $tenantId, $branchId, $fmt($yd, ' 00:00:00'), $fmt($yd, ' 23:59:59'));
    $tuanTruoc = doanhThuLai($pdo, $tenantId, $branchId, $fmt($ld, ' 00:00:00'), $fmt($ld, ' 23:59:59'));

    $viec = [];
    $bid = $branchId ?: (int) $pdo->query('SELECT id FROM branches WHERE tenant_id = ' . (int) $tenantId . ' ORDER BY id LIMIT 1')->fetchColumn();
    if ($bid) {
        $need = array_values(array_filter(duBaoNhapHang($pdo, $tenantId, $bid, 7), static fn($r) => $r['goi_y'] > 0));
        if ($need) {
            $names = implode(', ', array_map(static fn($r) => $r['name'], array_slice($need, 0, 3)));
            $viec[] = ['Nhập hàng: ' . count($need) . ' mặt hàng sắp hết', $names . (count($need) > 3 ? '…' : ''), 'reorder_suggestions.php'];
        }
    }
    $han = layLoCanHan($pdo, $tenantId, $branchId, 3, 100);
    if ($han) {
        $het = count(array_filter($han, static fn($r) => strtotime($r['expiry_date']) < strtotime('today')));
        $viec[] = ['Hàng hết hạn / sắp hết hạn (3 ngày): ' . count($han) . ' lô', ($het ? $het . ' lô đã hết hạn cần xuất hủy; ' : '') . 'xả hoặc xuất hủy trước khi quá hạn', 'ton_cham.php'];
    }
    $due = layPhieuNhapDenHan($pdo, $tenantId, $branchId, 3, 100);
    if ($due) {
        $tong = array_sum(array_map(static fn($r) => (float) $r['con_no'], $due));
        $viec[] = ['Trả nhà cung cấp: ' . count($due) . ' phiếu đến/quá hạn', 'Tổng ' . money($tong), 'cong_no.php'];
    }
    $kn = $pdo->prepare('SELECT COUNT(*) AS n, COALESCE(SUM(debt), 0) AS t FROM customers WHERE tenant_id = ? AND debt > 0');
    $kn->execute([$tenantId]);
    $k = $kn->fetch();
    if ((int) $k['n'] > 0) {
        $viec[] = ['Thu nợ khách: ' . (int) $k['n'] . ' khách đang nợ', 'Tổng ' . money($k['t']), 'customer_debt_reminders.php'];
    }
    return ['hom_qua' => $homQua, 'tuan_truoc' => $tuanTruoc, 'viec' => array_slice($viec, 0, 4), 'thang' => duBaoThang($pdo, $tenantId, $branchId), 'nhip' => nhipBanHomNay($pdo, $tenantId, $branchId)];
}

// ======================================================================================================
// Dot 3: diem suc khoe cua hang, nhac no thong minh, gio cao diem, mo phong doi gia
// ======================================================================================================

/**
 * Diem suc khoe cua hang 0-100 tu 6 chi tieu (tong trong so 100): bien lai gop (25), tang truong doanh thu so voi 30 ngay truoc (15),
 * von chon vao hang e (15), hang het han (15), no xau >30 ngay (15), that thoat = xuat huy + chenh lech ca am (15).
 * Tra ['diem' => int, 'xep_loai' => text, 'muc' => [[ten, diem, toi_da, chi_tiet, loi_khuyen]...]].
 */
function diemSucKhoe(PDO $pdo, int $tenantId, int $branchId): array
{
    $clamp = static fn(float $v, float $lo, float $hi): float => max($lo, min($hi, $v));
    $now = date('Y-m-d H:i:s');
    $d30 = date('Y-m-d H:i:s', strtotime('-30 days'));
    $d60 = date('Y-m-d H:i:s', strtotime('-60 days'));
    $cur = doanhThuLai($pdo, $tenantId, $branchId, $d30, $now);
    $prev = doanhThuLai($pdo, $tenantId, $branchId, $d60, $d30);
    $dt = $cur['doanh_thu'];

    // 1. Bien lai gop
    $margin = $dt > 0 ? $cur['lai'] / $dt : null;
    $s1 = $margin === null ? 12.5 : $clamp($margin / 0.25, 0, 1) * 25;
    $c1 = $margin === null ? 'Chưa có doanh thu 30 ngày' : 'Biên lãi gộp ' . number_format($margin * 100, 1) . '% (mục tiêu ≥25%)';
    $t1 = ($margin !== null && $margin < 0.15) ? 'Biên lãi thấp: rà lại giá bán và giá vốn, giảm chiết khấu.' : '';

    // 2. Tang truong doanh thu
    $growth = $prev['doanh_thu'] > 0 ? ($dt / $prev['doanh_thu'] - 1) * 100 : null;
    $s2 = $growth === null ? 7.5 : $clamp(10 + $growth * 0.5, 0, 15);
    $c2 = $growth === null ? 'Chưa đủ dữ liệu 30 ngày trước để so sánh' : ($growth >= 0 ? '+' : '') . round($growth) . '% so với 30 ngày trước';
    $t2 = ($growth !== null && $growth < -10) ? 'Doanh thu giảm: xem khách quen sắp bỏ đi và chương trình khuyến mại.' : '';

    // Gia tri ton kho
    $sp = [$tenantId];
    $sw = phamViChiNhanh($branchId, $sp, 'i.branch_id');
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(i.quantity * p.cost_price), 0) FROM inventory i JOIN products p ON p.id = i.product_id JOIN branches b ON b.id = i.branch_id
         WHERE b.tenant_id = ? AND i.quantity > 0$sw"
    );
    $stmt->execute($sp);
    $tonKho = (float) $stmt->fetchColumn();

    // 3. Von chon (hang khong ban duoc 30 ngay)
    $e = layHangTonLau($pdo, $tenantId, $branchId, 30, 500);
    $vonChon = array_sum(array_column($e, 'von_chon'));
    $r3 = $tonKho > 0 ? $vonChon / $tonKho : 0.0;
    $s3 = 15 * (1 - $clamp($r3 / 0.3, 0, 1));
    $c3 = $tonKho > 0 ? number_format($r3 * 100, 0) . '% giá trị tồn là hàng ế 30 ngày (' . money($vonChon) . ')' : 'Chưa có tồn kho';
    $t3 = $r3 > 0.2 ? 'Nhiều vốn chôn trong hàng ế: xả hàng, ngừng nhập các mặt hàng nhóm C.' : '';

    // 4. Hang het han con ton
    $ep = [$tenantId];
    $ew = phamViChiNhanh($branchId, $ep, 'b2.branch_id');
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(b2.quantity * p.cost_price), 0) FROM product_batches b2 JOIN products p ON p.id = b2.product_id JOIN branches br ON br.id = b2.branch_id
         WHERE br.tenant_id = ? AND b2.quantity > 0 AND b2.expiry_date < CURDATE()$ew"
    );
    $stmt->execute($ep);
    $hetHan = (float) $stmt->fetchColumn();
    $r4 = $tonKho > 0 ? $hetHan / $tonKho : 0.0;
    $s4 = 15 * (1 - $clamp($r4 / 0.05, 0, 1));
    $c4 = $hetHan > 0 ? 'Hàng đã hết hạn còn tồn: ' . money($hetHan) : 'Không có hàng hết hạn còn tồn';
    $t4 = $hetHan > 0 ? 'Xuất hủy hàng hết hạn và đặt cảnh báo cận hạn sớm hơn.' : '';

    // 5. No xau >30 ngay
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(c.debt), 0) FROM customers c
         WHERE c.tenant_id = ? AND c.debt > 0
           AND (SELECT MIN(e.created_at) FROM customer_debt_entries e WHERE e.customer_id = c.id AND e.amount > 0) < DATE_SUB(NOW(), INTERVAL 30 DAY)"
    );
    $stmt->execute([$tenantId]);
    $noXau = (float) $stmt->fetchColumn();
    $r5 = $dt > 0 ? $noXau / $dt : ($noXau > 0 ? 1.0 : 0.0);
    $s5 = 15 * (1 - $clamp($r5 / 0.3, 0, 1));
    $c5 = $noXau > 0 ? 'Khách nợ quá 30 ngày: ' . money($noXau) : 'Không có nợ quá 30 ngày';
    $t5 = $noXau > 0 ? 'Nhắc nợ theo thứ tự "dễ đòi trước" ở mục Nhắc nợ thông minh.' : '';

    // 6. That thoat: xuat huy 30 ngay + chenh lech ca am
    $wp = [$tenantId, $d30];
    $ww = '';
    if ($branchId) { $ww = ' AND branch_id = ?'; $wp[] = $branchId; }
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(quantity * cost_price), 0) FROM stock_write_offs WHERE tenant_id = ? AND created_at >= ?$ww");
    $stmt->execute($wp);
    $huy = (float) $stmt->fetchColumn();
    $sp2 = [$tenantId, $d30];
    $sw2 = phamViChiNhanh($branchId, $sp2, 's.branch_id');
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(CASE WHEN s.difference < 0 THEN -s.difference ELSE 0 END), 0) FROM pos_shifts s JOIN branches br ON br.id = s.branch_id
         WHERE br.tenant_id = ? AND s.status = 'CLOSED' AND s.closed_at >= ?$sw2"
    );
    $stmt->execute($sp2);
    $thieuCa = (float) $stmt->fetchColumn();
    $mat = $huy + $thieuCa;
    $r6 = $dt > 0 ? $mat / $dt : ($mat > 0 ? 1.0 : 0.0);
    $s6 = 15 * (1 - $clamp($r6 / 0.03, 0, 1));
    $c6 = 'Xuất hủy ' . money($huy) . ' + thiếu tiền ca ' . money($thieuCa) . ' trong 30 ngày';
    $t6 = $r6 > 0.015 ? 'Thất thoát cao: xem Cảnh báo bất thường và đối soát kho.' : '';

    $muc = [
        ['Biên lãi gộp', $s1, 25, $c1, $t1], ['Tăng trưởng doanh thu', $s2, 15, $c2, $t2], ['Vốn chôn vào hàng ế', $s3, 15, $c3, $t3],
        ['Hàng hết hạn', $s4, 15, $c4, $t4], ['Nợ khó đòi', $s5, 15, $c5, $t5], ['Thất thoát', $s6, 15, $c6, $t6],
    ];
    $diem = (int) round(array_sum(array_column($muc, 1)));
    $xep = $diem >= 80 ? 'Tốt' : ($diem >= 60 ? 'Khá' : ($diem >= 40 ? 'Cần chú ý' : 'Báo động'));
    return ['diem' => $diem, 'xep_loai' => $xep, 'muc' => $muc];
}

/**
 * Xep hang no khach theo kha nang doi duoc (cao truoc): +20 neu mua lai trong 30 ngay, +5/lan tra no truoc day (toi da +20),
 * -1 diem moi 3 ngay no ton (toi da -50), -20 neu no lon hon 3 thang mua trung binh. Moi khach co muc do + noi dung nhan goi y.
 */
function xepHangDoiNo(PDO $pdo, int $tenantId, string $storeName = ''): array
{
    $stmt = $pdo->prepare(
        "SELECT c.id, c.name, c.phone, c.debt,
                (SELECT MIN(e.created_at) FROM customer_debt_entries e WHERE e.customer_id = c.id AND e.amount > 0) AS no_dau,
                (SELECT COUNT(*) FROM customer_debt_entries e WHERE e.customer_id = c.id AND e.amount < 0) AS so_lan_tra,
                (SELECT MAX(o.created_at) FROM orders o WHERE o.customer_id = c.id AND o.status NOT IN ('CANCELLED','DRAFT')) AS mua_cuoi,
                (SELECT COALESCE(SUM(o.total_amount), 0) / 3 FROM orders o WHERE o.customer_id = c.id AND o.status NOT IN ('CANCELLED','DRAFT')
                    AND o.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)) AS mua_thang
         FROM customers c WHERE c.tenant_id = ? AND c.debt > 0"
    );
    $stmt->execute([$tenantId]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $tuoi = $r['no_dau'] ? max(0, (int) floor((time() - strtotime($r['no_dau'])) / 86400)) : 0;
        $score = 60.0;
        if ($r['mua_cuoi'] && strtotime($r['mua_cuoi']) >= strtotime('-30 days')) {
            $score += 20;
        }
        $score += min(20, 5 * (int) $r['so_lan_tra']);
        $score -= min(50, $tuoi / 3);
        if ((float) $r['mua_thang'] > 0 && (float) $r['debt'] > 3 * (float) $r['mua_thang']) {
            $score -= 20;
        }
        $score = max(0, min(100, (int) round($score)));
        $muc = $score >= 65 ? 'De doi' : ($score >= 40 ? 'Trung binh' : 'Kho doi');
        $shop = $storeName !== '' ? $storeName : 'cửa hàng';
        $sotien = money($r['debt']);
        if ($tuoi < 30) {
            $msg = "Chào {$r['name']}, $shop xin nhắc nhẹ: anh/chị còn khoản $sotien chưa thanh toán. Khi tiện anh/chị ghé hoặc chuyển khoản giúp em nhé, cảm ơn anh/chị!";
        } elseif ($tuoi < 60) {
            $msg = "Chào {$r['name']}, khoản $sotien tại $shop đã quá $tuoi ngày. Anh/chị cho em xin lịch thanh toán cụ thể để em tiện sắp xếp sổ sách, cảm ơn anh/chị.";
        } else {
            $msg = "Chào {$r['name']}, khoản nợ $sotien tại $shop đã kéo dài $tuoi ngày. $shop rất mong anh/chị thanh toán hoặc thỏa thuận chia nhỏ trong tuần này; em liên hệ để cùng giải quyết.";
        }
        $out[] = ['id' => (int) $r['id'], 'name' => $r['name'], 'phone' => $r['phone'], 'debt' => (float) $r['debt'], 'tuoi' => $tuoi,
            'diem' => $score, 'muc' => $muc, 'so_lan_tra' => (int) $r['so_lan_tra'], 'tin_nhan' => $msg];
    }
    usort($out, static fn($a, $b) => [$b['diem'], $b['debt']] <=> [$a['diem'], $a['debt']]);
    return $out;
}

/**
 * Gio cao diem: so don theo gio x thu trong tuan (8 tuan) va goi y so nhan vien moi khung gio.
 * $nangSuat = so don 1 nhan vien phuc vu duoc moi gio. Tra ['luoi' => [thu 0..6][gio] = don TB moi lan, 'gio' => [min,max], 'top' => [...], 'nv' => [thu][gio]].
 */
function gioCaoDiem(PDO $pdo, int $tenantId, int $branchId, int $nangSuat = 12): array
{
    $p = [$tenantId];
    $bw = phamViChiNhanh($branchId, $p, 'o.branch_id');
    $stmt = $pdo->prepare(
        "SELECT DAYOFWEEK(o.created_at) AS dow, HOUR(o.created_at) AS gio, COUNT(*) AS don
         FROM orders o JOIN branches b ON b.id = o.branch_id
         WHERE b.tenant_id = ? AND o.status NOT IN ('CANCELLED','DRAFT') AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL 56 DAY)$bw
         GROUP BY DAYOFWEEK(o.created_at), HOUR(o.created_at)"
    );
    $stmt->execute($p);
    $luoi = [];
    $min = 23;
    $max = 0;
    $top = [];
    foreach ($stmt->fetchAll() as $r) {
        $thu = (int) $r['dow'] - 1;           // 0 = CN
        $gio = (int) $r['gio'];
        $tb = (float) $r['don'] / 8;          // don TB moi lan thu do xuat hien trong 8 tuan
        $luoi[$thu][$gio] = $tb;
        $min = min($min, $gio);
        $max = max($max, $gio);
        $top[] = ['thu' => $thu, 'gio' => $gio, 'don' => $tb];
    }
    usort($top, static fn($a, $b) => $b['don'] <=> $a['don']);
    $nv = [];
    foreach ($luoi as $thu => $gios) {
        foreach ($gios as $gio => $don) {
            $nv[$thu][$gio] = max(1, (int) ceil($don / max(1, $nangSuat)));
        }
    }
    return ['luoi' => $luoi, 'gio' => $luoi ? [$min, $max] : [8, 20], 'top' => array_slice($top, 0, 5), 'nv' => $nv];
}

/**
 * Mo phong doi gia: uoc tinh lai thay doi khi tang/giam gia X%. Do nhay gia (co giang) uoc tinh tu lich su 180 ngay neu san pham da
 * ban o >=2 muc gia khac nhau (moi muc >=5 ngay co ban); khong thi dung mac dinh -1.2 (ghi ro nguon).
 */
function moPhongGia(PDO $pdo, int $tenantId, int $productId, int $branchId = 0): ?array
{
    $stmt = $pdo->prepare('SELECT id, name, sell_price, cost_price FROM products WHERE id = ? AND tenant_id = ?');
    $stmt->execute([$productId, $tenantId]);
    $p = $stmt->fetch();
    if (!$p) {
        return null;
    }
    $params = [$productId, $tenantId];
    $bw = phamViChiNhanh($branchId, $params, 'o.branch_id');
    $stmt = $pdo->prepare(
        "SELECT SUM(oi.quantity) AS sl FROM order_items oi JOIN orders o ON o.id = oi.order_id JOIN branches b ON b.id = o.branch_id
         WHERE oi.product_id = ? AND b.tenant_id = ? AND o.status NOT IN ('CANCELLED','DRAFT') AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)$bw"
    );
    $stmt->execute($params);
    $q30 = (float) $stmt->fetchColumn();

    $params = [$productId, $tenantId];
    $bw = phamViChiNhanh($branchId, $params, 'o.branch_id');
    $stmt = $pdo->prepare(
        "SELECT oi.unit_price AS gia, SUM(oi.quantity) AS sl, COUNT(DISTINCT DATE(o.created_at)) AS ngay
         FROM order_items oi JOIN orders o ON o.id = oi.order_id JOIN branches b ON b.id = o.branch_id
         WHERE oi.product_id = ? AND b.tenant_id = ? AND o.status NOT IN ('CANCELLED','DRAFT') AND o.created_at >= DATE_SUB(NOW(), INTERVAL 180 DAY)$bw
         GROUP BY oi.unit_price HAVING ngay >= 5 ORDER BY ngay DESC LIMIT 2"
    );
    $stmt->execute($params);
    $lv = $stmt->fetchAll();
    $e = -1.2;
    $nguon = 'mặc định (-1,2): hàng này chưa bán ở ≥2 mức giá đủ lâu để đo';
    if (count($lv) === 2 && (float) $lv[0]['gia'] > 0 && (float) $lv[1]['gia'] > 0 && abs(log((float) $lv[0]['gia'] / (float) $lv[1]['gia'])) >= 0.03) {
        $q1 = (float) $lv[0]['sl'] / (int) $lv[0]['ngay'];
        $q2 = (float) $lv[1]['sl'] / (int) $lv[1]['ngay'];
        if ($q1 > 0 && $q2 > 0) {
            $e = max(-3.0, min(0.0, log($q2 / $q1) / log((float) $lv[1]['gia'] / (float) $lv[0]['gia'])));
            $nguon = 'đo từ lịch sử bán ở 2 mức giá (' . number_format((float) $lv[0]['gia'], 0, ',', '.') . 'đ và ' . number_format((float) $lv[1]['gia'], 0, ',', '.') . 'đ)';
        }
    }
    $gia = (float) $p['sell_price'];
    $von = (float) $p['cost_price'];
    $base = ($gia - $von) * $q30;
    $kb = [];
    $best = null;
    foreach ([-20, -10, -5, 0, 5, 10, 20] as $x) {
        $g = $gia * (1 + $x / 100);
        $q = $q30 * pow(1 + $x / 100, $e);
        $lai = ($g - $von) * $q;
        $row = ['pct' => $x, 'gia' => $g, 'sl' => $q, 'lai' => $lai, 'doi' => $lai - $base];
        $kb[] = $row;
        if ($best === null || $lai > $best['lai']) {
            $best = $row;
        }
    }
    return ['ten' => $p['name'], 'gia' => $gia, 'von' => $von, 'q30' => $q30, 'e' => $e, 'nguon' => $nguon, 'kich_ban' => $kb, 'tot_nhat' => $best, 'lai_hien_tai' => $base];
}

// ======================================================================================================
// Dot 4: kiem tra du lieu, du bao doanh thu thang, nha cung cap gan nhat de dat hang nhap nhap
// ======================================================================================================

/** Chuan hoa so dien thoai ve dang chi con chu so, bo 0 dau / +84 de so sanh trung. */
function chuanHoaSdt(?string $phone): string
{
    $d = preg_replace('/\D+/', '', (string) $phone);
    if (str_starts_with($d, '84') && strlen($d) >= 11) {
        $d = substr($d, 2);
    }
    return ltrim($d, '0');
}

/**
 * Kiem tra chat luong du lieu cua cua hang (chi doc): gia ban thap hon gia von, thieu gia von/gia ban, ma/ten trung, ton kho am,
 * khach trung/sai so dien thoai, hang dang ban nhung chua dat dinh muc ton. Tra [[key, tieu_de, huong_dan, [dong...], tong], ...].
 * Moi dong: ['label' => text, 'link' => url].
 */
function kiemTraDuLieu(PDO $pdo, int $tenantId): array
{
    $sections = [];
    $push = static function (string $key, string $title, string $hint, array $rows, int $total) use (&$sections): void {
        $sections[] = ['key' => $key, 'title' => $title, 'hint' => $hint, 'rows' => $rows, 'total' => $total];
    };
    $pLink = static fn(int $id): string => 'product_form.php?id=' . $id;

    $base = "p.tenant_id = ? AND p.is_active = 1 AND p.product_type = 'PRODUCT'";

    // 1. Gia ban < gia von
    $stmt = $pdo->prepare("SELECT p.id, p.name, p.sell_price, p.cost_price FROM products p WHERE $base AND p.cost_price > 0 AND p.sell_price > 0 AND p.sell_price < p.cost_price ORDER BY (p.cost_price - p.sell_price) DESC");
    $stmt->execute([$tenantId]);
    $all = $stmt->fetchAll();
    $rows = array_map(static fn($r) => ['label' => $r['name'] . ' — bán ' . money($r['sell_price']) . ' < vốn ' . money($r['cost_price']), 'link' => $pLink((int) $r['id'])], array_slice($all, 0, 20));
    $push('duoi_von', 'Giá bán thấp hơn giá vốn', 'Bán mỗi cái là lỗ. Kiểm tra lại giá bán hoặc giá vốn nhập nhầm.', $rows, count($all));

    // 2. Thieu gia von
    $stmt = $pdo->prepare("SELECT p.id, p.name, p.sell_price FROM products p WHERE $base AND p.cost_price <= 0 AND p.sell_price > 0 ORDER BY p.name");
    $stmt->execute([$tenantId]);
    $all = $stmt->fetchAll();
    $rows = array_map(static fn($r) => ['label' => $r['name'] . ' — giá bán ' . money($r['sell_price']), 'link' => $pLink((int) $r['id'])], array_slice($all, 0, 20));
    $push('thieu_von', 'Chưa có giá vốn', 'Báo cáo lãi gộp sẽ cao ảo. Nhập giá vốn (hoặc nhập hàng qua phiếu nhập để tự cập nhật).', $rows, count($all));

    // 3. Thieu gia ban
    $stmt = $pdo->prepare("SELECT p.id, p.name FROM products p WHERE $base AND p.sell_price <= 0 ORDER BY p.name");
    $stmt->execute([$tenantId]);
    $all = $stmt->fetchAll();
    $rows = array_map(static fn($r) => ['label' => $r['name'], 'link' => $pLink((int) $r['id'])], array_slice($all, 0, 20));
    $push('thieu_gia', 'Chưa có giá bán', 'Hàng bán giá 0đ nếu quên nhập giá ở màn hình bán hàng.', $rows, count($all));

    // 4. Trung ten / trung ma vach
    $stmt = $pdo->prepare(
        "SELECT p.id, p.name, p.barcode, p.sku FROM products p
         WHERE $base AND (
            EXISTS (SELECT 1 FROM products q WHERE q.tenant_id = p.tenant_id AND q.is_active = 1 AND q.id <> p.id AND LOWER(TRIM(q.name)) = LOWER(TRIM(p.name)))
            OR (p.barcode IS NOT NULL AND p.barcode <> '' AND EXISTS (SELECT 1 FROM products q WHERE q.tenant_id = p.tenant_id AND q.is_active = 1 AND q.id <> p.id AND q.barcode = p.barcode)))
         ORDER BY p.name LIMIT 100"
    );
    $stmt->execute([$tenantId]);
    $all = $stmt->fetchAll();
    $rows = array_map(static fn($r) => ['label' => $r['name'] . ' (SKU ' . $r['sku'] . ($r['barcode'] ? ', mã vạch ' . $r['barcode'] : '') . ')', 'link' => $pLink((int) $r['id'])], array_slice($all, 0, 20));
    $push('trung_sp', 'Sản phẩm trùng tên hoặc mã vạch', 'Dễ bán nhầm / tồn kho tách đôi. Gộp lại hoặc ngừng bán bản trùng.', $rows, count($all));

    // 5. Ton kho am
    $stmt = $pdo->prepare(
        "SELECT p.id, p.name, i.quantity, br.name AS cn FROM inventory i JOIN products p ON p.id = i.product_id JOIN branches br ON br.id = i.branch_id
         WHERE br.tenant_id = ? AND i.quantity < 0 ORDER BY i.quantity ASC LIMIT 100"
    );
    $stmt->execute([$tenantId]);
    $all = $stmt->fetchAll();
    $rows = array_map(static fn($r) => ['label' => $r['name'] . ' — tồn ' . fmtQty($r['quantity']) . ' (' . $r['cn'] . ')', 'link' => 'inventory.php'], array_slice($all, 0, 20));
    $push('ton_am', 'Tồn kho âm', 'Thường do bán trước khi nhập hàng. Nhập phiếu nhập còn thiếu hoặc kiểm kho để chỉnh lại.', $rows, count($all));

    // 6. Khach trung / SDT bat thuong
    $stmt = $pdo->prepare("SELECT id, name, phone FROM customers WHERE tenant_id = ? AND phone IS NOT NULL AND phone <> ''");
    $stmt->execute([$tenantId]);
    $byPhone = [];
    $bad = [];
    foreach ($stmt->fetchAll() as $c) {
        $n = chuanHoaSdt($c['phone']);
        if (strlen($n) < 9 || strlen($n) > 11) {
            $bad[] = $c;
        }
        $byPhone[$n][] = $c;
    }
    $dups = [];
    foreach ($byPhone as $n => $list) {
        if ($n !== '' && count($list) > 1) {
            foreach ($list as $c) {
                $dups[] = $c;
            }
        }
    }
    $rows = array_map(static fn($c) => ['label' => $c['name'] . ' — ' . $c['phone'], 'link' => 'customer_view.php?id=' . (int) $c['id']], array_slice($dups, 0, 20));
    $push('trung_khach', 'Khách trùng số điện thoại', 'Cùng một số điện thoại nhưng có nhiều hồ sơ (kể cả viết khác nhau như 0912... và +84912...). Điểm và công nợ bị chia nhỏ.', $rows, count($dups));
    $rows = array_map(static fn($c) => ['label' => $c['name'] . ' — "' . $c['phone'] . '"', 'link' => 'customer_view.php?id=' . (int) $c['id']], array_slice($bad, 0, 20));
    $push('sdt_sai', 'Số điện thoại khách bất thường', 'Số có ít hơn 9 hoặc nhiều hơn 11 chữ số — nhắn tin/nhắc nợ sẽ không tới.', $rows, count($bad));

    // 7. Hang dang ban nhung chua dat dinh muc ton toi thieu (de goi y nhap hang hoat dong)
    $stmt = $pdo->prepare(
        "SELECT p.id, p.name, SUM(oi.quantity) AS sl FROM order_items oi JOIN orders o ON o.id = oi.order_id JOIN branches b ON b.id = o.branch_id JOIN products p ON p.id = oi.product_id
         WHERE b.tenant_id = ? AND p.product_type = 'PRODUCT' AND p.is_active = 1 AND o.status NOT IN ('CANCELLED','DRAFT') AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
           AND NOT EXISTS (SELECT 1 FROM inventory i WHERE i.product_id = p.id AND i.min_stock > 0)
         GROUP BY p.id, p.name HAVING sl >= 5 ORDER BY sl DESC LIMIT 100"
    );
    $stmt->execute([$tenantId]);
    $all = $stmt->fetchAll();
    $rows = array_map(static fn($r) => ['label' => $r['name'] . ' — bán ' . fmtQty($r['sl']) . ' trong 30 ngày', 'link' => $pLink((int) $r['id'])], array_slice($all, 0, 20));
    $push('thieu_dinh_muc', 'Hàng bán đều nhưng chưa đặt định mức tồn tối thiểu', 'Đặt định mức để hệ thống cảnh báo và gợi ý nhập hàng chính xác hơn.', $rows, count($all));

    return $sections;
}

/** Du bao doanh thu thang nay theo toc do hien tai (cac ngay da tron ven) va so voi thang truoc. */
function duBaoThang(PDO $pdo, int $tenantId, int $branchId): array
{
    $firstThis = strtotime('first day of this month midnight');
    $today = strtotime('today');
    $daysInMonth = (int) date('t');
    $done = (int) round(($today - $firstThis) / 86400);          // so ngay da tron ven (khong tinh hom nay)
    $mtd = doanhThuLai($pdo, $tenantId, $branchId, date('Y-m-d H:i:s', $firstThis), date('Y-m-d H:i:s'));
    $thangTruoc = doanhThuLai($pdo, $tenantId, $branchId, date('Y-m-d 00:00:00', strtotime('first day of last month')), date('Y-m-d 23:59:59', strtotime('last day of last month')));
    if ($done >= 1) {
        $stmt = doanhThuLai($pdo, $tenantId, $branchId, date('Y-m-d H:i:s', $firstThis), date('Y-m-d 23:59:59', $today - 86400));
        $duBao = $stmt['doanh_thu'] / $done * $daysInMonth;
        $duBaoLai = $stmt['lai'] / $done * $daysInMonth;
    } else {
        $duBao = $mtd['doanh_thu'] * $daysInMonth;
        $duBaoLai = $mtd['lai'] * $daysInMonth;
    }
    return [
        'mtd' => $mtd['doanh_thu'], 'du_bao' => $duBao, 'du_bao_lai' => $duBaoLai, 'thang_truoc' => $thangTruoc['doanh_thu'],
        'so_ngay' => $done, 'ngay_trong_thang' => $daysInMonth,
        'pct' => $thangTruoc['doanh_thu'] > 0 ? ($duBao / $thangTruoc['doanh_thu'] - 1) * 100 : null,
    ];
}

/** Nha cung cap gan nhat da nhap tung san pham: [product_id => supplier_id] (chi NCC thuoc tenant). */
function nhaCungCapGanNhat(PDO $pdo, int $tenantId, array $productIds): array
{
    if (!$productIds) {
        return [];
    }
    $in = implode(',', array_fill(0, count($productIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT i.product_id, r.supplier_id FROM stock_receipt_items i
         JOIN stock_receipts r ON r.id = i.receipt_id JOIN suppliers s ON s.id = r.supplier_id
         WHERE s.tenant_id = ? AND i.product_id IN ($in) ORDER BY r.created_at DESC, r.id DESC"
    );
    $stmt->execute(array_merge([$tenantId], array_map('intval', $productIds)));
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $pid = (int) $r['product_id'];
        if (!isset($out[$pid])) {
            $out[$pid] = (int) $r['supplier_id'];
        }
    }
    return $out;
}


/**
 * Goi y dinh muc ton toi thieu: voi moi (chi nhanh, mat hang) dang ban (>=5 trong 30 ngay) ma chua dat dinh muc (=0),
 * dinh muc = toc do ban/ngay x 7 (du ban 1 tuan), toi thieu 1. Tra danh sach [inventory_id, product_id, name, branch_name, ban_30, dinh_muc].
 */
function goiYDinhMuc(PDO $pdo, int $tenantId): array
{
    $bs = $pdo->prepare('SELECT id, name FROM branches WHERE tenant_id = ?');
    $bs->execute([$tenantId]);
    $out = [];
    foreach ($bs->fetchAll() as $b) {
        $sales = layTocDoBanChiNhanh($pdo, (int) $b['id']);
        if (!$sales) {
            continue;
        }
        $st = $pdo->prepare(
            "SELECT i.id, i.product_id, p.name FROM inventory i JOIN products p ON p.id = i.product_id
             WHERE i.branch_id = ? AND i.variant_id IS NULL AND i.min_stock = 0 AND p.tenant_id = ? AND p.is_active = 1 AND p.product_type = 'PRODUCT'"
        );
        $st->execute([(int) $b['id'], $tenantId]);
        foreach ($st->fetchAll() as $r) {
            $q30 = $sales[(int) $r['product_id']]['q30'] ?? 0.0;
            if ($q30 >= 5) {
                $out[] = ['inventory_id' => (int) $r['id'], 'product_id' => (int) $r['product_id'], 'name' => $r['name'], 'branch_name' => $b['name'],
                    'ban_30' => $q30, 'dinh_muc' => max(1, (int) ceil($q30 / 30 * 7))];
            }
        }
    }
    usort($out, static fn($a, $b) => $b['ban_30'] <=> $a['ban_30']);
    return $out;
}

/** Ap dung goi y dinh muc (chi cap nhat dong dang = 0 de khong de len gia tri nguoi dung da dat). Tra so dong cap nhat. */
function apDungDinhMuc(PDO $pdo, int $tenantId): int
{
    $n = 0;
    $up = $pdo->prepare('UPDATE inventory i JOIN branches b ON b.id = i.branch_id SET i.min_stock = ? WHERE i.id = ? AND i.min_stock = 0 AND b.tenant_id = ?');
    foreach (goiYDinhMuc($pdo, $tenantId) as $g) {
        $up->execute([$g['dinh_muc'], $g['inventory_id'], $tenantId]);
        $n += $up->rowCount();
    }
    return $n;
}


/**
 * Nhip ban hom nay so voi cung gio cua cung thu trong 4 tuan truoc: [hom_nay, tb, pct, so_tuan] hoac null neu chua du du lieu
 * (can >=2 tuan co ban cung khung gio va da qua 9h). Dung de bao som "hom nay dang bam hon moi khi".
 */
function nhipBanHomNay(PDO $pdo, int $tenantId, int $branchId): ?array
{
    if ((int) date('G') < 9) {
        return null;
    }
    $clock = date('H:i:s');
    $today = doanhThuLai($pdo, $tenantId, $branchId, date('Y-m-d 00:00:00'), date('Y-m-d H:i:s'));
    $tong = 0.0;
    $tuan = 0;
    for ($w = 1; $w <= 4; $w++) {
        $d = date('Y-m-d', strtotime("-$w week"));
        $r = doanhThuLai($pdo, $tenantId, $branchId, "$d 00:00:00", "$d $clock");
        if ($r['so_don'] > 0) {
            $tong += $r['doanh_thu'];
            $tuan++;
        }
    }
    if ($tuan < 2) {
        return null;
    }
    $tb = $tong / $tuan;
    return ['hom_nay' => $today['doanh_thu'], 'tb' => $tb, 'pct' => $tb > 0 ? ($today['doanh_thu'] / $tb - 1) * 100 : null, 'so_tuan' => $tuan];
}

/** Gia nhap gan nhat theo tung nha cung cap cho 1 mat hang (180 ngay), sap tu re den dat: [[supplier_id, name, cost, date]...]. */
function giaNhapTheoNcc(PDO $pdo, int $tenantId, int $productId): array
{
    $stmt = $pdo->prepare(
        "SELECT r.supplier_id, s.name, i.cost_price, r.created_at
         FROM stock_receipt_items i JOIN stock_receipts r ON r.id = i.receipt_id JOIN suppliers s ON s.id = r.supplier_id
         WHERE i.product_id = ? AND s.tenant_id = ? AND r.created_at >= DATE_SUB(NOW(), INTERVAL 180 DAY) AND i.cost_price > 0
         ORDER BY r.created_at DESC, r.id DESC"
    );
    $stmt->execute([$productId, $tenantId]);
    $latest = [];
    foreach ($stmt->fetchAll() as $r) {
        $sid = (int) $r['supplier_id'];
        if (!isset($latest[$sid])) {
            $latest[$sid] = ['supplier_id' => $sid, 'name' => $r['name'], 'cost' => (float) $r['cost_price'], 'date' => date('d/m', strtotime($r['created_at']))];
        }
    }
    $list = array_values($latest);
    usort($list, static fn($a, $b) => $a['cost'] <=> $b['cost']);
    return $list;
}
