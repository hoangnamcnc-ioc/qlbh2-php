<?php
/**
 * Nhac no khach hang (muc "Nhac no khach hang" trong roadmap).
 *
 * KHONG co san cot "han tra no" trong schema (customers chi co cot debt = so du hien tai), nen
 * "qua han" o day duoc dinh nghia bang HEURISTIC hop ly nhat co the tu du lieu da co: so ngay ke
 * tu lan PHAT SINH/CAP NHAT no gan nhat (MAX(created_at) trong customer_debt_entries) - khach
 * cang lau khong tra/mua them thi cang len dau danh sach.
 *
 * KHONG tich hop API Zalo that (thu tuc duyet Zalo OA rat mat cong, ngoai pham vi 1 lan lam) -
 * chi soan san noi dung tin nhan de chu quan tu bam Sao chep roi dan vao Zalo/SMS.
 */
require_once __DIR__ . '/inc_header.php';

$pdo = db();
$tenantId = currentTenantId();
$minDays = max(0, (int) ($_GET['min_days'] ?? 7));
$storeName = getSetting('store_name', '');

$stmt = $pdo->prepare(
    'SELECT c.id, c.name, c.phone, c.debt,
            (SELECT MAX(de.created_at) FROM customer_debt_entries de WHERE de.customer_id = c.id) AS last_debt_at
     FROM customers c
     WHERE c.tenant_id = ? AND c.debt > 0
     HAVING last_debt_at IS NULL OR last_debt_at <= DATE_SUB(NOW(), INTERVAL ? DAY)
     ORDER BY last_debt_at ASC'
);
$stmt->execute([$tenantId, $minDays]);
$rows = $stmt->fetchAll();

$tongNo = array_sum(array_column($rows, 'debt'));
?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;gap:8px;flex-wrap:wrap;">
  <h1 style="font-size:24px;font-weight:600;margin:0;">Nhắc nợ khách hàng</h1>
  <a href="customers.php" class="btn btn-secondary">Danh sách khách hàng</a>
</div>
<p class="muted" style="margin:0 0 16px;font-size:13px;max-width:680px;">
  Hệ thống không có "hạn trả nợ" cụ thể cho từng khách, nên xếp theo <b>số ngày kể từ lần phát
  sinh/cập nhật công nợ gần nhất</b> — khách càng lâu không mua thêm hoặc trả bớt thì càng lên
  đầu danh sách. Không tự gửi tin nhắn (Zalo yêu cầu đăng ký tài khoản doanh nghiệp riêng) — bấm
  <b>Sao chép</b> rồi dán vào Zalo/SMS gửi tay.
</p>

<form style="margin-bottom:16px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
  <label class="muted" style="font-size:13px;">Chưa cập nhật công nợ quá</label>
  <select name="min_days" class="input" style="max-width:140px;" onchange="this.form.submit()">
    <?php foreach ([0, 3, 7, 14, 30, 60] as $d): ?>
      <option value="<?= $d ?>" <?= $minDays === $d ? 'selected' : '' ?>><?= $d ?> ngày</option>
    <?php endforeach; ?>
  </select>
</form>

<div class="card" style="max-width:320px;margin-bottom:16px;">
  <div class="muted" style="font-size:12px;">Tổng công nợ trong danh sách</div>
  <div style="font-size:22px;font-weight:700;color:#dc2626;"><?= money($tongNo) ?></div>
</div>

<div class="card" style="padding:0;overflow-x:auto;">
  <table>
    <thead><tr><th>Khách hàng</th><th>SĐT</th><th class="text-right">Công nợ</th><th>Cập nhật gần nhất</th><th></th></tr></thead>
    <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="5" class="text-center muted" style="padding:32px;">Không có khách nào đang nợ quá <?= $minDays ?> ngày.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <?php
          $soNgay = $r['last_debt_at'] ? (int) floor((time() - strtotime($r['last_debt_at'])) / 86400) : null;
          $tin = "Chào {$r['name']}, " . ($storeName ? "$storeName xin " : '') . "nhắc bạn hiện còn khoản công nợ "
               . money((float) $r['debt']) . "đ tại cửa hàng. Bạn sắp xếp thanh toán giúp mình nhé, cảm ơn bạn!";
        ?>
        <tr>
          <td><a href="customer_view.php?id=<?= (int) $r['id'] ?>"><?= e($r['name']) ?></a></td>
          <td class="muted" style="font-family:monospace;"><?= e($r['phone'] ?: '—') ?></td>
          <td class="text-right" style="color:#dc2626;font-weight:600;"><?= money($r['debt']) ?></td>
          <td class="muted">
            <?= $r['last_debt_at'] ? date('d/m/Y', strtotime($r['last_debt_at'])) . " ($soNgay ngày trước)" : 'Không rõ' ?>
          </td>
          <td class="text-right" style="white-space:nowrap;">
            <textarea class="tin-nhan" style="position:absolute;left:-9999px;"><?= e($tin) ?></textarea>
            <button type="button" class="btn btn-secondary copy-btn" style="padding:4px 10px;font-size:12px;" data-tin="<?= e($tin) ?>">Sao chép tin nhắn</button>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<script>
document.querySelectorAll('.copy-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    navigator.clipboard.writeText(btn.dataset.tin).then(() => {
      const old = btn.textContent;
      btn.textContent = 'Đã sao chép ✓';
      setTimeout(() => { btn.textContent = old; }, 1500);
    });
  });
});
</script>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
