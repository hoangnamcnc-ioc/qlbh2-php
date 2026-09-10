<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireRole('ADMIN', 'MANAGER');

$pdo = db();
$branches = $pdo->query('SELECT * FROM branches ORDER BY name')->fetchAll();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $fromBranchId = (int) ($_POST['from_branch_id'] ?? 0);
    $toBranchId = (int) ($_POST['to_branch_id'] ?? 0);
    $note = post('note') ?: null;
    $productIds = $_POST['product_id'] ?? [];
    $variantIds = $_POST['variant_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];

    if (!$fromBranchId || !$toBranchId) {
        $error = 'Vui lòng chọn chi nhánh chuyển và chi nhánh nhận';
    } elseif ($fromBranchId === $toBranchId) {
        $error = 'Chi nhánh chuyển và chi nhánh nhận phải khác nhau';
    } else {
        $lines = [];
        foreach ($productIds as $i => $pid) {
            $pid = (int) $pid;
            $vid = (int) ($variantIds[$i] ?? 0) ?: null;
            $qty = (int) ($quantities[$i] ?? 0);
            if ($pid > 0 && $qty > 0) {
                $lines[] = [$pid, $vid, $qty];
            }
        }

        if (!$lines) {
            $error = 'Vui lòng thêm ít nhất 1 sản phẩm với số lượng hợp lệ';
        } else {
            $pdo->beginTransaction();
            try {
                foreach ($lines as [$pid, $vid, $qty]) {
                    if ($vid) {
                        $stmt = $pdo->prepare('SELECT * FROM inventory WHERE branch_id = ? AND variant_id = ? FOR UPDATE');
                        $stmt->execute([$fromBranchId, $vid]);
                    } else {
                        $stmt = $pdo->prepare('SELECT * FROM inventory WHERE branch_id = ? AND product_id = ? AND variant_id IS NULL FOR UPDATE');
                        $stmt->execute([$fromBranchId, $pid]);
                    }
                    $inv = $stmt->fetch();
                    if (!$inv || (int) $inv['quantity'] < $qty) {
                        $prod = $pdo->prepare('SELECT name FROM products WHERE id = ?');
                        $prod->execute([$pid]);
                        throw new RuntimeException('Không đủ tồn kho để chuyển: ' . ($prod->fetchColumn() ?: "#$pid"));
                    }
                }

                $code = 'CH' . substr((string) (int) round(microtime(true) * 1000), -8);
                $pdo->prepare("INSERT INTO stock_transfers (code, from_branch_id, to_branch_id, created_by_id, note, status) VALUES (?,?,?,?,?,'IN_TRANSIT')")
                    ->execute([$code, $fromBranchId, $toBranchId, $currentUser['id'], $note]);
                $transferId = (int) $pdo->lastInsertId();

                $itemStmt = $pdo->prepare(
                    'INSERT INTO stock_transfer_items (transfer_id, product_id, variant_id, quantity) VALUES (?,?,?,?)'
                );
                foreach ($lines as [$pid, $vid, $qty]) {
                    $itemStmt->execute([$transferId, $pid, $vid, $qty]);

                    // Trừ kho chi nhánh chuyển ngay (hàng đang trên đường đi, chưa cộng vào chi
                    // nhánh nhận — chỉ cộng khi chi nhánh nhận xác nhận đã nhận hàng, xem
                    // stock_transfer_receive.php).
                    if ($vid) {
                        $pdo->prepare('UPDATE inventory SET quantity = quantity - ? WHERE branch_id = ? AND variant_id = ?')
                            ->execute([$qty, $fromBranchId, $vid]);
                    } else {
                        $pdo->prepare('UPDATE inventory SET quantity = quantity - ? WHERE branch_id = ? AND product_id = ? AND variant_id IS NULL')
                            ->execute([$qty, $fromBranchId, $pid]);
                    }
                }

                $pdo->commit();
                redirect('stock_transfer_view.php?id=' . $transferId);
            } catch (RuntimeException $ex) {
                $pdo->rollBack();
                $error = $ex->getMessage();
            } catch (Throwable $ex) {
                $pdo->rollBack();
                $error = 'Không thể tạo phiếu chuyển hàng, vui lòng thử lại';
            }
        }
    }
}

require_once __DIR__ . '/inc_header.php';
?>

<a href="stock_transfers.php" class="muted" style="font-size:14px;">← Danh sách phiếu chuyển hàng</a>
<h1 style="font-size:24px;font-weight:600;margin:8px 0 24px;">Tạo phiếu chuyển hàng</h1>

<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
<?php if (count($branches) < 2): ?>
  <div class="alert alert-warning" style="max-width:640px;">
    Cần ít nhất 2 chi nhánh để chuyển hàng. Vào <a href="branches.php">Chi nhánh</a> để thêm.
  </div>
<?php endif; ?>

<form method="post" id="transfer-form">
  <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">

  <div class="card" style="max-width:640px;margin-bottom:16px;">
    <div class="grid-2">
      <div class="field">
        <label>Từ chi nhánh *</label>
        <select class="input" name="from_branch_id" id="from-branch" required>
          <option value="">— Chọn —</option>
          <?php foreach ($branches as $b): ?><option value="<?= (int) $b['id'] ?>"><?= e($b['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>Đến chi nhánh *</label>
        <select class="input" name="to_branch_id" required>
          <option value="">— Chọn —</option>
          <?php foreach ($branches as $b): ?><option value="<?= (int) $b['id'] ?>"><?= e($b['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="field"><label>Ghi chú</label><input class="input" name="note"></div>
  </div>

  <div style="position:relative;margin-bottom:12px;max-width:640px;">
    <input type="text" id="search-input" class="input" placeholder="Tìm sản phẩm để chuyển (chọn chi nhánh chuyển trước)...">
    <div id="search-results" style="position:absolute;z-index:10;margin-top:4px;width:100%;background:#fff;border:1px solid #e2e8f0;border-radius:6px;box-shadow:0 4px 12px rgba(0,0,0,.08);max-height:280px;overflow-y:auto;display:none;"></div>
  </div>

  <div class="card" style="padding:0;overflow-x:auto;max-width:800px;margin-bottom:16px;">
    <table>
      <thead><tr><th>Sản phẩm</th><th class="text-right">Tồn tại chi nhánh chuyển</th><th class="text-right">SL chuyển</th><th></th></tr></thead>
      <tbody id="lines-body">
        <tr id="empty-row"><td colspan="4" class="text-center muted" style="padding:24px;">Chưa có sản phẩm nào</td></tr>
      </tbody>
    </table>
  </div>

  <button type="submit" class="btn">Lưu phiếu chuyển hàng</button>
</form>

<script>
let lines = [];
const searchInput = document.getElementById('search-input');
const searchResults = document.getElementById('search-results');
const fromBranch = document.getElementById('from-branch');
let timer = null;

searchInput.addEventListener('input', () => {
  clearTimeout(timer);
  const q = searchInput.value.trim();
  const branchId = fromBranch.value;
  if (!q || !branchId) { searchResults.style.display = 'none'; return; }
  timer = setTimeout(() => {
    fetch('stock_transfer_search.php?q=' + encodeURIComponent(q) + '&branch_id=' + branchId)
      .then(r => r.json())
      .then(data => {
        if (!data.length) { searchResults.style.display = 'none'; return; }
        searchResults.innerHTML = data.map((p, i) => `
          <div class="s-item" data-i="${i}" style="padding:8px 12px;font-size:14px;cursor:pointer;">${esc(p.name)} <span class="muted" style="font-family:monospace;font-size:12px;">(${esc(p.sku)})</span> · Tồn: ${p.qty}</div>`).join('');
        searchResults.style.display = 'block';
        searchResults.querySelectorAll('.s-item').forEach(el => {
          el.addEventListener('click', () => {
            const p = data[parseInt(el.dataset.i, 10)];
            addLine(p.id, p.variant_id, p.name, parseInt(p.qty, 10) || 0);
            searchInput.value = '';
            searchResults.style.display = 'none';
          });
        });
      });
  }, 250);
});

function addLine(id, variantId, name, availQty) {
  const key = id + ':' + (variantId ?? '');
  if (lines.find(l => l.key === key)) return;
  lines.push({ key, id, variantId, name, availQty, qty: 1 });
  render();
}

function render() {
  const body = document.getElementById('lines-body');
  if (!lines.length) {
    body.innerHTML = '<tr id="empty-row"><td colspan="4" class="text-center muted" style="padding:24px;">Chưa có sản phẩm nào</td></tr>';
  } else {
    body.innerHTML = lines.map((l, i) => `
      <tr>
        <td>${esc(l.name)}<input type="hidden" name="product_id[]" value="${l.id}"><input type="hidden" name="variant_id[]" value="${l.variantId ?? ''}"></td>
        <td class="text-right muted">${l.availQty}</td>
        <td class="text-right"><input type="number" min="1" max="${l.availQty}" value="${l.qty}" data-i="${i}" name="quantity[]" style="width:80px;text-align:right;padding:4px;border:1px solid #cbd5e1;border-radius:6px;"></td>
        <td class="text-right"><a href="#" data-i="${i}" class="remove" style="color:#ef4444;font-size:12px;">Xóa</a></td>
      </tr>`).join('');
    body.querySelectorAll('input[name="quantity[]"]').forEach(inp => {
      inp.addEventListener('input', () => { lines[parseInt(inp.dataset.i, 10)].qty = parseInt(inp.value, 10) || 1; });
    });
    body.querySelectorAll('.remove').forEach(a => {
      a.addEventListener('click', (e) => { e.preventDefault(); lines.splice(parseInt(a.dataset.i, 10), 1); render(); });
    });
  }
}

function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

document.addEventListener('click', (e) => {
  if (!e.target.closest('#search-input') && !e.target.closest('#search-results')) {
    searchResults.style.display = 'none';
  }
});

fromBranch.addEventListener('change', () => { lines = []; render(); });
</script>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
