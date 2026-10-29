<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireRole('ADMIN', 'MANAGER');

$pdo = db();
$tenantId = currentTenantId();
$suppliersStmt = $pdo->prepare('SELECT * FROM suppliers WHERE tenant_id = ? ORDER BY name');
$suppliersStmt->execute([$tenantId]);
$suppliers = $suppliersStmt->fetchAll();
$staffStmt = $pdo->prepare('SELECT id, name FROM users WHERE is_active = 1 AND tenant_id = ? ORDER BY name');
$staffStmt->execute([$tenantId]);
$staffList = $staffStmt->fetchAll();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $branchId = (int) ($currentUser['branch_id'] ?? 0);
    $supplierId = (int) ($_POST['supplier_id'] ?? 0) ?: null;
    if ($supplierId && !in_array($supplierId, array_map('intval', array_column($suppliers, 'id')), true)) {
        $supplierId = null;
    }
    $note = post('note') ?: null;
    $expectedDeliveryDate = post('expected_delivery_date') ?: null;
    $referenceNo = post('reference_no') ?: null;
    $assignedStaffId = (int) ($_POST['assigned_staff_id'] ?? 0) ?: null;
    if ($assignedStaffId && !in_array($assignedStaffId, array_map('intval', array_column($staffList, 'id')), true)) {
        $assignedStaffId = null;
    }
    $productIds = $_POST['product_id'] ?? [];
    $variantIds = $_POST['variant_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $costPrices = $_POST['cost_price'] ?? [];

    if (!$branchId) {
        $error = 'Tài khoản chưa được gán chi nhánh';
    } else {
        $lines = [];
        foreach ($productIds as $i => $pid) {
            $pid = (int) $pid;
            $vid = (int) ($variantIds[$i] ?? 0) ?: null;
            $qty = round((float) ($quantities[$i] ?? 0), 3);
            $cost = (float) ($costPrices[$i] ?? 0);
            if ($pid > 0 && $qty > 0) {
                $ownCheck = $pdo->prepare('SELECT id FROM products WHERE id = ? AND tenant_id = ?');
                $ownCheck->execute([$pid, $tenantId]);
                if ($ownCheck->fetch()) {
                    $lines[] = [$pid, $vid, $qty, $cost];
                }
            }
        }

        if (!$lines) {
            $error = 'Vui lòng thêm ít nhất 1 sản phẩm';
        } else {
            $code = 'DHN' . substr((string) (int) round(microtime(true) * 1000), -8);
            $pdo->prepare(
                'INSERT INTO purchase_orders (code, supplier_id, branch_id, created_by_id, assigned_staff_id, expected_delivery_date, reference_no, note) VALUES (?,?,?,?,?,?,?,?)'
            )->execute([$code, $supplierId, $branchId, $currentUser['id'], $assignedStaffId, $expectedDeliveryDate, $referenceNo, $note]);
            $poId = (int) $pdo->lastInsertId();

            $itemStmt = $pdo->prepare('INSERT INTO purchase_order_items (po_id, product_id, variant_id, quantity, cost_price) VALUES (?,?,?,?,?)');
            foreach ($lines as [$pid, $vid, $qty, $cost]) {
                $itemStmt->execute([$poId, $pid, $vid, $qty, $cost]);
            }

            redirect('purchase_order_view.php?id=' . $poId);
        }
    }
}

require_once __DIR__ . '/inc_header.php';
?>

<a href="purchase_orders.php" class="muted" style="font-size:14px;">← Danh sách đặt hàng nhập</a>
<h1 style="font-size:24px;font-weight:600;margin:8px 0 24px;">Tạo đặt hàng nhập</h1>

<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<form method="post" id="po-form">
  <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">

  <div class="card" style="max-width:640px;margin-bottom:16px;">
    <div class="field">
      <label>Nhà cung cấp</label>
      <div style="display:flex;gap:8px;">
        <select class="input" name="supplier_id" style="flex:1;">
          <option value="">— Không chọn —</option>
          <?php foreach ($suppliers as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?>
        </select>
        <button type="button" id="sup-add-btn" class="btn btn-secondary" style="flex-shrink:0;">➕ Thêm NCC</button>
      </div>
      <div id="sup-add-box" style="display:none;margin-top:8px;padding:10px;border:1px solid #e2e8f0;border-radius:8px;">
        <div id="sup-error" class="alert alert-error" style="display:none;margin-bottom:8px;"></div>
        <div class="grid-2">
          <div class="field"><label>Tên nhà cung cấp *</label><input type="text" id="sup-name" class="input"></div>
          <div class="field"><label>Điện thoại</label><input type="text" id="sup-phone" class="input"></div>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;">
          <button type="button" id="sup-cancel" class="btn btn-secondary">Hủy</button>
          <button type="button" id="sup-save" class="btn">Tạo và chọn NCC này</button>
        </div>
      </div>
    </div>
    <div class="grid-2">
      <div class="field">
        <label>Nhân viên phụ trách</label>
        <select class="input" name="assigned_staff_id">
          <option value="">— Không chọn —</option>
          <?php foreach ($staffList as $s): ?><option value="<?= (int) $s['id'] ?>" <?= (int) ($currentUser['id'] ?? 0) === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Ngày hẹn giao</label><input class="input" type="date" name="expected_delivery_date"></div>
    </div>
    <div class="field"><label>Tham chiếu</label><input class="input" name="reference_no"></div>
    <div class="field"><label>Ghi chú</label><input class="input" name="note"></div>
  </div>

  <div style="margin-bottom:12px;max-width:640px;display:flex;gap:8px;">
    <div style="position:relative;flex:1;">
      <input type="text" id="search-input" class="input" placeholder="Tìm sản phẩm để đặt hàng...">
      <div id="search-results" style="position:absolute;z-index:10;margin-top:4px;width:100%;background:#fff;border:1px solid #e2e8f0;border-radius:6px;box-shadow:0 4px 12px rgba(0,0,0,.08);max-height:280px;overflow-y:auto;display:none;"></div>
    </div>
    <button type="button" id="new-prod-btn" class="btn btn-secondary" style="flex-shrink:0;">➕ Hàng mới</button>
  </div>
  <div id="qa-box" class="card" style="display:none;max-width:640px;margin-bottom:12px;">
    <p class="muted" style="font-size:12px;margin:0 0 10px;">Hàng chưa có trong danh mục: tạo nhanh rồi thêm luôn vào phiếu; khai báo đầy đủ (danh mục, thương hiệu...) thì vào Sản phẩm sau.</p>
    <div id="qa-error" class="alert alert-error" style="display:none;margin-bottom:8px;"></div>
    <div class="grid-2">
      <div class="field"><label>Tên hàng *</label><input type="text" id="qa-name" class="input"></div>
      <div class="field"><label>Mã SKU (bỏ trống để tự sinh)</label><input type="text" id="qa-sku" class="input"></div>
    </div>
    <div class="grid-2">
      <div class="field"><label>Mã vạch (nếu có)</label><input type="text" id="qa-barcode" class="input"></div>
      <div class="field"><label>Đơn vị tính</label><input type="text" id="qa-unit" class="input" placeholder="cái, hộp..."></div>
    </div>
    <div class="grid-2">
      <div class="field"><label>Giá nhập</label><input type="number" id="qa-cost" class="input" min="0" value="0"></div>
      <div class="field"><label>Giá bán</label><input type="number" id="qa-sell" class="input" min="0" value="0"></div>
    </div>
    <div style="display:flex;gap:8px;justify-content:flex-end;">
      <button type="button" id="qa-cancel" class="btn btn-secondary">Hủy</button>
      <button type="button" id="qa-save" class="btn">Tạo và thêm vào phiếu</button>
    </div>
  </div>

  <div class="card" style="padding:0;overflow-x:auto;max-width:800px;margin-bottom:16px;">
    <table>
      <thead><tr><th>Sản phẩm</th><th class="text-right">SL đặt</th><th class="text-right">Giá dự kiến</th><th class="text-right">Thành tiền</th><th></th></tr></thead>
      <tbody id="lines-body">
        <tr id="empty-row"><td colspan="5" class="text-center muted" style="padding:24px;">Chưa có sản phẩm nào</td></tr>
      </tbody>
    </table>
  </div>

  <div style="max-width:800px;display:flex;justify-content:flex-end;align-items:center;gap:16px;">
    <div>Tổng tiền dự kiến: <b id="grand-total" style="font-size:18px;color:#2563eb;">0</b></div>
    <button type="submit" class="btn">Lưu đặt hàng nhập</button>
  </div>
</form>

<script>
let lines = [];
const searchInput = document.getElementById('search-input');
const searchResults = document.getElementById('search-results');
let timer = null;

searchInput.addEventListener('input', () => {
  clearTimeout(timer);
  const q = searchInput.value.trim();
  if (!q) { searchResults.style.display = 'none'; return; }
  timer = setTimeout(() => {
    fetch('purchase_order_search.php?q=' + encodeURIComponent(q))
      .then(r => r.json())
      .then(data => {
        if (!data.length) { searchResults.style.display = 'none'; return; }
        searchResults.innerHTML = data.map((p, i) => `
          <div class="s-item" data-i="${i}" style="padding:8px 12px;font-size:14px;cursor:pointer;">${esc(p.name)} <span class="muted" style="font-family:monospace;font-size:12px;">(${esc(p.sku)})</span></div>`).join('');
        searchResults.style.display = 'block';
        searchResults.querySelectorAll('.s-item').forEach(el => {
          el.addEventListener('click', () => {
            const p = data[parseInt(el.dataset.i, 10)];
            addLine(p.id, p.variant_id, p.name, parseFloat(p.cost_price) || 0);
            searchInput.value = '';
            searchResults.style.display = 'none';
          });
        });
      });
  }, 250);
});

function addLine(id, variantId, name, cost) {
  const key = id + ':' + (variantId ?? '');
  const existing = lines.find(l => l.key === key);
  if (existing) { existing.qty += 1; } else { lines.push({ key, id, variantId, name, qty: 1, cost }); }
  render();
}

function render() {
  const body = document.getElementById('lines-body');
  if (!lines.length) {
    body.innerHTML = '<tr id="empty-row"><td colspan="5" class="text-center muted" style="padding:24px;">Chưa có sản phẩm nào</td></tr>';
  } else {
    body.innerHTML = lines.map((l, i) => `
      <tr>
        <td>${esc(l.name)}<input type="hidden" name="product_id[]" value="${l.id}"><input type="hidden" name="variant_id[]" value="${l.variantId ?? ''}"></td>
        <td class="text-right"><input type="number" min="0.001" step="0.001" value="${l.qty}" data-i="${i}" data-f="qty" name="quantity[]" style="width:70px;text-align:right;padding:4px;border:1px solid #cbd5e1;border-radius:6px;"></td>
        <td class="text-right"><input type="number" min="0" value="${l.cost}" data-i="${i}" data-f="cost" name="cost_price[]" style="width:100px;text-align:right;padding:4px;border:1px solid #cbd5e1;border-radius:6px;"></td>
        <td class="text-right">${fmt(l.qty * l.cost)}</td>
        <td class="text-right"><a href="#" data-i="${i}" class="remove" style="color:#ef4444;font-size:12px;">Xóa</a></td>
      </tr>`).join('');
    body.querySelectorAll('input[data-f]').forEach(inp => {
      inp.addEventListener('input', () => { lines[parseInt(inp.dataset.i, 10)][inp.dataset.f] = parseFloat(inp.value) || 0; render(); });
    });
    body.querySelectorAll('.remove').forEach(a => {
      a.addEventListener('click', (e) => { e.preventDefault(); lines.splice(parseInt(a.dataset.i, 10), 1); render(); });
    });
  }
  document.getElementById('grand-total').textContent = fmt(lines.reduce((s, l) => s + l.qty * l.cost, 0));
}

function fmt(n) { return Math.round(n).toLocaleString('vi-VN'); }
function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

document.addEventListener('click', (e) => {
  if (!e.target.closest('#search-input') && !e.target.closest('#search-results')) searchResults.style.display = 'none';
});

// Tao nhanh hang moi / nha cung cap ngay trong phieu (cung endpoint voi phieu nhap hang).
const csrfVal = <?= json_encode(csrfToken()) ?>;
const qaBox = document.getElementById('qa-box');
function postForm(url, fields, errBox, btn, done) {
  const fd = new FormData();
  fd.append('csrf', csrfVal);
  Object.keys(fields).forEach(k => fd.append(k, fields[k]));
  btn.disabled = true;
  fetch(url, { method: 'POST', body: fd })
    .then(r => r.json().then(d => ({ ok: r.ok, d })))
    .then(({ ok, d }) => {
      btn.disabled = false;
      if (!ok) { errBox.textContent = d.error || 'Không tạo được.'; errBox.style.display = 'block'; return; }
      errBox.style.display = 'none';
      done(d);
    })
    .catch(() => { btn.disabled = false; errBox.textContent = 'Lỗi kết nối, vui lòng thử lại.'; errBox.style.display = 'block'; });
}
document.getElementById('new-prod-btn').addEventListener('click', () => {
  const q = searchInput.value.trim();
  const isCode = /^[0-9]{8,14}$/.test(q);
  document.getElementById('qa-name').value = isCode ? '' : q;
  document.getElementById('qa-barcode').value = isCode ? q : '';
  searchResults.style.display = 'none';
  qaBox.style.display = 'block';
  document.getElementById('qa-name').focus();
});
document.getElementById('qa-cancel').addEventListener('click', () => { qaBox.style.display = 'none'; });
document.getElementById('qa-save').addEventListener('click', () => {
  postForm('product_quick_add.php', {
    name: document.getElementById('qa-name').value, sku: document.getElementById('qa-sku').value,
    barcode: document.getElementById('qa-barcode').value, unit: document.getElementById('qa-unit').value,
    cost_price: document.getElementById('qa-cost').value, sell_price: document.getElementById('qa-sell').value,
  }, document.getElementById('qa-error'), document.getElementById('qa-save'), d => {
    addLine(d.id, d.variant_id, d.name, parseFloat(d.cost_price) || 0);
    ['qa-name', 'qa-sku', 'qa-barcode', 'qa-unit'].forEach(id => { document.getElementById(id).value = ''; });
    document.getElementById('qa-cost').value = 0; document.getElementById('qa-sell').value = 0;
    searchInput.value = '';
    qaBox.style.display = 'none';
  });
});

const supSel = document.querySelector('select[name="supplier_id"]');
const supBox = document.getElementById('sup-add-box');
document.getElementById('sup-add-btn').addEventListener('click', () => {
  supBox.style.display = supBox.style.display === 'none' ? 'block' : 'none';
  document.getElementById('sup-name').focus();
});
document.getElementById('sup-cancel').addEventListener('click', () => { supBox.style.display = 'none'; });
document.getElementById('sup-save').addEventListener('click', () => {
  postForm('supplier_quick_add.php', {
    name: document.getElementById('sup-name').value, phone: document.getElementById('sup-phone').value,
  }, document.getElementById('sup-error'), document.getElementById('sup-save'), d => {
    let opt = Array.from(supSel.options).find(o => parseInt(o.value, 10) === d.id);
    if (!opt) { opt = new Option(d.name, d.id); supSel.add(opt); }
    supSel.value = String(d.id);
    document.getElementById('sup-name').value = ''; document.getElementById('sup-phone').value = '';
    supBox.style.display = 'none';
  });
});
</script>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
