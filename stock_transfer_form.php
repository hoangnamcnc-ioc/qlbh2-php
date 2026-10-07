<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireRole('ADMIN', 'MANAGER');

$pdo = db();
$tenantId = currentTenantId();
$branchesStmt = $pdo->prepare('SELECT * FROM branches WHERE is_active = 1 AND tenant_id = ? ORDER BY name');
$branchesStmt->execute([$tenantId]);
$branches = $branchesStmt->fetchAll();
$branchIds = array_map('intval', array_column($branches, 'id'));
$error = null;

// MANAGER chỉ được tạo phiếu chuyển ĐI từ chi nhánh mình quản lý — tránh tự ý rút hàng từ
// tồn kho của 1 chi nhánh khác mà mình không phụ trách.
$lockedFromBranchId = hasRole('ADMIN') ? 0 : effectiveBranchId($currentUser);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $fromBranchId = (int) ($_POST['from_branch_id'] ?? 0);
    if ($lockedFromBranchId && $fromBranchId !== $lockedFromBranchId) {
        $fromBranchId = $lockedFromBranchId;
    }
    $toBranchId = (int) ($_POST['to_branch_id'] ?? 0);
    // Ca 2 chi nhanh (du la ADMIN) deu phai thuoc tenant hien tai.
    if (!in_array($fromBranchId, $branchIds, true)) $fromBranchId = 0;
    if (!in_array($toBranchId, $branchIds, true)) $toBranchId = 0;
    $note = post('note') ?: null;
    $productIds = $_POST['product_id'] ?? [];
    $variantIds = $_POST['variant_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];

    if (!$fromBranchId || !$toBranchId) {
        $error = 'Vui lòng chọn chi nhánh chuyển và chi nhánh nhận';
    } elseif ($fromBranchId === $toBranchId) {
        $error = 'Chi nhánh chuyển và chi nhánh nhận phải khác nhau';
    } else {
        // Gộp các dòng trùng sản phẩm/biến thể lại thành 1 trước khi kiểm tra tồn kho — tránh
        // trường hợp gửi 2 dòng cùng sản phẩm (vd request POST thủ công) khiến số lượng bị trừ
        // kho 2 lần dù mỗi dòng kiểm tra riêng lẻ đều thấy đủ tồn.
        $merged = [];
        foreach ($productIds as $i => $pid) {
            $pid = (int) $pid;
            $vid = (int) ($variantIds[$i] ?? 0) ?: null;
            $qty = round((float) ($quantities[$i] ?? 0), 3);
            if ($pid > 0 && $qty > 0) {
                // San pham (va bien the) phai thuoc dung cua hang hien tai. Thieu dong nay thi
                // gui product_id cua cua hang khac se dua ten san pham cua ho vao chung tu va
                // vao ton kho chi nhanh minh. stock_receipt_form.php/purchase_order_form.php da
                // kiem tu truoc - hai file nay bi sot.
                if (!laySanPhamCuaToi($pid)) {
                    continue;
                }
                if ($vid) {
                    $ownVariant = $pdo->prepare('SELECT id FROM product_variants WHERE id = ? AND product_id = ? AND tenant_id = ?');
                    $ownVariant->execute([$vid, $pid, $tenantId]);
                    if (!$ownVariant->fetch()) {
                        continue;
                    }
                }
                $key = $pid . ':' . ($vid ?? '');
                $merged[$key] = [$pid, $vid, ($merged[$key][2] ?? 0) + $qty];
            }
        }
        $lines = array_values($merged);

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
                    if (!$inv || (float) $inv['quantity'] < $qty) {
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
        <?php if ($lockedFromBranchId): ?>
          <?php $lockedFromBranch = array_values(array_filter($branches, fn ($b) => (int) $b['id'] === $lockedFromBranchId))[0] ?? null; ?>
          <input type="hidden" name="from_branch_id" id="from-branch" value="<?= $lockedFromBranchId ?>">
          <input class="input" value="<?= e($lockedFromBranch['name'] ?? '') ?>" disabled>
        <?php else: ?>
          <select class="input" name="from_branch_id" id="from-branch" required>
            <option value="">— Chọn —</option>
            <?php foreach ($branches as $b): ?><option value="<?= (int) $b['id'] ?>"><?= e($b['name']) ?></option><?php endforeach; ?>
          </select>
        <?php endif; ?>
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

  <div style="position:relative;margin-bottom:12px;max-width:640px;display:flex;gap:8px;">
    <div style="position:relative;flex:1;">
      <input type="text" id="search-input" class="input" placeholder="Tìm sản phẩm để chuyển (chọn chi nhánh chuyển trước)...">
      <div id="search-results" style="position:absolute;z-index:10;margin-top:4px;width:100%;background:#fff;border:1px solid #e2e8f0;border-radius:6px;box-shadow:0 4px 12px rgba(0,0,0,.08);max-height:280px;overflow-y:auto;display:none;"></div>
    </div>
    <button type="button" id="scan-btn" class="btn btn-secondary" style="flex-shrink:0;">📷 Quét mã vạch</button>
  </div>
  <div id="scan-modal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.6);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:12px;padding:16px;max-width:420px;width:92%;">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
        <b style="font-size:15px;">Quét mã vạch bằng camera</b>
        <button type="button" id="scan-close" style="border:none;background:none;font-size:22px;line-height:1;cursor:pointer;color:#64748b;">&times;</button>
      </div>
      <div id="scan-reader" style="width:100%;"></div>
      <p id="scan-hint" class="muted" style="font-size:12px;margin:10px 0 0;">Đưa mã vạch của sản phẩm vào giữa khung hình. Trình duyệt sẽ hỏi quyền dùng camera.</p>
    </div>
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

// May quet ma vach (hoac camera, xem scanCode ben duoi) go nhanh ma roi tu bam Enter - tu them
// dung 1 san pham khop vao phieu chuyen ngay, giong man hinh ban hang (POS) va Nhap hang.
searchInput.addEventListener('keydown', (e) => {
  if (e.key !== 'Enter') return;
  e.preventDefault();
  clearTimeout(timer);
  const q = searchInput.value.trim();
  if (!q) return;
  scanCode(q);
});

function scanCode(q) {
  const branchId = fromBranch.value;
  if (!q || !branchId) {
    scanMessage('Vui lòng chọn chi nhánh chuyển trước khi quét.', true);
    return;
  }
  fetch('stock_transfer_search.php?q=' + encodeURIComponent(q) + '&branch_id=' + branchId)
    .then(r => r.json())
    .then(data => {
      if (data.length === 1) {
        const p = data[0];
        addLine(p.id, p.variant_id, p.name, parseFloat(p.qty) || 0);
        searchInput.value = '';
        searchResults.style.display = 'none';
        return;
      }
      const exact = data.find(p => p.sku === q || p.barcode === q);
      if (exact) {
        addLine(exact.id, exact.variant_id, exact.name, parseFloat(exact.qty) || 0);
        searchInput.value = '';
        searchResults.style.display = 'none';
      }
    });
}

function scanMessage(text, isError) {
  const modal = document.getElementById('scan-modal');
  const el = document.getElementById('scan-hint');
  if (!el || modal.style.display === 'none') return;
  el.textContent = text;
  el.style.color = isError ? '#dc2626' : '';
}

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
        <td class="text-right"><input type="number" min="0.001" step="0.001" max="${l.availQty}" value="${l.qty}" data-i="${i}" name="quantity[]" style="width:80px;text-align:right;padding:4px;border:1px solid #cbd5e1;border-radius:6px;"></td>
        <td class="text-right"><a href="#" data-i="${i}" class="remove" style="color:#ef4444;font-size:12px;">Xóa</a></td>
      </tr>`).join('');
    body.querySelectorAll('input[name="quantity[]"]').forEach(inp => {
      inp.addEventListener('input', () => { lines[parseInt(inp.dataset.i, 10)].qty = Math.round((parseFloat(inp.value) || 1) * 1000) / 1000; });
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

fromBranch.addEventListener('change', () => { lines = []; render(); });

// Quet ma vach bang camera dien thoai/laptop - chi tai thu vien khi bam nut.
let html5QrCode = null;
let html5QrStart = null;
let scanCooldown = false;

function openScanner() {
  document.getElementById('scan-modal').style.display = 'flex';
  if (typeof Html5Qrcode === 'undefined') {
    document.getElementById('scan-hint').textContent = 'Không tải được thư viện quét mã. Kiểm tra kết nối mạng rồi thử lại.';
    document.getElementById('scan-hint').style.color = '#dc2626';
    return;
  }
  if (html5QrCode) return; // dang mo / dang chay roi: tranh mo camera hai lan
  html5QrCode = new Html5Qrcode('scan-reader');
  html5QrStart = html5QrCode.start(
    { facingMode: 'environment' },
    { fps: 10, qrbox: { width: 260, height: 160 } },
    (decodedText) => {
      if (scanCooldown) return;
      scanCooldown = true;
      scanCode(decodedText.trim());
      setTimeout(() => { scanCooldown = false; }, 1500);
    },
    () => {}
  );
  html5QrStart.catch((err) => {
    html5QrCode = null;
    document.getElementById('scan-hint').textContent = 'Không mở được camera: ' + err
      + '. Kiểm tra đã cho phép trình duyệt dùng camera chưa.';
    document.getElementById('scan-hint').style.color = '#dc2626';
  });
}

function closeScanner() {
  document.getElementById('scan-modal').style.display = 'none';
  if (html5QrCode) {
    // Cho start() xong roi moi stop(): dong modal luc camera dang khoi dong thi stop() truoc do
    // se loi va den camera bi bat mai.
    const qr = html5QrCode;
    html5QrCode = null;
    (html5QrStart || Promise.resolve()).then(() => qr.stop()).then(() => qr.clear()).catch(() => {});
  }
}
window.addEventListener('pagehide', closeScanner);

document.getElementById('scan-btn').addEventListener('click', openScanner);
document.getElementById('scan-close').addEventListener('click', closeScanner);
</script>
<script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
