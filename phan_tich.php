<?php
/**
 * Phan tich kinh doanh: ABC theo lai gop, du bao dong tien 14 ngay, khach quen sap "bo", combo goi y.
 * Tinh tren du lieu cua cua hang, quy tac minh bach (xem inc_thong_minh.php).
 */
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
require_once __DIR__ . '/inc_thong_minh.php';
$currentUser = requireRole('ADMIN', 'MANAGER');

$pdo = db();
$tenantId = currentTenantId();
$branchId = hasRole('ADMIN') ? 0 : effectiveBranchId($currentUser);

$days = (int) ($_GET['days'] ?? 90);
if (!in_array($days, [30, 90, 180, 365], true)) {
    $days = 90;
}
$abc = phanTichABC($pdo, $tenantId, $branchId, $days);
$tien = duBaoDongTien($pdo, $tenantId, $branchId, 14);
$bo = khachSapBo($pdo, $tenantId, 40);
$combos = comboGoiY($pdo, $tenantId, 10);
$maxDu = max(1.0, max(array_map(static fn($d) => abs($d['so_du']), $tien['ngay'])));

require_once __DIR__ . '/inc_header.php';
?>

<h1 style="font-size:24px;font-weight:600;margin:0 0 8px;">Phân tích kinh doanh</h1>
<p class="muted" style="margin:0 0 16px;font-size:13px;max-width:780px;">
  Bốn công cụ giúp quyết định nhanh: nên dồn vốn vào hàng nào (ABC), có thiếu tiền trong 2 tuần tới không, khách quen nào sắp bỏ đi,
  và nên bán combo gì. Tất cả là <b>ước tính từ dữ liệu của bạn</b>, dùng để tham khảo.
</p>
<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px;font-size:13px;">
  <a href="#dong-tien">Dòng tiền 14 ngày</a> · <a href="#abc">Phân tích ABC</a> · <a href="#khach-bo">Khách sắp bỏ đi</a> · <a href="#combo">Combo gợi ý</a>
</div>

<!-- ============ Dong tien ============ -->
<h2 id="dong-tien" style="font-size:18px;margin:0 0 8px;">💰 Dự báo dòng tiền 14 ngày</h2>
<?php if ($tien['thang_am'] !== null): ?>
  <div class="alert alert-error" style="max-width:820px;">⚠ Dự kiến <b>thiếu tiền từ ngày <?= date('d/m', strtotime($tien['thang_am'])) ?></b> (thấp nhất khoảng <?= money($tien['thap_nhat']) ?>).
    Cân nhắc đòi nợ khách, hoãn nhập hàng chưa gấp hoặc đàm phán lùi hạn trả nhà cung cấp.</div>
<?php else: ?>
  <div class="alert alert-success" style="max-width:820px;">✓ Quỹ dự kiến không âm trong 14 ngày tới (thấp nhất khoảng <?= money($tien['thap_nhat']) ?>).</div>
<?php endif; ?>
<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:12px;">
  <div class="card" style="min-width:170px;"><div class="muted" style="font-size:12px;">Quỹ hiện tại (sổ quỹ)</div><div style="font-size:18px;font-weight:700;"><?= money($tien['quy_hien_tai']) ?></div></div>
  <div class="card" style="min-width:170px;"><div class="muted" style="font-size:12px;">Thu dự kiến TB/ngày</div><div style="font-size:18px;font-weight:700;color:#059669;"><?= money($tien['thu_tb']) ?></div></div>
  <div class="card" style="min-width:170px;"><div class="muted" style="font-size:12px;">Chi TB/ngày (cố định + nhập hàng)</div><div style="font-size:18px;font-weight:700;color:#dc2626;"><?= money($tien['chi_tb']) ?></div></div>
  <div class="card" style="min-width:170px;"><div class="muted" style="font-size:12px;">Phải trả NCC trong 14 ngày</div><div style="font-size:18px;font-weight:700;color:#dc2626;"><?= money($tien['ncc_tong']) ?></div></div>
</div>
<div class="card" style="padding:0;overflow-x:auto;margin-bottom:8px;">
  <table>
    <thead><tr><th>Ngày</th><th class="text-right">Thu dự kiến</th><th class="text-right">Chi dự kiến</th><th class="text-right">Trả NCC</th><th class="text-right">Quỹ cuối ngày</th><th style="width:30%;"></th></tr></thead>
    <tbody>
      <?php foreach ($tien['ngay'] as $d): $am = $d['so_du'] < 0; ?>
        <tr>
          <td><?= date('d/m', strtotime($d['date'])) ?> <span class="muted" style="font-size:12px;"><?= ['CN', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7'][(int) date('w', strtotime($d['date']))] ?></span></td>
          <td class="text-right"><?= money($d['thu']) ?></td>
          <td class="text-right"><?= money($d['chi']) ?></td>
          <td class="text-right" style="<?= $d['chi_ncc'] > 0 ? 'color:#dc2626;font-weight:600;' : '' ?>"><?= $d['chi_ncc'] > 0 ? money($d['chi_ncc']) : '—' ?></td>
          <td class="text-right" style="font-weight:600;<?= $am ? 'color:#dc2626;' : '' ?>"><?= money($d['so_du']) ?></td>
          <td><div style="height:8px;border-radius:4px;background:<?= $am ? '#dc2626' : '#2563eb' ?>;width:<?= max(2, (int) round(abs($d['so_du']) / $maxDu * 100)) ?>%;"></div></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<p class="muted" style="font-size:12px;margin:0 0 28px;max-width:820px;">Thu dự kiến = trung bình tiền thu cùng thứ trong 8 tuần qua (gồm cả thu nợ); chi = chi thủ công và chi nhập hàng trả ngay trung bình 30 ngày (không tính chủ rút tiền) + phiếu nhập còn nợ theo hạn trả (quá hạn tính vào hôm nay).</p>

<!-- ============ ABC ============ -->
<h2 id="abc" style="font-size:18px;margin:0 0 8px;">📊 Phân tích ABC theo lãi gộp</h2>
<form style="display:flex;gap:8px;align-items:center;margin-bottom:12px;">
  <label class="muted" style="font-size:13px;">Trong</label>
  <select name="days" class="input" style="width:auto;" onchange="this.form.submit()">
    <?php foreach ([30, 90, 180, 365] as $d): ?><option value="<?= $d ?>" <?= $d === $days ? 'selected' : '' ?>><?= $d ?> ngày qua</option><?php endforeach; ?>
  </select>
</form>
<?php if (!$abc['rows']): ?>
  <div class="card muted" style="max-width:640px;margin-bottom:28px;">Chưa có dữ liệu bán hàng trong <?= $days ?> ngày để phân tích.</div>
<?php else: ?>
  <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:12px;">
    <div class="card" style="min-width:200px;border-left:4px solid #059669;"><b>Nhóm A: <?= (int) $abc['dem']['A'] ?> mặt hàng</b><div class="muted" style="font-size:12px;">Tạo ~80% lãi. <b>Luôn phải đủ hàng</b>, không để hết.</div></div>
    <div class="card" style="min-width:200px;border-left:4px solid #d97706;"><b>Nhóm B: <?= (int) $abc['dem']['B'] ?> mặt hàng</b><div class="muted" style="font-size:12px;">~15% lãi tiếp theo. Giữ tồn vừa phải.</div></div>
    <div class="card" style="min-width:200px;border-left:4px solid #64748b;"><b>Nhóm C: <?= (int) $abc['dem']['C'] ?> mặt hàng</b><div class="muted" style="font-size:12px;">~5% lãi còn lại hoặc lỗ. <b>Hạn chế nhập</b>, cân nhắc ngừng bán.</div></div>
  </div>
  <div class="card" style="padding:0;overflow-x:auto;margin-bottom:8px;">
    <table>
      <thead><tr><th>Nhóm</th><th>Mặt hàng</th><th class="text-right">Đã bán</th><th class="text-right">Lãi gộp</th><th class="text-right">% tổng lãi</th></tr></thead>
      <tbody>
        <?php foreach (array_slice($abc['rows'], 0, 60) as $r): $mau = ['A' => '#059669', 'B' => '#d97706', 'C' => '#64748b'][$r['nhom']]; ?>
          <tr>
            <td><span style="font-weight:700;color:<?= $mau ?>;"><?= $r['nhom'] ?></span></td>
            <td><?= e($r['name']) ?> <span class="muted" style="font-family:monospace;font-size:12px;">(<?= e($r['sku']) ?>)</span></td>
            <td class="text-right"><?= fmtQty($r['sl']) ?></td>
            <td class="text-right" style="<?= (float) $r['lai'] < 0 ? 'color:#dc2626;' : '' ?>"><?= money($r['lai']) ?></td>
            <td class="text-right muted"><?= number_format($r['ty_le'], 1) ?>%</td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="muted" style="font-size:12px;margin:0 0 28px;">Lãi gộp = doanh thu hàng − giá vốn lúc bán. Hàng còn tồn mà không bán được cái nào xem ở <a href="ton_cham.php">Hàng tồn lâu</a>.</p>
<?php endif; ?>

<!-- ============ Khach sap bo ============ -->
<h2 id="khach-bo" style="font-size:18px;margin:0 0 8px;">👋 Khách quen sắp "bỏ" mình</h2>
<p class="muted" style="margin:0 0 8px;font-size:13px;max-width:780px;">Khách đã mua từ 3 đơn, nhưng vắng lâu hơn gấp đôi khoảng cách mua bình thường của chính họ (và ít nhất 21 ngày). Nhắn tin / gọi hỏi thăm, tặng ưu đãi nhỏ để giữ chân.</p>
<div class="card" style="padding:0;overflow-x:auto;margin-bottom:28px;">
  <table>
    <thead><tr><th>Khách</th><th>SĐT</th><th class="text-right">Số đơn</th><th class="text-right">Tổng mua</th><th>Mua thường cách</th><th>Vắng</th></tr></thead>
    <tbody>
      <?php if (!$bo): ?><tr><td colspan="6" class="text-center muted" style="padding:20px;">Chưa có khách quen nào có dấu hiệu bỏ đi (hoặc chưa đủ dữ liệu).</td></tr><?php endif; ?>
      <?php foreach ($bo as $k): ?>
        <tr>
          <td><a href="customer_view.php?id=<?= (int) $k['id'] ?>"><?= e($k['name']) ?></a></td>
          <td><?= $k['phone'] ? '<a href="tel:' . e($k['phone']) . '">' . e($k['phone']) . '</a>' : '—' ?></td>
          <td class="text-right"><?= (int) $k['so_don'] ?></td>
          <td class="text-right"><?= money($k['tong_mua']) ?></td>
          <td class="muted">~<?= (int) round((float) $k['khoang_tb']) ?> ngày/lần</td>
          <td style="color:#d97706;font-weight:600;"><?= (int) $k['ngay_vang'] ?> ngày</td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- ============ Combo ============ -->
<h2 id="combo" style="font-size:18px;margin:0 0 8px;">🧺 Combo gợi ý</h2>
<p class="muted" style="margin:0 0 8px;font-size:13px;max-width:780px;">Các cặp hàng hay được mua chung (90 ngày, từ 3 đơn). Giá combo giảm nhẹ ~5%, không thấp hơn tổng giá vốn. Tạo combo ở <a href="products.php">Sản phẩm</a> (loại Combo).</p>
<div class="card" style="padding:0;overflow-x:auto;margin-bottom:8px;">
  <table>
    <thead><tr><th>Cặp hàng</th><th class="text-right">Mua chung</th><th class="text-right">Giá lẻ</th><th class="text-right">Giá combo gợi ý</th><th class="text-right">Lãi/combo</th></tr></thead>
    <tbody>
      <?php if (!$combos): ?><tr><td colspan="5" class="text-center muted" style="padding:20px;">Chưa có cặp hàng nào được mua chung từ 3 đơn trong 90 ngày.</td></tr><?php endif; ?>
      <?php foreach ($combos as $c): ?>
        <tr>
          <td><?= e($c['a']) ?> + <?= e($c['b']) ?></td>
          <td class="text-right"><?= (int) $c['so_don'] ?> đơn</td>
          <td class="text-right"><?= money($c['gia_le']) ?></td>
          <td class="text-right"><b><?= money($c['gia_combo']) ?></b> <span class="muted" style="font-size:12px;">(−<?= (int) $c['giam_pct'] ?>%)</span></td>
          <td class="text-right"><?= money($c['lai']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<p class="muted" style="font-size:12px;margin:16px 0 0;max-width:820px;">⚖️ Số liệu tính từ dữ liệu bạn nhập, chỉ mang tính tham khảo và không thay thế tư vấn của kế toán. Xem <a href="dieu_khoan.php" target="_blank" rel="noopener">Điều khoản sử dụng</a>.</p>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
