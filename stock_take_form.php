<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireRole('ADMIN', 'MANAGER');

$pdo = db();
$branches = $pdo->query('SELECT * FROM branches WHERE is_active = 1 ORDER BY name')->fetchAll();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $branchId = (int) ($_POST['branch_id'] ?? 0);
    $note = post('note') ?: null;
    $productIds = $_POST['product_id'] ?? [];
    $variantIds = $_POST['variant_id'] ?? [];
    $systemQtys = $_POST['system_qty'] ?? [];
    $countedQtys = $_POST['counted_qty'] ?? [];

    if (!$branchId) {
        $error = 'Vui lòng chọn chi nhánh';
    } else {
        $lines = [];
        foreach ($productIds as $i => $pid) {
            $pid = (int) $pid;
            $vid = (int) ($variantIds[$i] ?? 0) ?: null;
            $sys = round((float) ($systemQtys[$i] ?? 0), 3);
            $counted = round((float) ($countedQtys[$i] ?? 0), 3);
            if ($pid > 0) {
                $lines[] = [$pid, $vid, $sys, $counted];
            }
        }

        if (!$lines) {
            $error = 'Vui lòng thêm ít nhất 1 sản phẩm để kiểm';
        } else {
            $pdo->beginTransaction();
            try {
                $code = 'KH' . substr((string) (int) round(microtime(true) * 1000), -8);
                $pdo->prepare("INSERT INTO stock_takes (code, branch_id, created_by_id, note, status) VALUES (?,?,?,?,'DRAFT')")
                    ->execute([$code, $branchId, $currentUser['id'], $note]);
                $takeId = (int) $pdo->lastInsertId();

                // Chỉ ghi lại số đếm thực tế ở dạng nháp — CHƯA cập nhật tồn kho hệ thống, chờ
                // nhân viên có quyền bấm "Cân bằng kho" xác nhận mới áp dụng (xem stock_take_balance.php).
                $itemStmt = $pdo->prepare(
                    'INSERT INTO stock_take_items (take_id, product_id, variant_id, system_qty, counted_qty) VALUES (?,?,?,?,?)'
                );
                foreach ($lines as [$pid, $vid, $sys, $counted]) {
                    $itemStmt->execute([$takeId, $pid, $vid, $sys, $counted]);
                }

                $pdo->commit();
                redirect('stock_take_view.php?id=' . $takeId);
            } catch (Throwable $ex) {
                $pdo->rollBack();
                $error = 'Không thể tạo phiếu kiểm hàng, vui lòng thử lại';
            }
        }
    }
}

require_once __DIR__ . '/inc_header.php';
?>

<a href="stock_takes.php" class="muted" style="font-size:14px;">← Danh sách phiếu kiểm hàng</a>
<h1 style="font-size:24px;font-weight:600;margin:8px 0 24px;">Tạo phiếu kiểm hàng</h1>

<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<form method="post" id="take-form">
  <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">

  <div class="card" style="max-width:640px;margin-bottom:16px;">
    <div class="field">
      <label>Chi nhánh *</label>
      <select class="input" name="branch_id" id="branch-select" required>
        <option value="">— Chọn chi nhánh —</option>
        <?php foreach ($branches as $b): ?>
          <option value="<?= (int) $b['id'] ?>" <?= (int) ($currentUser['branch_id'] ?? 0) === (int) $b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Ghi chú</label><input class="input" name="note"></div>
  </div>

  <div style="position:relative;margin-bottom:12px;max-width:640px;">
    <input type="text" id="search-input" class="input" placeholder="Tìm sản phẩm để kiểm...">
    <div id="search-results" style="position:absolute;z-index:10;margin-top:4px;width:100%;background:#fff;border:1px solid #e2e8f0;border-radius:6px;box-shadow:0 4px 12px rgba(0,0,0,.08);max-height:280px;overflow-y:auto;display:none;"></div>
  </div>

  <div class="card" style="padding:0;overflow-x:auto;max-width:800px;margin-bottom:16px;">
    <table>
      <thead><tr><th>Sản phẩm</th><th class="text-right">Tồn hệ thống</th><th class="text-right">SL thực tế</th><th class="text-right">Chênh lệch</th><th></th></tr></thead>
      <tbody id="lines-body">
        <tr id="empty-row"><td colspan="5" class="text-center muted" style="padding:24px;">Chưa có sản phẩm nào</td></tr>
      </tbody>
    </table>
  </div>

  <button type="submit" class="btn">Lưu phiếu kiểm hàng</button>
</form>

<script>
let lines = [];
const searchInput = document.getElementById('search-input');
const searchResults = document.getElementById('search-results');
const branchSelect = document.getElementById('branch-select');
let timer = null;

searchInput.addEventListener('input', () => {
  clearTimeout(timer);
  const q = searchInput.value.trim();
  const branchId = branchSelect.value;
  if (!q || !branchId) { searchResults.style.display = 'none'; return; }
  timer = setTimeout(() => {
    fetch('stock_take_search.php?q=' + encodeURIComponent(q) + '&branch_id=' + branchId)
      .then(r => r.json())
      .then(data => {
        if (!data.length) { searchResults.style.display = 'none'; return; }
        searchResults.innerHTML = data.map((p, i) => `
          <div class="s-item" data-i="${i}" style="padding:8px 12px;font-size:14px;cursor:pointer;">${esc(p.name)} <span class="muted" style="font-family:monospace;font-size:12px;">(${esc(p.sku)})</span> · Tồn: ${fmtQty(p.qty)}</div>`).join('');
        searchResults.style.display = 'block';
        searchResults.querySelectorAll('.s-item').forEach(el => {
          el.addEventListener('click', () => {
            const p = data[parseInt(el.dataset.i, 10)];
            addLine(p.id, p.variant_id, p.name, parseFloat(p.qty) || 0);
            searchInput.value = '';
            searchResults.style.display = 'none';
          });
        });
      });
  }, 250);
});

function addLine(id, variantId, name, systemQty) {
  const key = id + ':' + (variantId ?? '');
  if (lines.find(l => l.key === key)) return;
  lines.push({ key, id, variantId, name, systemQty, counted: systemQty });
  render();
}

function render() {
  const body = document.getElementById('lines-body');
  if (!lines.length) {
    body.innerHTML = '<tr id="empty-row"><td colspan="5" class="text-center muted" style="padding:24px;">Chưa có sản phẩm nào</td></tr>';
  } else {
    body.innerHTML = lines.map((l, i) => {
      const diff = l.counted - l.systemQty;
      const diffColor = diff === 0 ? '' : (diff > 0 ? 'color:#059669;' : 'color:#dc2626;');
      return `
      <tr>
        <td>${esc(l.name)}<input type="hidden" name="product_id[]" value="${l.id}"><input type="hidden" name="variant_id[]" value="${l.variantId ?? ''}"><input type="hidden" name="system_qty[]" value="${l.systemQty}"></td>
        <td class="text-right muted">${fmtQty(l.systemQty)}</td>
        <td class="text-right"><input type="number" min="0" step="0.001" value="${l.counted}" data-i="${i}" name="counted_qty[]" style="width:80px;text-align:right;padding:4px;border:1px solid #cbd5e1;border-radius:6px;"></td>
        <td class="text-right" style="${diffColor}">${diff > 0 ? '+' : ''}${fmtQty(diff)}</td>
        <td class="text-right"><a href="#" data-i="${i}" class="remove" style="color:#ef4444;font-size:12px;">Xóa</a></td>
      </tr>`;
    }).join('');
    body.querySelectorAll('input[name="counted_qty[]"]').forEach(inp => {
      inp.addEventListener('input', () => {
        lines[parseInt(inp.dataset.i, 10)].counted = Math.round((parseFloat(inp.value) || 0) * 1000) / 1000;
        render();
      });
    });
    body.querySelectorAll('.remove').forEach(a => {
      a.addEventListener('click', (e) => { e.preventDefault(); lines.splice(parseInt(a.dataset.i, 10), 1); render(); });
    });
  }
}

function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
function fmtQty(n) { n = parseFloat(n) || 0; return n % 1 === 0 ? String(n) : String(Math.round(n * 1000) / 1000); }

document.addEventListener('click', (e) => {
  if (!e.target.closest('#search-input') && !e.target.closest('#search-results')) {
    searchResults.style.display = 'none';
  }
});
</script>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
