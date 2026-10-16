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
