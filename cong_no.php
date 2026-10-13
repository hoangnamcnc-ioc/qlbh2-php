<?php
/**
 * Cong no phai thu (khach no cua hang) va phai tra (cua hang no nha cung cap) tren 1 trang, co xuat Excel de in/gui ke toan.
 */
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
require_once __DIR__ . '/inc_xlsx.php';
requireRole('ADMIN', 'MANAGER');

$pdo = db();
$tenantId = currentTenantId();

// Phai thu: khach con no, kem so ngay tu lan ghi no cu nhat con chua tra (xep theo tuoi no).
$stmt = $pdo->prepare(
    "SELECT c.id, c.name, c.phone, c.debt,
            (SELECT MIN(e.created_at) FROM customer_debt_entries e WHERE e.customer_id = c.id AND e.amount > 0) AS dau_tien,
            (SELECT MAX(e.created_at) FROM customer_debt_entries e WHERE e.customer_id = c.id) AS gan_nhat
     FROM customers c WHERE c.tenant_id = ? AND c.debt > 0 ORDER BY c.debt DESC"
);
$stmt->execute([$tenantId]);
$phaiThu = $stmt->fetchAll();

// Phai tra: nha cung cap con no (tong phieu nhap - da tra - hang tra lai NCC), lay theo cot debt da duoc cac phieu cap nhat.
$stmt = $pdo->prepare('SELECT s.id, s.name, s.phone, s.debt FROM suppliers s WHERE s.tenant_id = ? AND s.debt > 0 ORDER BY s.debt DESC');
$stmt->execute([$tenantId]);
$phaiTra = $stmt->fetchAll();

$tongThu = array_sum(array_map(static fn($r) => (float) $r['debt'], $phaiThu));
$tongTra = array_sum(array_map(static fn($r) => (float) $r['debt'], $phaiTra));
$tuoi = static fn(?string $ts): string => $ts ? (string) max(0, (int) floor((time() - strtotime($ts)) / 86400)) : '';

if (isset($_GET['export'])) {
    $rows = [
        ['BÁO CÁO CÔNG NỢ', date('d/m/Y H:i'), '', '', ''],
        ['Cửa hàng', getSetting('store_name', 'Cửa hàng'), '', '', ''],
        ['', '', '', '', ''],
        ['PHẢI THU TỪ KHÁCH HÀNG', '', '', '', ''],
        ['Khách hàng', 'Số điện thoại', 'Còn nợ', 'Nợ từ ngày', 'Số ngày nợ'],
    ];
    foreach ($phaiThu as $r) {
        $rows[] = [$r['name'], (string) $r['phone'], (string) round((float) $r['debt']),
            $r['dau_tien'] ? date('d/m/Y', strtotime($r['dau_tien'])) : '', $tuoi($r['dau_tien'])];
    }
    $rows[] = ['Tổng phải thu', '', (string) round($tongThu), '', ''];
    $rows[] = ['', '', '', '', ''];
    $rows[] = ['PHẢI TRẢ NHÀ CUNG CẤP', '', '', '', ''];
    $rows[] = ['Nhà cung cấp', 'Số điện thoại', 'Còn nợ', '', ''];
    foreach ($phaiTra as $r) {
        $rows[] = [$r['name'], (string) $r['phone'], (string) round((float) $r['debt']), '', ''];
    }
    $rows[] = ['Tổng phải trả', '', (string) round($tongTra), '', ''];
    $rows[] = ['', '', '', '', ''];
    $rows[] = ['Chênh lệch (phải thu - phải trả)', '', (string) round($tongThu - $tongTra), '', ''];
    logActivity('EXPORT_DEBT_REPORT', 'thu=' . count($phaiThu) . ' tra=' . count($phaiTra));
    downloadXlsx($rows, 'cong_no_' . date('Ymd') . '.xlsx');
    exit;
}

require_once __DIR__ . '/inc_header.php';
?>

<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;margin-bottom:8px;">
  <h1 style="font-size:24px;font-weight:600;margin:0;">Công nợ phải thu / phải trả</h1>
  <a class="btn btn-secondary" href="?export=1">⬇ Xuất Excel</a>
</div>
<p class="muted" style="font-size:13px;margin:0 0 16px;max-width:760px;">
  Tổng hợp số tiền khách còn nợ cửa hàng và số tiền cửa hàng còn nợ nhà cung cấp tại thời điểm hiện tại.
  Muốn thu nợ vào hồ sơ khách (<a href="customers.php">Khách hàng</a>) hoặc trả tiền phiếu nhập (<a href="stock_receipts.php">Nhập hàng</a>).
</p>

<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px;">
  <div class="card" style="min-width:200px;"><div class="muted" style="font-size:12px;">Phải thu từ khách</div><div style="font-size:22px;font-weight:700;color:#059669;"><?= money($tongThu) ?></div><div class="muted" style="font-size:12px;"><?= count($phaiThu) ?> khách đang nợ</div></div>
  <div class="card" style="min-width:200px;"><div class="muted" style="font-size:12px;">Phải trả nhà cung cấp</div><div style="font-size:22px;font-weight:700;color:#dc2626;"><?= money($tongTra) ?></div><div class="muted" style="font-size:12px;"><?= count($phaiTra) ?> nhà cung cấp</div></div>
  <div class="card" style="min-width:200px;"><div class="muted" style="font-size:12px;">Chênh lệch</div><div style="font-size:22px;font-weight:700;"><?= money($tongThu - $tongTra) ?></div><div class="muted" style="font-size:12px;">phải thu − phải trả</div></div>
</div>

<h2 style="font-size:16px;margin:0 0 8px;">Khách hàng còn nợ</h2>
<div class="card" style="padding:0;overflow-x:auto;margin-bottom:24px;">
  <table>
    <thead><tr><th>Khách hàng</th><th>SĐT</th><th class="text-right">Còn nợ</th><th>Nợ từ</th><th class="text-right">Số ngày</th></tr></thead>
    <tbody>
      <?php if (!$phaiThu): ?><tr><td colspan="5" class="text-center muted" style="padding:20px;">Không có khách nào đang nợ.</td></tr><?php endif; ?>
      <?php foreach ($phaiThu as $r): ?>
        <?php $ngay = (int) $tuoi($r['dau_tien']); ?>
        <tr>
          <td><a href="customer_view.php?id=<?= (int) $r['id'] ?>"><?= e($r['name']) ?></a></td>
          <td><?= e((string) $r['phone']) ?></td>
          <td class="text-right"><b><?= money($r['debt']) ?></b></td>
          <td class="muted"><?= $r['dau_tien'] ? e(date('d/m/Y', strtotime($r['dau_tien']))) : '—' ?></td>
          <td class="text-right" style="<?= $ngay >= 30 ? 'color:#dc2626;font-weight:600;' : '' ?>"><?= $r['dau_tien'] ? $ngay : '—' ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<h2 style="font-size:16px;margin:0 0 8px;">Nhà cung cấp cửa hàng còn nợ</h2>
<div class="card" style="padding:0;overflow-x:auto;">
  <table>
    <thead><tr><th>Nhà cung cấp</th><th>SĐT</th><th class="text-right">Còn nợ</th></tr></thead>
    <tbody>
      <?php if (!$phaiTra): ?><tr><td colspan="3" class="text-center muted" style="padding:20px;">Không còn nợ nhà cung cấp nào.</td></tr><?php endif; ?>
      <?php foreach ($phaiTra as $r): ?>
        <tr>
          <td><a href="supplier_view.php?id=<?= (int) $r['id'] ?>"><?= e($r['name']) ?></a></td>
          <td><?= e((string) $r['phone']) ?></td>
          <td class="text-right"><b><?= money($r['debt']) ?></b></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
