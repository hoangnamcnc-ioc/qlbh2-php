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
$suc = diemSucKhoe($pdo, $tenantId, $branchId);
// Luu diem moi ngay de so sanh xu huong ve sau (store_settings, khong can bang moi)
$hoy = date('Y-m-d');
if (getSetting('health_' . $hoy, '') === '') {
    setSetting('health_' . $hoy, (string) $suc['diem']);
}
$truoc7 = getSetting('health_' . date('Y-m-d', strtotime('-7 days')), '');
$truoc30 = getSetting('health_' . date('Y-m-d', strtotime('-30 days')), '');
$noKhach = xepHangDoiNo($pdo, $tenantId, getSetting('store_name', ''));
$nangSuat = max(1, min(200, (int) ($_GET['ns'] ?? 12)));
$cao = gioCaoDiem($pdo, $tenantId, $branchId, $nangSuat);
$simId = (int) ($_GET['sim'] ?? 0);
$mp = $simId ? moPhongGia($pdo, $tenantId, $simId, $branchId) : null;
$dsSp = $pdo->prepare("SELECT id, name, sku FROM products WHERE tenant_id = ? AND is_active = 1 AND product_type = 'PRODUCT' ORDER BY name LIMIT 500");
$dsSp->execute([$tenantId]);
$dsSp = $dsSp->fetchAll();
$tenThu = ['CN', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7'];
$maxDu = max(1.0, max(array_map(static fn($d) => abs($d['so_du']), $tien['ngay'])));

require_once __DIR__ . '/inc_header.php';
?>

<?= thanhTabNghiepVu('baocao') ?>
<h1 style="font-size:24px;font-weight:600;margin:0 0 8px;">Phân tích kinh doanh</h1>
<p class="muted" style="margin:0 0 16px;font-size:13px;max-width:780px;">
  Bốn công cụ giúp quyết định nhanh: nên dồn vốn vào hàng nào (ABC), có thiếu tiền trong 2 tuần tới không, khách quen nào sắp bỏ đi,
  và nên bán combo gì. Tất cả là <b>ước tính từ dữ liệu của bạn</b>, dùng để tham khảo.
</p>
<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px;font-size:13px;">
  <a href="#suc-khoe">Điểm sức khỏe</a> · <a href="#dong-tien">Dòng tiền 14 ngày</a> · <a href="#abc">Phân tích ABC</a> · <a href="#khach-bo">Khách sắp bỏ đi</a> · <a href="#combo">Combo gợi ý</a> ·
  <a href="#doi-no">Nhắc nợ thông minh</a> · <a href="#gio-cao-diem">Giờ cao điểm</a> · <a href="#mo-phong">Mô phỏng đổi giá</a>
</div>

<!-- ============ Diem suc khoe ============ -->
<h2 id="suc-khoe" style="font-size:18px;margin:0 0 8px;">❤️ Điểm sức khỏe cửa hàng</h2>
<?php $mauDiem = $suc['diem'] >= 80 ? '#059669' : ($suc['diem'] >= 60 ? '#2563eb' : ($suc['diem'] >= 40 ? '#d97706' : '#dc2626')); ?>
<div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-start;margin-bottom:12px;">
  <div class="card" style="min-width:200px;text-align:center;">
    <div style="font-size:52px;font-weight:800;line-height:1;color:<?= $mauDiem ?>;"><?= (int) $suc['diem'] ?></div>
    <div style="font-weight:600;color:<?= $mauDiem ?>;"><?= e($suc['xep_loai']) ?> · trên 100</div>
    <div class="muted" style="font-size:12px;margin-top:6px;">
      <?= $truoc7 !== '' ? '7 ngày trước: ' . e($truoc7) . '<br>' : '' ?><?= $truoc30 !== '' ? '30 ngày trước: ' . e($truoc30) : '' ?>
      <?= ($truoc7 === '' && $truoc30 === '') ? 'Điểm được lưu mỗi ngày để so sánh xu hướng từ lần sau.' : '' ?>
    </div>
  </div>
  <div class="card" style="flex:1;min-width:300px;">
    <?php foreach ($suc['muc'] as [$ten, $d, $max, $ct, $tip]): $pc = (int) round($d / $max * 100); ?>
      <div style="margin-bottom:8px;">
        <div style="display:flex;justify-content:space-between;font-size:13px;"><b><?= e($ten) ?></b><span><?= number_format($d, 1) ?>/<?= (int) $max ?></span></div>
        <div style="height:6px;background:#e2e8f0;border-radius:3px;"><div style="height:6px;border-radius:3px;width:<?= $pc ?>%;background:<?= $pc >= 70 ? '#059669' : ($pc >= 40 ? '#d97706' : '#dc2626') ?>;"></div></div>
        <div class="muted" style="font-size:12px;"><?= e($ct) ?><?= $tip !== '' ? ' — <span style="color:#d97706;">' . e($tip) . '</span>' : '' ?></div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<p class="muted" style="font-size:12px;margin:0 0 28px;">Điểm = biên lãi gộp (25) + tăng trưởng doanh thu (15) + vốn không chôn vào hàng ế (15) + không có hàng hết hạn (15) + nợ khó đòi thấp (15) + ít thất thoát (15). Chỉ để theo dõi xu hướng.</p>

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

<!-- ============ Nhac no thong minh ============ -->
<h2 id="doi-no" style="font-size:18px;margin:24px 0 8px;">📞 Nhắc nợ thông minh</h2>
<p class="muted" style="margin:0 0 8px;font-size:13px;max-width:780px;">Xếp khách theo khả năng đòi được: khách còn mua gần đây và từng trả nợ thì <b>dễ đòi trước</b>; nợ lâu, nợ lớn hơn 3 tháng mua của họ thì khó đòi hơn. Tin nhắn soạn sẵn theo mức độ (nhẹ / nhắc lịch / quyết liệt) — bấm Sao chép rồi dán vào Zalo/SMS.</p>
<div class="card" style="padding:0;overflow-x:auto;margin-bottom:28px;">
  <table>
    <thead><tr><th>Khách</th><th class="text-right">Nợ</th><th>Nợ được</th><th>Khả năng đòi</th><th>Tin nhắn gợi ý</th></tr></thead>
    <tbody>
      <?php if (!$noKhach): ?><tr><td colspan="5" class="text-center muted" style="padding:20px;">Không có khách nào đang nợ.</td></tr><?php endif; ?>
      <?php foreach (array_slice($noKhach, 0, 30) as $i => $k): $lab = ['De doi' => ['Dễ đòi', '#059669'], 'Trung binh' => ['Trung bình', '#d97706'], 'Kho doi' => ['Khó đòi', '#dc2626']][$k['muc']]; ?>
        <tr>
          <td><a href="customer_view.php?id=<?= (int) $k['id'] ?>"><?= e($k['name']) ?></a><div class="muted" style="font-size:12px;"><?= $k['phone'] ? '<a href="tel:' . e($k['phone']) . '">' . e($k['phone']) . '</a>' : 'chưa có SĐT' ?></div></td>
          <td class="text-right"><b><?= money($k['debt']) ?></b></td>
          <td><?= (int) $k['tuoi'] ?> ngày<div class="muted" style="font-size:12px;">đã trả <?= (int) $k['so_lan_tra'] ?> lần</div></td>
          <td><span style="font-weight:700;color:<?= $lab[1] ?>;"><?= $lab[0] ?></span> <span class="muted" style="font-size:12px;">(<?= (int) $k['diem'] ?>/100)</span></td>
          <td style="max-width:360px;"><div class="muted" style="font-size:12px;" id="msg-<?= $i ?>"><?= e($k['tin_nhan']) ?></div>
            <button type="button" class="btn btn-secondary copy-msg" data-target="msg-<?= $i ?>" style="padding:2px 10px;font-size:12px;margin-top:4px;">Sao chép</button></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- ============ Gio cao diem ============ -->
<h2 id="gio-cao-diem" style="font-size:18px;margin:0 0 8px;">⏰ Giờ cao điểm và gợi ý số nhân viên</h2>
<form style="display:flex;gap:8px;align-items:center;margin-bottom:12px;flex-wrap:wrap;">
  <label class="muted" style="font-size:13px;">1 nhân viên phục vụ được khoảng</label>
  <input type="number" name="ns" min="1" max="200" value="<?= (int) $nangSuat ?>" class="input" style="width:80px;">
  <span class="muted" style="font-size:13px;">đơn/giờ</span>
  <button class="btn btn-secondary" type="submit">Tính lại</button>
</form>
<?php if (!$cao['luoi']): ?>
  <div class="card muted" style="max-width:640px;margin-bottom:28px;">Chưa có dữ liệu đơn hàng 8 tuần để vẽ giờ cao điểm.</div>
<?php else: [$g0, $g1] = $cao['gio']; $maxDon = max(1.0, max(array_map(static fn($r) => max($r), $cao['luoi']))); ?>
  <div class="card" style="padding:12px;overflow-x:auto;margin-bottom:8px;">
    <table style="font-size:12px;">
      <thead><tr><th></th><?php for ($g = $g0; $g <= $g1; $g++): ?><th class="text-center"><?= $g ?>h</th><?php endfor; ?></tr></thead>
      <tbody>
        <?php foreach ([1, 2, 3, 4, 5, 6, 0] as $thu): ?>
          <tr><td><b><?= $tenThu[$thu] ?></b></td>
            <?php for ($g = $g0; $g <= $g1; $g++): $v = $cao['luoi'][$thu][$g] ?? 0; $a = $v > 0 ? 0.12 + 0.88 * $v / $maxDon : 0; ?>
              <td class="text-center" style="background:rgba(37,99,235,<?= round($a, 2) ?>);color:<?= $a > 0.55 ? '#fff' : 'inherit' ?>;min-width:34px;" title="<?= $v > 0 ? number_format($v, 1) . ' đơn/giờ, cần ~' . ($cao['nv'][$thu][$g] ?? 1) . ' người' : 'không có đơn' ?>"><?= $v > 0 ? ($cao['nv'][$thu][$g] ?? 1) : '' ?></td>
            <?php endfor; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="muted" style="font-size:12px;margin:0 0 6px;">Số trong ô = số nhân viên gợi ý (đơn TB mỗi giờ ÷ <?= (int) $nangSuat ?>, làm tròn lên); ô càng đậm càng đông. Rê chuột vào ô để xem số đơn.</p>
  <p style="font-size:13px;margin:0 0 28px;"><b>Cao điểm nhất:</b>
    <?php foreach ($cao['top'] as $t): ?><span style="margin-right:12px;"><?= $tenThu[$t['thu']] ?> <?= (int) $t['gio'] ?>h (~<?= number_format($t['don'], 1) ?> đơn)</span><?php endforeach; ?></p>
<?php endif; ?>

<!-- ============ Mo phong doi gia ============ -->
<h2 id="mo-phong" style="font-size:18px;margin:0 0 8px;">🧮 Mô phỏng "nếu tăng/giảm giá X%"</h2>
<form style="display:flex;gap:8px;align-items:center;margin-bottom:12px;flex-wrap:wrap;" action="#mo-phong">
  <select name="sim" class="input" style="max-width:320px;" required>
    <option value="">— Chọn mặt hàng —</option>
    <?php foreach ($dsSp as $sp): ?><option value="<?= (int) $sp['id'] ?>" <?= (int) $sp['id'] === $simId ? 'selected' : '' ?>><?= e($sp['name']) ?> (<?= e($sp['sku']) ?>)</option><?php endforeach; ?>
  </select>
  <button class="btn" type="submit">Mô phỏng</button>
</form>
<?php if ($simId && !$mp): ?><div class="alert alert-error">Không tìm thấy mặt hàng.</div><?php endif; ?>
<?php if ($mp): ?>
  <div class="card" style="padding:0;overflow-x:auto;margin-bottom:6px;">
    <table>
      <thead><tr><th colspan="5"><?= e($mp['ten']) ?> — giá <?= money($mp['gia']) ?>, vốn <?= money($mp['von']) ?>, bán 30 ngày: <?= fmtQty($mp['q30']) ?>, lãi hiện tại <?= money($mp['lai_hien_tai']) ?></th></tr>
        <tr><th>Đổi giá</th><th class="text-right">Giá mới</th><th class="text-right">Lượng bán/30 ngày</th><th class="text-right">Lãi gộp</th><th class="text-right">So với hiện tại</th></tr></thead>
      <tbody>
        <?php foreach ($mp['kich_ban'] as $k): $tot = $mp['tot_nhat'] && $k['pct'] === $mp['tot_nhat']['pct']; ?>
          <tr style="<?= $tot ? 'background:#f0fdf4;font-weight:600;' : '' ?>">
            <td><?= $k['pct'] > 0 ? '+' : '' ?><?= (int) $k['pct'] ?>%<?= $tot ? ' ★' : '' ?></td>
            <td class="text-right"><?= money($k['gia']) ?></td>
            <td class="text-right"><?= fmtQty(round($k['sl'], 1)) ?></td>
            <td class="text-right"><?= money($k['lai']) ?></td>
            <td class="text-right" style="color:<?= $k['doi'] >= 0 ? '#059669' : '#dc2626' ?>;"><?= $k['doi'] >= 0 ? '+' : '' ?><?= money($k['doi']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="muted" style="font-size:12px;margin:0 0 28px;max-width:820px;">Độ nhạy giá: <b><?= number_format($mp['e'], 2, ',', '') ?></b> — <?= e($mp['nguon']) ?>. Công thức: lượng mới = lượng cũ × (1 + X%)<sup>độ nhạy</sup>. Đây là ước tính, thị trường thực tế còn phụ thuộc đối thủ và mùa vụ; hãy thử thay đổi nhỏ trước. ★ = mức cho lãi cao nhất trong các kịch bản.</p>
<?php elseif (!$simId): ?><p class="muted" style="font-size:13px;margin:0 0 28px;">Chọn một mặt hàng để xem lãi thay đổi thế nào nếu tăng hoặc giảm giá.</p><?php endif; ?>

<script>
document.querySelectorAll('.copy-msg').forEach(b => b.addEventListener('click', () => {
  const t = document.getElementById(b.dataset.target).textContent;
  (navigator.clipboard ? navigator.clipboard.writeText(t) : Promise.reject()).then(() => { b.textContent = 'Đã chép'; setTimeout(() => b.textContent = 'Sao chép', 1500); })
    .catch(() => { const r = document.createRange(); r.selectNodeContents(document.getElementById(b.dataset.target)); const s = getSelection(); s.removeAllRanges(); s.addRange(r); });
}));
</script>

<p class="muted" style="font-size:12px;margin:16px 0 0;max-width:820px;">⚖️ Số liệu tính từ dữ liệu bạn nhập, chỉ mang tính tham khảo và không thay thế tư vấn của kế toán. Xem <a href="dieu_khoan.php" target="_blank" rel="noopener">Điều khoản sử dụng</a>.</p>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
