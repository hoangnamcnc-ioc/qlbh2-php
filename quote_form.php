<?php
/** Lap bao gia moi: chon khach, them san pham (tim nhu man ban hang), giam gia, han hieu luc. Luu xong chuyen sang trang xem/in. */
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireRole('ADMIN', 'MANAGER');

$pdo = db();
$tenantId = currentTenantId();
$branchId = effectiveBranchId($currentUser);
$error = null;
$old = ['name' => '', 'phone' => '', 'address' => '', 'valid_days' => 15, 'discount' => 0, 'note' => '', 'lines' => []];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $name = mb_substr(post('customer_name'), 0, 255);
    $phone = mb_substr(post('customer_phone'), 0, 50);
    $address = mb_substr(post('customer_address'), 0, 500);
    $days = max(1, min(365, (int) ($_POST['valid_days'] ?? 15)));
    $discount = max(0.0, postFloat('discount'));
    $note = mb_substr(post('note'), 0, 2000);
    $raw = json_decode((string) ($_POST['lines_json'] ?? '[]'), true);
    $old = ['name' => $name, 'phone' => $phone, 'address' => $address, 'valid_days' => $days, 'discount' => $discount, 'note' => $note, 'lines' => is_array($raw) ? $raw : []];

    $lines = [];
    if (is_array($raw)) {
        $chk = $pdo->prepare('SELECT id FROM products WHERE id = ? AND tenant_id = ?');
        foreach ($raw as $l) {
            $pid = (int) ($l['id'] ?? 0);
            $qty = (float) ($l['qty'] ?? 0);
            $price = (float) ($l['price'] ?? 0);
            if ($pid <= 0 || $qty <= 0 || $price < 0) continue;
            $chk->execute([$pid, $tenantId]);
            if (!$chk->fetchColumn()) continue;
            $lines[] = ['pid' => $pid, 'vid' => !empty($l['variantId']) ? (int) $l['variantId'] : null, 'name' => mb_substr((string) ($l['name'] ?? ''), 0, 255), 'qty' => $qty, 'price' => $price];
        }
    }
    $sub = array_sum(array_map(static fn($l) => $l['qty'] * $l['price'], $lines));
    if ($name === '') {
        $error = 'Nhập tên khách hàng.';
    } elseif (!$lines) {
        $error = 'Thêm ít nhất một sản phẩm.';
    } elseif ($discount > $sub) {
        $error = 'Giảm giá không được lớn hơn tổng tiền hàng.';
    } elseif (!$branchId) {
        $error = 'Tài khoản chưa được gán chi nhánh.';
    } else {
        $cid = null;
        if ($phone !== '') {
            $c = $pdo->prepare('SELECT id FROM customers WHERE tenant_id = ? AND phone = ? LIMIT 1');
            $c->execute([$tenantId, $phone]);
            $cid = $c->fetchColumn() ?: null;
        }
        $pdo->beginTransaction();
        try {
            $n = (int) $pdo->query('SELECT COUNT(*) FROM quotes WHERE tenant_id = ' . $tenantId)->fetchColumn() + 1;
            $code = 'BG' . date('ym') . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
            $pdo->prepare('INSERT INTO quotes (tenant_id, branch_id, code, customer_id, customer_name, customer_phone, customer_address, valid_until, discount, total, note, created_by_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$tenantId, $branchId, $code, $cid, $name, $phone ?: null, $address ?: null, date('Y-m-d', strtotime("+$days days")), $discount, $sub - $discount, $note ?: null, $currentUser['id']]);
            $qid = (int) $pdo->lastInsertId();
            $ins = $pdo->prepare('INSERT INTO quote_items (quote_id, product_id, variant_id, name, quantity, unit_price, line_total) VALUES (?,?,?,?,?,?,?)');
            foreach ($lines as $l) {
                $ins->execute([$qid, $l['pid'], $l['vid'], $l['name'], $l['qty'], $l['price'], round($l['qty'] * $l['price'], 2)]);
            }
            $pdo->commit();
            logActivity('QUOTE_CREATE', "$code total=" . ($sub - $discount));
            redirect('quote_view.php?id=' . $qid);
        } catch (Throwable $ex) {
            $pdo->rollBack();
            $error = 'Không lưu được báo giá, thử lại.';
        }
    }
}

require_once __DIR__ . '/inc_header.php';
?>
<a href="quotes.php" class="muted" style="font-size:14px;">← Báo giá</a>
<h1 style="font-size:24px;font-weight:600;margin:8px 0 14px;">Tạo báo giá</h1>
<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<form method="post" id="qf" class="card" style="max-width:900px;">
  <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="lines_json" id="lines_json">
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-bottom:14px;">
    <div><label>Tên khách hàng</label><input class="input" name="customer_name" value="<?= e($old['name']) ?>" required></div>
    <div><label>Số điện thoại</label><input class="input" name="customer_phone" value="<?= e($old['phone']) ?>"></div>
    <div><label>Địa chỉ</label><input class="input" name="customer_address" value="<?= e($old['address']) ?>"></div>
    <div><label>Hiệu lực (ngày)</label><input class="input" type="number" min="1" max="365" name="valid_days" value="<?= (int) $old['valid_days'] ?>"></div>
  </div>
  <div style="position:relative;margin-bottom:10px;">
    <input type="text" id="search-input" class="input" placeholder="Tìm sản phẩm theo tên, SKU hoặc mã vạch..." autocomplete="off">
    <div id="search-results" style="display:none;position:absolute;z-index:10;margin-top:4px;width:100%;background:#fff;border:1px solid #e2e8f0;border-radius:6px;box-shadow:0 8px 20px -8px rgba(0,0,0,.2);max-height:260px;overflow:auto;"></div>
  </div>
  <div style="overflow-x:auto;"><table>
    <thead><tr><th>Sản phẩm</th><th style="width:110px;">Số lượng</th><th style="width:150px;">Đơn giá</th><th style="text-align:right;width:140px;">Thành tiền</th><th style="width:44px;"></th></tr></thead>
    <tbody id="lines-body"></tbody>
  </table></div>
  <div style="display:flex;justify-content:flex-end;gap:16px;align-items:center;margin:12px 0;flex-wrap:wrap;">
    <div><label>Giảm giá</label><input class="input" type="number" min="0" step="1" name="discount" id="discount" value="<?= e((string) (int) $old['discount']) ?>" style="width:150px;"></div>
    <div style="text-align:right;">Tổng báo giá<div style="font-size:22px;font-weight:700;color:#2563eb;" id="grand">0</div></div>
  </div>
  <div style="margin-bottom:12px;"><label>Ghi chú, điều khoản (thanh toán, giao hàng, bảo hành...)</label><textarea class="input" name="note" rows="3"><?= e($old['note']) ?></textarea></div>
  <button class="btn" type="submit">Lưu báo giá</button>
</form>

<script>
let lines = <?= json_encode(array_map(static fn($l) => ['key' => ($l['id'] ?? 0) . ':' . ($l['variantId'] ?? ''), 'id' => (int) ($l['id'] ?? 0), 'variantId' => $l['variantId'] ?? null, 'name' => (string) ($l['name'] ?? ''), 'qty' => (float) ($l['qty'] ?? 1), 'price' => (float) ($l['price'] ?? 0)], $old['lines']), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const searchInput = document.getElementById('search-input'), searchResults = document.getElementById('search-results');
let timer = null;
const esc = t => String(t ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const fmt = n => new Intl.NumberFormat('vi-VN').format(Math.round(n));

function addLine(p) {
  const key = p.id + ':' + (p.variant_id ?? '');
  const ex = lines.find(l => l.key === key);
  if (ex) ex.qty += 1; else lines.push({ key, id: p.id, variantId: p.variant_id ?? null, name: p.name, qty: 1, price: parseFloat(p.sell_price) || 0 });
  render();
}
searchInput.addEventListener('input', () => {
  clearTimeout(timer);
  const q = searchInput.value.trim();
  if (!q) { searchResults.style.display = 'none'; return; }
  timer = setTimeout(() => fetch('pos_search.php?q=' + encodeURIComponent(q)).then(r => r.json()).then(data => {
    if (!data.length) { searchResults.style.display = 'none'; return; }
    searchResults.innerHTML = data.map((p, i) => `<div class="s-item" data-i="${i}" style="padding:8px 12px;font-size:14px;cursor:pointer;">${esc(p.name)} <span class="muted" style="font-size:12px;">${fmt(p.sell_price)}</span></div>`).join('');
    searchResults.style.display = 'block';
    searchResults.querySelectorAll('.s-item').forEach(el => el.addEventListener('click', () => { addLine(data[+el.dataset.i]); searchInput.value = ''; searchResults.style.display = 'none'; }));
  }), 250);
});
searchInput.addEventListener('keydown', e => {
  if (e.key !== 'Enter') return;
  e.preventDefault();
  const q = searchInput.value.trim();
  if (!q) return;
  fetch('pos_search.php?q=' + encodeURIComponent(q)).then(r => r.json()).then(d => { const p = d.length === 1 ? d[0] : d.find(x => x.sku === q); if (p) { addLine(p); searchInput.value = ''; searchResults.style.display = 'none'; } });
});
function render() {
  const body = document.getElementById('lines-body');
  body.innerHTML = lines.length ? lines.map((l, i) => `<tr><td>${esc(l.name)}</td>
    <td><input class="input" type="number" min="0.001" step="any" value="${l.qty}" data-i="${i}" data-f="qty"></td>
    <td><input class="input" type="number" min="0" step="1" value="${l.price}" data-i="${i}" data-f="price"></td>
    <td style="text-align:right;">${fmt(l.qty * l.price)}</td>
    <td><button type="button" class="btn btn-secondary" style="padding:2px 8px;" data-del="${i}">×</button></td></tr>`).join('')
    : '<tr><td colspan="5" class="muted" style="text-align:center;padding:20px;">Chưa có sản phẩm nào</td></tr>';
  body.querySelectorAll('input').forEach(el => el.addEventListener('input', () => { lines[+el.dataset.i][el.dataset.f] = parseFloat(el.value) || 0; totals(false); }));
  body.querySelectorAll('[data-del]').forEach(b => b.addEventListener('click', () => { lines.splice(+b.dataset.del, 1); render(); }));
  totals(true);
}
function totals() {
  const sub = lines.reduce((s, l) => s + l.qty * l.price, 0), d = parseFloat(document.getElementById('discount').value) || 0;
  document.getElementById('grand').textContent = fmt(Math.max(0, sub - d));
  document.querySelectorAll('#lines-body tr').forEach((tr, i) => { const td = tr.children[3]; if (td && lines[i]) td.textContent = fmt(lines[i].qty * lines[i].price); });
}
document.getElementById('discount').addEventListener('input', totals);
document.getElementById('qf').addEventListener('submit', () => { document.getElementById('lines_json').value = JSON.stringify(lines); });
render();
</script>
<?php require_once __DIR__ . '/inc_footer.php'; ?>
