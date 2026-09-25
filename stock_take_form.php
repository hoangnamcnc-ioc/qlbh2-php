<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireRole('ADMIN', 'MANAGER');

$pdo = db();
$tenantId = currentTenantId();
$branchesStmt = $pdo->prepare('SELECT * FROM branches WHERE is_active = 1 AND tenant_id = ? ORDER BY name');
$branchesStmt->execute([$tenantId]);
$branches = $branchesStmt->fetchAll();
$error = null;

// MANAGER chỉ được tạo phiếu kiểm hàng cho chi nhánh mình quản lý — tránh sửa/xem nhầm tồn
// kho chi nhánh khác (giống các trang Đơn hàng/Tồn kho đã chặn theo chi nhánh).
$lockedBranchId = hasRole('ADMIN') ? 0 : effectiveBranchId($currentUser);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $branchId = (int) ($_POST['branch_id'] ?? 0);
    if ($lockedBranchId && $branchId !== $lockedBranchId) {
        $branchId = $lockedBranchId;
    }
    // Du la ADMIN cung chi duoc chon chi nhanh thuoc tenant minh.
    if ($branchId && !in_array($branchId, array_map('intval', array_column($branches, 'id')), true)) {
        $branchId = 0;
    }
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
      <?php if ($lockedBranchId): ?>
        <?php $lockedBranch = array_values(array_filter($branches, fn ($b) => (int) $b['id'] === $lockedBranchId))[0] ?? null; ?>
        <input type="hidden" name="branch_id" id="branch-select" value="<?= $lockedBranchId ?>">
        <input class="input" value="<?= e($lockedBranch['name'] ?? '') ?>" disabled>
      <?php else: ?>
        <select class="input" name="branch_id" id="branch-select" required>
          <option value="">— Chọn chi nhánh —</option>
          <?php foreach ($branches as $b): ?>
            <option value="<?= (int) $b['id'] ?>" <?= (int) ($currentUser['branch_id'] ?? 0) === (int) $b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option>
          <?php endforeach; ?>
        </select>
      <?php endif; ?>
    </div>
    <div class="field"><label>Ghi chú</label><input class="input" name="note"></div>
  </div>

  <div style="position:relative;margin-bottom:4px;max-width:640px;display:flex;gap:8px;">
    <div style="position:relative;flex:1;">
      <input type="text" id="search-input" class="input" placeholder="Tìm sản phẩm để kiểm...">
      <div id="search-results" style="position:absolute;z-index:10;margin-top:4px;width:100%;background:#fff;border:1px solid #e2e8f0;border-radius:6px;box-shadow:0 4px 12px rgba(0,0,0,.08);max-height:280px;overflow-y:auto;display:none;"></div>
    </div>
    <button type="button" id="scan-btn" class="btn btn-secondary" style="flex-shrink:0;">📷 Quét mã vạch</button>
  </div>
  <div id="scan-msg" style="margin-bottom:8px;font-size:13px;min-height:18px;"></div>

  <!-- Modal quet camera: chi tai thu vien khi bam nut, khong lam nang trang cho nguoi khong dung -->
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
      <thead><tr><th>Sản phẩm</th><th class="text-right">Tồn hệ thống</th><th class="text-right">SL thực tế</th><th class="text-right">Chênh lệch</th><th></th></tr></thead>
      <tbody id="lines-body">
        <tr id="empty-row"><td colspan="5" class="text-center muted" style="padding:24px;">Chưa có sản phẩm nào</td></tr>
      </tbody>
    </table>
  </div>

  <button type="submit" class="btn">Lưu phiếu kiểm hàng</button>
</form>

<!-- Thu vien quet ma vach qua camera (dung getUserMedia + ZXing ben trong), chi tai o trang nay
     (khong nhet vao inc_header.php dung chung) - trang khac khong can nen khong phai tai them
     ~200KB. Ghim dung 1 phien ban cu the, giong quy uoc dung cho moi thu vien ngoai trong du an. -->
<script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
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

// May quet ma vach go nhanh ma roi tu bam Enter - tu them dung 1 san pham khop vao danh sach kiem
// ngay, giong hanh vi da co o man hinh ban hang (POS) va man hinh Nhap hang.
searchInput.addEventListener('keydown', (e) => {
  if (e.key !== 'Enter') return;
  e.preventDefault();
  clearTimeout(timer);
  scanCode(searchInput.value.trim());
});

// Dung CHUNG cho ca may quet vat ly (Enter o tren) LAN quet bang camera dien thoai ben duoi -
// cung 1 quy tac khop ma (dung 1 ket qua HOAC khop chinh xac sku/barcode), tranh viet trung logic
// va tranh 2 duong quet cho ra hanh vi khac nhau.
function scanCode(q) {
  const branchId = branchSelect.value;
  if (!q || !branchId) return;
  fetch('stock_take_search.php?q=' + encodeURIComponent(q) + '&branch_id=' + branchId)
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
      } else {
        scanMessage(`Không tìm thấy sản phẩm khớp mã "${q}".`, true);
      }
    });
}

function scanMessage(text, isError) {
  const el = document.getElementById('scan-msg');
  if (!el) return;
  el.textContent = text;
  el.style.color = isError ? '#dc2626' : '#16a34a';
  setTimeout(() => { el.textContent = ''; }, 2500);
}

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

// Quet ma vach bang camera dien thoai. Moi lan quet duoc 1 ma la goi scanCode() - DUNG CHUNG
// duong xu ly voi may quet vat ly gan qua bang phim (khong tu them dong hang o day, tranh 2 noi
// co logic khop-san-pham khac nhau). Tu dong tam dung ~1.5s sau khi quet duoc 1 ma de tranh quet
// lai lien tuc cung 1 ma khi camera con dang huong vao no.
let html5QrCode = null;
let scanCooldown = false;

function openScanner() {
  document.getElementById('scan-modal').style.display = 'flex';
  if (typeof Html5Qrcode === 'undefined') {
    document.getElementById('scan-hint').textContent = 'Không tải được thư viện quét mã. Kiểm tra kết nối mạng rồi thử lại.';
    document.getElementById('scan-hint').style.color = '#dc2626';
    return;
  }
  html5QrCode = new Html5Qrcode('scan-reader');
  html5QrCode.start(
    { facingMode: 'environment' }, // camera sau - dung camera thuong dua vao ma vach
    { fps: 10, qrbox: { width: 260, height: 160 } },
    (decodedText) => {
      if (scanCooldown) return;
      scanCooldown = true;
      scanCode(decodedText.trim());
      setTimeout(() => { scanCooldown = false; }, 1500);
    },
    () => {} // loi doc tung khung hinh (binh thuong, xay ra lien tuc khi chua thay ma) - bo qua
  ).catch((err) => {
    document.getElementById('scan-hint').textContent = 'Không mở được camera: ' + err
      + '. Kiểm tra đã cho phép trình duyệt dùng camera chưa.';
    document.getElementById('scan-hint').style.color = '#dc2626';
  });
}

function closeScanner() {
  document.getElementById('scan-modal').style.display = 'none';
  if (html5QrCode) {
    html5QrCode.stop().then(() => html5QrCode.clear()).catch(() => {});
    html5QrCode = null;
  }
}

document.getElementById('scan-btn').addEventListener('click', openScanner);
document.getElementById('scan-close').addEventListener('click', closeScanner);
</script>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
