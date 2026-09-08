<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireLogin();

$pdo = db();
$suppliers = $pdo->query('SELECT * FROM suppliers ORDER BY name')->fetchAll();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $branchId = (int) ($currentUser['branch_id'] ?? 0);
    $supplierId = (int) ($_POST['supplier_id'] ?? 0) ?: null;
    $note = post('note') ?: null;
    $productIds = $_POST['product_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $costPrices = $_POST['cost_price'] ?? [];

    if (!$branchId) {
        $error = 'Tài khoản chưa được gán chi nhánh';
    } else {
        $lines = [];
        foreach ($productIds as $i => $pid) {
            $pid = (int) $pid;
            $qty = (int) ($quantities[$i] ?? 0);
            $cost = (float) ($costPrices[$i] ?? 0);
            if ($pid > 0 && $qty > 0 && $cost >= 0) {
                $lines[] = [$pid, $qty, $cost];
            }
        }

        if (!$lines) {
            $error = 'Vui lòng thêm ít nhất 1 sản phẩm với số lượng hợp lệ';
        } else {
            $pdo->beginTransaction();
            try {
                $total = 0.0;
                foreach ($lines as [$pid, $qty, $cost]) {
                    $total += $qty * $cost;
                }

                $code = 'PN' . substr((string) (int) round(microtime(true) * 1000), -8);
                $pdo->prepare(
                    'INSERT INTO stock_receipts (code, branch_id, supplier_id, created_by_id, total_amount, note) VALUES (?,?,?,?,?,?)'
                )->execute([$code, $branchId, $supplierId, $currentUser['id'], $total, $note]);
                $receiptId = (int) $pdo->lastInsertId();

                $itemStmt = $pdo->prepare(
                    'INSERT INTO stock_receipt_items (receipt_id, product_id, quantity, cost_price) VALUES (?,?,?,?)'
                );
                foreach ($lines as [$pid, $qty, $cost]) {
                    $itemStmt->execute([$receiptId, $pid, $qty, $cost]);

                    $inv = $pdo->prepare('SELECT id FROM inventory WHERE branch_id = ? AND product_id = ?');
                    $inv->execute([$branchId, $pid]);
                    $invRow = $inv->fetch();
                    if ($invRow) {
                        $pdo->prepare('UPDATE inventory SET quantity = quantity + ? WHERE id = ?')
                            ->execute([$qty, $invRow['id']]);
                    } else {
                        $pdo->prepare('INSERT INTO inventory (branch_id, product_id, quantity) VALUES (?,?,?)')
                            ->execute([$branchId, $pid, $qty]);
                    }

                    // Cập nhật giá vốn mới nhất cho sản phẩm
                    $pdo->prepare('UPDATE products SET cost_price = ? WHERE id = ?')->execute([$cost, $pid]);
                }

                if ($supplierId) {
                    $pdo->prepare('UPDATE suppliers SET debt = debt + ? WHERE id = ?')->execute([$total, $supplierId]);
                }

                $pdo->commit();
                redirect('stock_receipt_view.php?id=' . $receiptId);
            } catch (Throwable $ex) {
                $pdo->rollBack();
                $error = 'Không thể tạo phiếu nhập, vui lòng thử lại';
            }
        }
    }
}

require_once __DIR__ . '/inc_header.php';
?>

<a href="stock_receipts.php" class="muted" style="font-size:14px;">← Danh sách phiếu nhập</a>
<h1 style="font-size:24px;font-weight:600;margin:8px 0 24px;">Tạo phiếu nhập hàng</h1>

<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<form method="post" id="receipt-form">
  <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">

  <div class="card" style="max-width:640px;margin-bottom:16px;">
    <div class="field">
      <label>Nhà cung cấp</label>
      <select class="input" name="supplier_id">
        <option value="">— Không chọn —</option>
        <?php foreach ($suppliers as $s): ?>
          <option value="<?= (int) $s['id'] ?>"><?= e($s['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label>Ghi chú</label>
      <input class="input" name="note">
    </div>
  </div>

  <div style="position:relative;margin-bottom:12px;max-width:640px;">
    <input type="text" id="search-input" class="input" placeholder="Tìm sản phẩm theo tên hoặc SKU để thêm vào phiếu nhập...">
    <div id="search-results" style="position:absolute;z-index:10;margin-top:4px;width:100%;background:#fff;border:1px solid #e2e8f0;border-radius:6px;box-shadow:0 4px 12px rgba(0,0,0,.08);max-height:280px;overflow-y:auto;display:none;"></div>
  </div>

  <div class="card" style="padding:0;overflow-x:auto;max-width:800px;margin-bottom:16px;">
    <table>
      <thead><tr><th>Sản phẩm</th><th class="text-right">SL</th><th class="text-right">Giá vốn</th><th class="text-right">Thành tiền</th><th></th></tr></thead>
      <tbody id="lines-body">
        <tr id="empty-row"><td colspan="5" class="text-center muted" style="padding:24px;">Chưa có sản phẩm nào</td></tr>
      </tbody>
    </table>
  </div>

  <div style="max-width:800px;display:flex;justify-content:flex-end;align-items:center;gap:16px;">
    <div>Tổng tiền: <b id="grand-total" style="font-size:18px;color:#2563eb;">0</b></div>
    <button type="submit" class="btn">Lưu phiếu nhập</button>
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
    fetch('stock_receipt_search.php?q=' + encodeURIComponent(q))
      .then(r => r.json())
      .then(data => {
        if (!data.length) { searchResults.style.display = 'none'; return; }
        searchResults.innerHTML = data.map(p => `
          <div class="s-item" data-id="${p.id}" data-name="${esc(p.name)}" data-cost="${p.cost_price}"
               style="padding:8px 12px;font-size:14px;cursor:pointer;">${esc(p.name)} <span class="muted" style="font-family:monospace;font-size:12px;">(${esc(p.sku)})</span></div>`).join('');
        searchResults.style.display = 'block';
        searchResults.querySelectorAll('.s-item').forEach(el => {
          el.addEventListener('click', () => {
            addLine(el.dataset.id, el.dataset.name, parseFloat(el.dataset.cost) || 0);
            searchInput.value = '';
            searchResults.style.display = 'none';
          });
        });
      });
  }, 250);
});

function addLine(id, name, cost) {
  const existing = lines.find(l => l.id === id);
  if (existing) { existing.qty += 1; } else { lines.push({ id, name, qty: 1, cost }); }
  render();
}

function render() {
  const body = document.getElementById('lines-body');
  if (!lines.length) {
    body.innerHTML = '<tr id="empty-row"><td colspan="5" class="text-center muted" style="padding:24px;">Chưa có sản phẩm nào</td></tr>';
  } else {
    body.innerHTML = lines.map((l, i) => `
      <tr>
        <td>${esc(l.name)}<input type="hidden" name="product_id[]" value="${l.id}"></td>
        <td class="text-right"><input type="number" min="1" value="${l.qty}" data-i="${i}" data-f="qty" name="quantity[]" style="width:70px;text-align:right;padding:4px;border:1px solid #cbd5e1;border-radius:6px;"></td>
        <td class="text-right"><input type="number" min="0" value="${l.cost}" data-i="${i}" data-f="cost" name="cost_price[]" style="width:100px;text-align:right;padding:4px;border:1px solid #cbd5e1;border-radius:6px;"></td>
        <td class="text-right">${fmt(l.qty * l.cost)}</td>
        <td class="text-right"><a href="#" data-i="${i}" class="remove" style="color:#ef4444;font-size:12px;">Xóa</a></td>
      </tr>`).join('');
    body.querySelectorAll('input[data-f]').forEach(inp => {
      inp.addEventListener('input', () => {
        const i = parseInt(inp.dataset.i, 10);
        const f = inp.dataset.f;
        lines[i][f] = parseFloat(inp.value) || 0;
        render();
      });
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
  if (!e.target.closest('#search-input') && !e.target.closest('#search-results')) {
    searchResults.style.display = 'none';
  }
});
</script>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
