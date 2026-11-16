<?php
/**
 * San xuat va gia thanh: dinh muc nguyen lieu cho thanh pham, phieu san xuat tru kho nguyen lieu, nhap kho thanh pham,
 * gia von thanh pham = (gia tri nguyen lieu + chi phi nhan cong, chi phi chung) / so luong. Gia von nay dung khi ban thanh pham.
 */
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireRole('ADMIN', 'MANAGER');

$pdo = db();
$tenantId = currentTenantId();
$branchId = effectiveBranchId($currentUser);
$error = null;
$tab = in_array($_GET['tab'] ?? '', ['recipe', 'history'], true) ? $_GET['tab'] : 'run';

$loadProduct = static function (int $id) use ($pdo, $tenantId) {
    $st = $pdo->prepare("SELECT id, name, sku, cost_price, sell_price FROM products WHERE id = ? AND tenant_id = ? AND is_active = 1 AND product_type = 'PRODUCT'");
    $st->execute([$id, $tenantId]);
    return $st->fetch() ?: null;
};
$recipeOf = static function (int $pid) use ($pdo, $tenantId): array {
    $st = $pdo->prepare('SELECT r.material_id, r.qty_per_unit, p.name, p.sku, p.cost_price FROM product_recipes r JOIN products p ON p.id = r.material_id WHERE r.tenant_id = ? AND r.product_id = ? ORDER BY p.name');
    $st->execute([$tenantId, $pid]);
    return $st->fetchAll();
};

// Dinh muc cua mot thanh pham (JSON) de form san xuat tu dien nguyen lieu
if (($_GET['a'] ?? '') === 'recipe') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($recipeOf((int) ($_GET['pid'] ?? 0)), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'save_recipe') {
        $pid = (int) ($_POST['product_id'] ?? 0);
        $raw = json_decode((string) ($_POST['lines_json'] ?? '[]'), true) ?: [];
        $fin = $loadProduct($pid);
        $clean = [];
        foreach ($raw as $l) {
            $mid = (int) ($l['id'] ?? 0);
            $q = (float) ($l['qty'] ?? 0);
            if ($mid <= 0 || $q <= 0 || $mid === $pid || !$loadProduct($mid)) continue;
            $clean[$mid] = $q;
        }
        if (!$fin) {
            $error = 'Chọn thành phẩm.';
        } elseif (!$clean) {
            $error = 'Thêm ít nhất một nguyên liệu (khác thành phẩm).';
        } else {
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM product_recipes WHERE tenant_id = ? AND product_id = ?')->execute([$tenantId, $pid]);
            $ins = $pdo->prepare('INSERT INTO product_recipes (tenant_id, product_id, material_id, qty_per_unit) VALUES (?,?,?,?)');
            foreach ($clean as $mid => $q) $ins->execute([$tenantId, $pid, $mid, $q]);
            $pdo->commit();
            logActivity('RECIPE_SAVE', $fin['name']);
            redirect('san_xuat.php?tab=recipe&ok=1');
        }
        $tab = 'recipe';
    } elseif ($action === 'produce') {
        $pid = (int) ($_POST['product_id'] ?? 0);
        $qty = (float) ($_POST['qty'] ?? 0);
        $extra = max(0.0, postFloat('extra_cost'));
        $raw = json_decode((string) ($_POST['lines_json'] ?? '[]'), true) ?: [];
        $fin = $loadProduct($pid);
        $date = (string) ($_POST['prod_date'] ?? date('Y-m-d'));
        if (!$fin) {
            $error = 'Chọn thành phẩm.';
        } elseif ($qty <= 0) {
            $error = 'Số lượng sản xuất phải lớn hơn 0.';
        } elseif (!$branchId) {
            $error = 'Tài khoản chưa được gán chi nhánh.';
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > date('Y-m-d')) {
            $error = 'Ngày sản xuất không hợp lệ.';
        } elseif (kyDaKhoa($date)) {
            $error = 'Ngày này thuộc kỳ đã khóa sổ.';
        } else {
            $mats = [];
            foreach ($raw as $l) {
                $mid = (int) ($l['id'] ?? 0);
                $q = (float) ($l['qty'] ?? 0);
                if ($mid > 0 && $q > 0 && $mid !== $pid) $mats[$mid] = ($mats[$mid] ?? 0) + $q;
            }
            if (!$mats) {
                $error = 'Chưa có nguyên liệu nào.';
            } else {
                $pdo->beginTransaction();
                try {
                    $items = [];
                    $matCost = 0.0;
                    foreach ($mats as $mid => $q) {
                        $mp = $loadProduct($mid);
                        if (!$mp) throw new RuntimeException('Nguyên liệu không hợp lệ.');
                        $inv = $pdo->prepare('SELECT id, quantity FROM inventory WHERE branch_id = ? AND product_id = ? AND variant_id IS NULL FOR UPDATE');
                        $inv->execute([$branchId, $mid]);
                        $row = $inv->fetch();
                        if (!$row || (float) $row['quantity'] < $q) {
                            throw new RuntimeException('Không đủ tồn kho "' . $mp['name'] . '": cần ' . $q . ', còn ' . ($row ? (float) $row['quantity'] : 0) . '.');
                        }
                        $unit = (float) $mp['cost_price'];
                        $items[] = [$mid, $q, $unit, round($q * $unit, 2), (int) $row['id']];
                        $matCost += $q * $unit;
                    }
                    $total = round($matCost + $extra, 2);
                    $unitCost = round($total / $qty, 2);
                    $code = 'SX' . substr((string) (int) round(microtime(true) * 1000), -8);
                    $pdo->prepare('INSERT INTO productions (tenant_id, branch_id, code, product_id, qty, material_cost, extra_cost, unit_cost, note, prod_date, created_by_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                        ->execute([$tenantId, $branchId, $code, $pid, $qty, round($matCost, 2), $extra, $unitCost, mb_substr(post('note'), 0, 255) ?: null, $date, $currentUser['id']]);
                    $prodId = (int) $pdo->lastInsertId();
                    $pi = $pdo->prepare('INSERT INTO production_items (production_id, material_id, qty, unit_cost, amount) VALUES (?,?,?,?,?)');
                    foreach ($items as [$mid, $q, $unit, $amt, $invId]) {
                        $pi->execute([$prodId, $mid, $q, $unit, $amt]);
                        $pdo->prepare('UPDATE inventory SET quantity = quantity - ? WHERE id = ?')->execute([$q, $invId]);
                    }
                    $fi = $pdo->prepare('SELECT id FROM inventory WHERE branch_id = ? AND product_id = ? AND variant_id IS NULL FOR UPDATE');
                    $fi->execute([$branchId, $pid]);
                    if ($fid = $fi->fetchColumn()) {
                        $pdo->prepare('UPDATE inventory SET quantity = quantity + ? WHERE id = ?')->execute([$qty, $fid]);
                    } else {
                        $pdo->prepare('INSERT INTO inventory (branch_id, product_id, variant_id, quantity) VALUES (?,?,NULL,?)')->execute([$branchId, $pid, $qty]);
                    }
                    capNhatGiaVonKhiNhap($pdo, $pid, null, $qty, $unitCost);
                    $pdo->commit();
                    logActivity('PRODUCTION', "$code {$fin['name']} x$qty gia thanh/don vi=$unitCost");
                    redirect('san_xuat.php?tab=history&ok=' . urlencode("Đã sản xuất $qty {$fin['name']}, giá thành " . number_format($unitCost, 0, ',', '.') . ' đ/đơn vị.'));
                } catch (Throwable $ex) {
                    $pdo->rollBack();
                    $error = $ex instanceof RuntimeException ? $ex->getMessage() : 'Không lưu được phiếu sản xuất, thử lại.';
                }
            }
        }
        $tab = 'run';
    }
}

$finished = $pdo->prepare("SELECT DISTINCT p.id, p.name, p.sku FROM product_recipes r JOIN products p ON p.id = r.product_id WHERE r.tenant_id = ? ORDER BY p.name");
$finished->execute([$tenantId]);
$finished = $finished->fetchAll();
$hist = $pdo->prepare('SELECT x.*, p.name AS pname, b.name AS bname FROM productions x JOIN products p ON p.id = x.product_id JOIN branches b ON b.id = x.branch_id WHERE x.tenant_id = ? ORDER BY x.prod_date DESC, x.id DESC LIMIT 60');
$hist->execute([$tenantId]);
$hist = $hist->fetchAll();
$editPid = (int) ($_GET['pid'] ?? 0);

require_once __DIR__ . '/inc_header.php';
echo thanhTabNghiepVu('kho');
$tabs = ['run' => 'Sản xuất', 'recipe' => 'Định mức nguyên liệu', 'history' => 'Lịch sử và giá thành'];
?>
<h1 style="font-size:24px;font-weight:600;margin-bottom:6px;">Sản xuất và giá thành</h1>
<p class="muted" style="margin:0 0 14px;max-width:780px;font-size:14px;">
  Khai báo nguyên liệu cho một thành phẩm, mỗi lần sản xuất phần mềm trừ kho nguyên liệu, nhập kho thành phẩm và tính giá thành
  = (giá trị nguyên liệu + nhân công, chi phí chung) / số lượng. Giá thành trở thành giá vốn khi bán thành phẩm. Nguyên liệu và thành phẩm đều là sản phẩm trong danh mục.
</p>
<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
<?php if (!empty($_GET['ok'])): ?><div class="alert alert-success"><?= $_GET['ok'] === '1' ? 'Đã lưu.' : e((string) $_GET['ok']) ?></div><?php endif; ?>
<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px;border-bottom:1px solid #e2e8f0;padding-bottom:8px;">
  <?php foreach ($tabs as $k => $v): ?><a href="san_xuat.php?tab=<?= e($k) ?>" style="padding:6px 12px;border-radius:6px;font-size:13px;text-decoration:none;<?= $k === $tab ? 'background:var(--brand,#2563eb);color:#fff;font-weight:600;' : 'background:#f1f5f9;color:#334155;' ?>"><?= e($v) ?></a><?php endforeach; ?>
</div>

<?php if ($tab === 'history'): ?>
<div class="card" style="overflow-x:auto;">
  <table>
    <thead><tr><th>Ngày</th><th>Phiếu</th><th>Thành phẩm</th><th style="text-align:right;">Số lượng</th><th style="text-align:right;">Nguyên liệu</th><th style="text-align:right;">Nhân công, chung</th><th style="text-align:right;">Giá thành/đơn vị</th></tr></thead>
    <tbody>
    <?php if (!$hist): ?><tr><td colspan="7" class="muted" style="text-align:center;padding:24px;">Chưa có phiếu sản xuất nào.</td></tr><?php endif; ?>
    <?php foreach ($hist as $h): ?>
      <tr><td><?= e(date('d/m/Y', strtotime($h['prod_date']))) ?></td><td><?= e($h['code']) ?><div class="muted" style="font-size:12px;"><?= e($h['bname']) ?></div></td><td><?= e($h['pname']) ?></td>
        <td style="text-align:right;"><?= rtrim(rtrim(number_format((float) $h['qty'], 3, ',', '.'), '0'), ',') ?></td><td style="text-align:right;"><?= money($h['material_cost']) ?></td><td style="text-align:right;"><?= money($h['extra_cost']) ?></td><td style="text-align:right;font-weight:700;"><?= money($h['unit_cost']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php else: ?>
<form method="post" id="sf" class="card" style="max-width:900px;">
  <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="lines_json" id="lines_json">
  <input type="hidden" name="action" value="<?= $tab === 'recipe' ? 'save_recipe' : 'produce' ?>">
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-bottom:12px;">
    <?php if ($tab === 'run'): ?>
      <div><label>Thành phẩm (đã có định mức)</label>
        <select name="product_id" id="fin" required><option value="">— chọn —</option><?php foreach ($finished as $f): ?><option value="<?= (int) $f['id'] ?>"><?= e($f['name']) ?></option><?php endforeach; ?></select></div>
      <div><label>Số lượng sản xuất</label><input class="input" type="number" name="qty" id="qty" min="0.001" step="any" required></div>
      <div><label>Ngày sản xuất</label><input class="input" type="date" name="prod_date" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>"></div>
      <div><label>Nhân công, chi phí chung</label><input class="input" type="number" name="extra_cost" min="0" step="1" value="0"></div>
    <?php else: ?>
      <div style="grid-column:1/-1;"><label>Thành phẩm cần khai báo định mức (tìm theo tên)</label>
        <div style="position:relative;"><input id="fin_search" class="input" placeholder="Tìm thành phẩm..." autocomplete="off"><input type="hidden" name="product_id" id="fin_id" value="<?= $editPid ?: '' ?>">
          <div id="fin_results" style="display:none;position:absolute;z-index:10;width:100%;background:#fff;border:1px solid #e2e8f0;border-radius:6px;max-height:240px;overflow:auto;"></div></div>
        <div id="fin_name" style="margin-top:4px;font-weight:600;"></div></div>
    <?php endif; ?>
  </div>
  <div style="position:relative;margin-bottom:10px;">
    <input type="text" id="search-input" class="input" placeholder="Thêm nguyên liệu: tìm theo tên, SKU hoặc mã vạch..." autocomplete="off">
    <div id="search-results" style="display:none;position:absolute;z-index:10;margin-top:4px;width:100%;background:#fff;border:1px solid #e2e8f0;border-radius:6px;box-shadow:0 8px 20px -8px rgba(0,0,0,.2);max-height:260px;overflow:auto;"></div>
  </div>
  <div style="overflow-x:auto;"><table>
    <thead><tr><th>Nguyên liệu</th><th style="width:150px;"><?= $tab === 'run' ? 'Số lượng dùng' : 'Số lượng cho 1 đơn vị thành phẩm' ?></th><th style="text-align:right;width:130px;">Giá vốn</th><?php if ($tab === 'run'): ?><th style="text-align:right;width:140px;">Thành tiền</th><?php endif; ?><th style="width:44px;"></th></tr></thead>
    <tbody id="lines-body"></tbody>
  </table></div>
  <?php if ($tab === 'run'): ?>
    <div style="text-align:right;margin:12px 0;">Giá thành ước tính / đơn vị: <b id="unitc" style="font-size:20px;color:#2563eb;">—</b> <span class="muted" id="totc"></span></div>
    <div style="margin-bottom:12px;"><label>Ghi chú</label><input class="input" name="note"></div>
  <?php endif; ?>
  <button class="btn" type="submit"><?= $tab === 'run' ? 'Sản xuất, nhập kho thành phẩm' : 'Lưu định mức' ?></button>
</form>

<?php if ($tab === 'recipe' && $finished): ?>
<div class="card" style="max-width:900px;margin-top:16px;"><b>Thành phẩm đã có định mức</b>
  <?php foreach ($finished as $f): ?><div style="padding:4px 0;"><?= e($f['name']) ?> <a href="san_xuat.php?tab=recipe&amp;pid=<?= (int) $f['id'] ?>" style="font-size:13px;">sửa</a></div><?php endforeach; ?>
</div>
<?php endif; ?>

<script>
const MODE = <?= json_encode($tab) ?>;
let lines = [];
const esc = t => String(t ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const fmt = n => new Intl.NumberFormat('vi-VN').format(Math.round(n));
const body = document.getElementById('lines-body');
function search(input, box, onPick) {
  let t = null;
  input.addEventListener('input', () => {
    clearTimeout(t);
    const q = input.value.trim();
    if (!q) { box.style.display = 'none'; return; }
    t = setTimeout(() => fetch('stock_receipt_search.php?q=' + encodeURIComponent(q)).then(r => r.json()).then(d => {
      d = d.filter(p => !p.variant_id);
      if (!d.length) { box.style.display = 'none'; return; }
      box.innerHTML = d.map((p, i) => `<div data-i="${i}" style="padding:8px 12px;font-size:14px;cursor:pointer;">${esc(p.name)} <span class="muted" style="font-size:12px;">${esc(p.sku || '')}</span></div>`).join('');
      box.style.display = 'block';
      box.querySelectorAll('[data-i]').forEach(el => el.addEventListener('click', () => { onPick(d[+el.dataset.i]); input.value = ''; box.style.display = 'none'; }));
    }), 250);
  });
}
function addMat(p, qty) {
  const ex = lines.find(l => l.id === +p.id);
  if (ex) { ex.qty += qty || 1; } else lines.push({ id: +p.id, name: p.name, qty: qty || 1, cost: parseFloat(p.cost_price) || 0 });
  render();
}
function render() {
  body.innerHTML = lines.length ? lines.map((l, i) => `<tr><td>${esc(l.name)}</td>
    <td><input class="input" type="number" min="0.0001" step="any" value="${l.qty}" data-i="${i}"></td>
    <td style="text-align:right;">${fmt(l.cost)}</td>
    ${MODE === 'run' ? `<td style="text-align:right;" data-amt="${i}">${fmt(l.qty * l.cost)}</td>` : ''}
    <td><button type="button" class="btn btn-secondary" style="padding:2px 8px;" data-del="${i}">×</button></td></tr>`).join('')
    : `<tr><td colspan="5" class="muted" style="text-align:center;padding:20px;">Chưa có nguyên liệu</td></tr>`;
  body.querySelectorAll('input').forEach(el => el.addEventListener('input', () => { lines[+el.dataset.i].qty = parseFloat(el.value) || 0; totals(); }));
  body.querySelectorAll('[data-del]').forEach(b => b.addEventListener('click', () => { lines.splice(+b.dataset.del, 1); render(); }));
  totals();
}
function totals() {
  if (MODE !== 'run') return;
  const extra = parseFloat(document.querySelector('[name=extra_cost]').value) || 0, q = parseFloat(document.getElementById('qty').value) || 0;
  const mat = lines.reduce((s, l) => s + l.qty * l.cost, 0);
  lines.forEach((l, i) => { const c = body.querySelector(`[data-amt="${i}"]`); if (c) c.textContent = fmt(l.qty * l.cost); });
  document.getElementById('unitc').textContent = q > 0 ? fmt((mat + extra) / q) : '—';
  document.getElementById('totc').textContent = '(tổng ' + fmt(mat + extra) + ')';
}
search(document.getElementById('search-input'), document.getElementById('search-results'), p => addMat(p, 1));
document.getElementById('sf').addEventListener('submit', () => { document.getElementById('lines_json').value = JSON.stringify(lines.map(l => ({ id: l.id, qty: l.qty }))); });

if (MODE === 'run') {
  const fin = document.getElementById('fin'), qty = document.getElementById('qty');
  let base = [];
  const refill = () => { lines = base.map(r => ({ id: +r.material_id, name: r.name, qty: r.qty_per_unit * (parseFloat(qty.value) || 1), cost: parseFloat(r.cost_price) || 0 })); render(); };
  fin.addEventListener('change', () => fetch('san_xuat.php?a=recipe&pid=' + fin.value).then(r => r.json()).then(d => { base = d; refill(); }));
  qty.addEventListener('input', () => { if (base.length) refill(); else totals(); });
  document.querySelector('[name=extra_cost]').addEventListener('input', totals);
} else {
  const s = document.getElementById('fin_search'), box = document.getElementById('fin_results'), idEl = document.getElementById('fin_id'), nm = document.getElementById('fin_name');
  search(s, box, p => { idEl.value = p.id; nm.textContent = p.name; loadRecipe(p.id); });
  function loadRecipe(id) { fetch('san_xuat.php?a=recipe&pid=' + id).then(r => r.json()).then(d => { lines = d.map(r => ({ id: +r.material_id, name: r.name, qty: parseFloat(r.qty_per_unit), cost: parseFloat(r.cost_price) || 0 })); render(); }); }
  if (idEl.value) { nm.textContent = 'Đang sửa định mức của sản phẩm #' + idEl.value; loadRecipe(idEl.value); }
}
render();
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/inc_footer.php'; ?>
