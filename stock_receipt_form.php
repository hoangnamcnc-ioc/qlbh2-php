<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireRole('ADMIN', 'MANAGER');

$pdo = db();
$tenantId = currentTenantId();
$suppliersStmt = $pdo->prepare('SELECT * FROM suppliers WHERE tenant_id = ? ORDER BY name');
$suppliersStmt->execute([$tenantId]);
$suppliers = $suppliersStmt->fetchAll();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $branchId = (int) ($currentUser['branch_id'] ?? 0);
    $supplierId = (int) ($_POST['supplier_id'] ?? 0) ?: null;
    if ($supplierId && !in_array($supplierId, array_map('intval', array_column($suppliers, 'id')), true)) {
        $supplierId = null;
    }
    $note = post('note') ?: null;
    $invoiceDate = post('invoice_date') ?: null;
    $referenceNo = post('reference_no') ?: null;
    $discountAmount = postFloat('discount_amount');
    $extraCost = postFloat('extra_cost');
    $paidAmount = postFloat('paid_amount');
    $dueDate = post('due_date') ?: null;
    if ($dueDate !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
        $dueDate = null;
    }
    $lotNumbers = $_POST['lot_number'] ?? [];
    $expiryDates = $_POST['expiry_date'] ?? [];
    $productIds = $_POST['product_id'] ?? [];
    $variantIds = $_POST['variant_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $costPrices = $_POST['cost_price'] ?? [];

    if (!$branchId) {
        $error = 'Tài khoản chưa được gán chi nhánh';
    } else {
        // Chi chap nhan san pham/bien the thuc su thuoc tenant hien tai - tranh nhap kho
        // gia mao cho product_id cua tenant khac qua request thu cong.
        $lines = [];
        foreach ($productIds as $i => $pid) {
            $pid = (int) $pid;
            $vid = (int) ($variantIds[$i] ?? 0) ?: null;
            $qty = round((float) ($quantities[$i] ?? 0), 3);
            $cost = (float) ($costPrices[$i] ?? 0);
            if ($pid > 0 && $qty > 0 && $cost >= 0) {
                $ownCheck = $pdo->prepare('SELECT id FROM products WHERE id = ? AND tenant_id = ?');
                $ownCheck->execute([$pid, $tenantId]);
                if (!$ownCheck->fetch()) {
                    continue;
                }
                if ($vid) {
                    $ownVariant = $pdo->prepare('SELECT id FROM product_variants WHERE id = ? AND product_id = ? AND tenant_id = ?');
                    $ownVariant->execute([$vid, $pid, $tenantId]);
                    if (!$ownVariant->fetch()) {
                        continue;
                    }
                }
                // Lo + han su dung (khong bat buoc): co 1 trong 2 thi tao lo hang moi gan voi phieu nhap
                $lot = mb_substr(trim((string) ($lotNumbers[$i] ?? '')), 0, 100);
                $exp = trim((string) ($expiryDates[$i] ?? ''));
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp)) {
                    $exp = '';
                }
                $lines[] = [$pid, $vid, $qty, $cost, $lot, $exp];
            }
        }

        if (!$lines) {
            $error = 'Vui lòng thêm ít nhất 1 sản phẩm với số lượng hợp lệ';
        } else {
            $pdo->beginTransaction();
            try {
                $subTotal = 0.0;
                foreach ($lines as [$pid, $vid, $qty, $cost]) {
                    $subTotal += $qty * $cost;
                }
                $discountAmount = min($discountAmount, $subTotal);
                $total = $subTotal - $discountAmount + $extraCost;
                $paidAmount = min($paidAmount, $total);

                $code = 'PN' . substr((string) (int) round(microtime(true) * 1000), -8);
                $pdo->prepare(
                    'INSERT INTO stock_receipts (code, branch_id, supplier_id, created_by_id, invoice_date, reference_no, discount_amount, extra_cost, paid_amount, total_amount, note, due_date) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
                )->execute([$code, $branchId, $supplierId, $currentUser['id'], $invoiceDate, $referenceNo, $discountAmount, $extraCost, $paidAmount, $total, $note, ($total - $paidAmount) > 0 ? $dueDate : null]);
                $receiptId = (int) $pdo->lastInsertId();

                $itemStmt = $pdo->prepare(
                    'INSERT INTO stock_receipt_items (receipt_id, product_id, variant_id, quantity, cost_price) VALUES (?,?,?,?,?)'
                );
                $lineNo = 0;
                foreach ($lines as [$pid, $vid, $qty, $cost, $lot, $exp]) {
                    $lineNo++;
                    $itemStmt->execute([$receiptId, $pid, $vid, $qty, $cost]);
                    if ($lot !== '' || $exp !== '') {
                        $pdo->prepare('INSERT INTO product_batches (product_id, branch_id, lot_number, expiry_date, quantity, receipt_id) VALUES (?,?,?,?,?,?)')
                            ->execute([$pid, $branchId, $lot !== '' ? $lot : ($code . '-' . $lineNo), $exp !== '' ? $exp : null, $qty, $receiptId]);
                    }

                    if ($vid) {
                        $inv = $pdo->prepare('SELECT id FROM inventory WHERE branch_id = ? AND variant_id = ?');
                        $inv->execute([$branchId, $vid]);
                    } else {
                        $inv = $pdo->prepare('SELECT id FROM inventory WHERE branch_id = ? AND product_id = ? AND variant_id IS NULL');
                        $inv->execute([$branchId, $pid]);
                    }
                    $invRow = $inv->fetch();
                    if ($invRow) {
                        $pdo->prepare('UPDATE inventory SET quantity = quantity + ? WHERE id = ?')
                            ->execute([$qty, $invRow['id']]);
                    } else {
                        $pdo->prepare('INSERT INTO inventory (branch_id, product_id, variant_id, quantity) VALUES (?,?,?,?)')
                            ->execute([$branchId, $pid, $vid, $qty]);
                    }

                    // Cập nhật giá vốn (gần nhất hoặc bình quân gia quyền theo cấu hình kho)
                    capNhatGiaVonKhiNhap($pdo, (int) $pid, $vid ? (int) $vid : null, (float) $qty, (float) $cost);
                }

                if ($supplierId) {
                    $pdo->prepare('UPDATE suppliers SET debt = debt + ? WHERE id = ?')->execute([$total - $paidAmount, $supplierId]);
                }

                if ($paidAmount > 0) {
                    recordCashbookEntry($branchId, 'PAYMENT', $paidAmount, "Chi tiền nhập hàng $code", 'CASH', $currentUser['id'], null, $receiptId);
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
    <div class="grid-2">
      <div class="field"><label>Ngày hoá đơn</label><input class="input" type="date" name="invoice_date"></div>
      <div class="field"><label>Tham chiếu (số hoá đơn/chứng từ NCC)</label><input class="input" name="reference_no"></div>
    </div>
    <div class="field">
      <label>Ghi chú</label>
      <input class="input" name="note">
    </div>
    <div class="field">
      <label>Hạn trả tiền NCC (nếu còn nợ)</label>
      <input class="input" type="date" name="due_date">
      <div class="muted" style="font-size:12px;margin-top:2px;">Có nhập hạn thì phiếu nợ sẽ được nhắc ở Trang chủ và Công nợ khi sắp đến hạn / quá hạn.</div>
    </div>
  </div>

  <div style="position:relative;margin-bottom:12px;max-width:640px;display:flex;gap:8px;">
    <div style="position:relative;flex:1;">
      <input type="text" id="search-input" class="input" placeholder="Tìm sản phẩm theo tên hoặc SKU để thêm vào phiếu nhập...">
      <div id="search-results" style="position:absolute;z-index:10;margin-top:4px;width:100%;background:#fff;border:1px solid #e2e8f0;border-radius:6px;box-shadow:0 4px 12px rgba(0,0,0,.08);max-height:280px;overflow-y:auto;display:none;"></div>
    </div>
    <button type="button" id="scan-btn" class="btn btn-secondary" style="flex-shrink:0;">📷 Quét mã vạch</button>
  </div>
  <div id="qa-modal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.6);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:12px;padding:20px;max-width:420px;width:92%;max-height:92vh;overflow:auto;">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
        <b style="font-size:16px;">Tạo hàng mới</b>
        <button type="button" id="qa-close" style="border:none;background:none;font-size:22px;line-height:1;cursor:pointer;color:#64748b;">&times;</button>
      </div>
      <p class="muted" style="font-size:12px;margin:0 0 12px;">Hàng này chưa có trong danh mục. Tạo nhanh rồi thêm luôn vào phiếu nhập; muốn khai báo đầy đủ (danh mục, thương hiệu...) thì vào Sản phẩm sau.</p>
      <div id="qa-error" class="alert alert-error" style="display:none;margin-bottom:10px;"></div>
      <div class="field"><label>Tên hàng *</label><input type="text" id="qa-name" class="input"></div>
      <div class="field"><label>Mã SKU (bỏ trống để tự sinh)</label><input type="text" id="qa-sku" class="input"></div>
      <div class="field"><label>Mã vạch (nếu có)</label><input type="text" id="qa-barcode" class="input"></div>
      <div style="display:flex;gap:10px;">
        <div class="field" style="flex:1"><label>Đơn vị tính</label><input type="text" id="qa-unit" class="input" placeholder="cái, hộp..."></div>
        <div class="field" style="flex:1"><label>Giá nhập</label><input type="number" id="qa-cost" class="input" min="0" value="0"></div>
        <div class="field" style="flex:1"><label>Giá bán</label><input type="number" id="qa-sell" class="input" min="0" value="0"></div>
      </div>
      <div style="display:flex;gap:8px;justify-content:flex-end;">
        <button type="button" id="qa-cancel" class="btn btn-secondary">Hủy</button>
        <button type="button" id="qa-save" class="btn">Tạo và thêm vào phiếu</button>
      </div>
    </div>
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
      <thead><tr><th>Sản phẩm (lô / hạn dùng nếu có)</th><th class="text-right">SL</th><th class="text-right">Giá vốn</th><th class="text-right">Thành tiền</th><th></th></tr></thead>
      <tbody id="lines-body">
        <tr id="empty-row"><td colspan="5" class="text-center muted" style="padding:24px;">Chưa có sản phẩm nào</td></tr>
      </tbody>
    </table>
  </div>

  <div class="card" style="max-width:400px;margin-left:auto;margin-bottom:16px;">
    <div class="field"><label>Chiết khấu</label><input class="input" type="number" min="0" id="discount-input" name="discount_amount" value="0"></div>
    <div class="field"><label>Chi phí nhập hàng (vận chuyển...)</label><input class="input" type="number" min="0" id="extra-cost-input" name="extra_cost" value="0"></div>
    <div class="field"><label>Đã trả NCC ngay</label><input class="input" type="number" min="0" id="paid-input" name="paid_amount" value="0"></div>
    <div style="display:flex;justify-content:space-between;font-size:14px;margin-bottom:4px;"><span>Tạm tính</span><span id="sub-total">0</span></div>
    <div style="display:flex;justify-content:space-between;border-top:1px solid #e2e8f0;padding-top:8px;">
      <span>Tổng tiền</span><b id="grand-total" style="font-size:18px;color:#2563eb;">0</b>
    </div>
  </div>

  <div style="max-width:800px;text-align:right;">
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
        searchResults.innerHTML = data.map((p, i) => `
          <div class="s-item" data-i="${i}"
               style="padding:8px 12px;font-size:14px;cursor:pointer;">${esc(p.name)} <span class="muted" style="font-family:monospace;font-size:12px;">(${esc(p.sku)})</span></div>`).join('')
          + `<div id="s-create-new" style="padding:8px 12px;font-size:14px;cursor:pointer;border-top:1px solid #e2e8f0;color:#2563eb;font-weight:600;">➕ ${data.length ? 'Không có hàng cần tìm? ' : 'Chưa có trong danh mục. '}Tạo hàng mới "${esc(q)}"</div>`;
        searchResults.style.display = 'block';
        document.getElementById('s-create-new').addEventListener('click', () => openQuickAdd(q));
        searchResults.querySelectorAll('.s-item').forEach(el => {
          el.addEventListener('click', () => {
            const p = data[parseInt(el.dataset.i, 10)];
            addLine(p.id, p.variant_id, p.name, parseFloat(p.cost_price) || 0, p.pack_unit, parseFloat(p.pack_size) || 1, parseFloat(p.sell_price) || 0);
            searchInput.value = '';
            searchResults.style.display = 'none';
          });
        });
      });
  }, 250);
});

// May quet ma vach (hoac camera, xem scanCode ben duoi) go nhanh ma roi tu bam Enter - bat su
// kien nay de tu them dung 1 san pham khop ma vao phieu nhap ngay, giong man hinh ban hang (POS).
searchInput.addEventListener('keydown', (e) => {
  if (e.key !== 'Enter') return;
  e.preventDefault();
  clearTimeout(timer);
  const q = searchInput.value.trim();
  if (!q) return;
  scanCode(q);
});

function scanCode(q) {
  fetch('stock_receipt_search.php?q=' + encodeURIComponent(q))
    .then(r => r.json())
    .then(data => {
      if (data.length === 1) {
        const p = data[0];
        addLine(p.id, p.variant_id, p.name, parseFloat(p.cost_price) || 0, p.pack_unit, parseFloat(p.pack_size) || 1, parseFloat(p.sell_price) || 0);
        searchInput.value = '';
        searchResults.style.display = 'none';
        return;
      }
      const exact = data.find(p => p.sku === q || p.barcode === q);
      if (exact) {
        addLine(exact.id, exact.variant_id, exact.name, parseFloat(exact.cost_price) || 0, exact.pack_unit, parseFloat(exact.pack_size) || 1, parseFloat(exact.sell_price) || 0);
        searchInput.value = '';
        searchResults.style.display = 'none';
      }
    });
}

// Quy doi don vi lon (thung/loc) sang don vi le NGAY TAI TRINH DUYET truoc khi gui form - server
// van chi nhan quantity[]/cost_price[] theo dung don vi le nhu truoc gio, khong doi 1 dong PHP
// nao o phia xu ly/luu kho. l.qtyInput/costInput la so nguoi dung GO (theo don vi dang chon o
// l.mode: 'le' hoac 'lon'); l.qty/l.cost la gia tri THAT SU se gui len server (luon quy ve don
// vi le) - tinh lai moi lan render().
function addLine(id, variantId, name, cost, packUnit, packSize, sellPrice) {
  const key = id + ':' + (variantId ?? '');
  const existing = lines.find(l => l.key === key);
  if (existing) {
    existing.qtyInput += 1;
  } else {
    lines.push({
      key, id, variantId, name,
      packUnit: packUnit || null, packSize: packSize > 1 ? packSize : 1,
      mode: 'le', qtyInput: 1, costInput: cost, qty: 1, cost, lot: '', exp: '', baseCost: cost, sell: sellPrice || 0, ncc: null,
    });
  }
  recalc();
  render();
  if (!existing) loadNcc(lines[lines.length - 1]);
}

// Tinh lai qty/cost (don vi le, gui len server) tu qtyInput/costInput/mode cua tung dong.
function recalc() {
  lines.forEach(l => {
    if (l.mode === 'lon' && l.packUnit) {
      l.qty = l.qtyInput * l.packSize;
      l.cost = l.packSize > 0 ? l.costInput / l.packSize : l.costInput;
    } else {
      l.qty = l.qtyInput;
      l.cost = l.costInput;
    }
  });
}

// Canh bao thong minh khi gia nhap lech nhieu so voi lan truoc (>=5%) va goi y gia ban de giu nguyen ti le lai.
function nccHint(l) {
  if (!l.ncc || !l.ncc.length) return '';
  const sel = document.querySelector('select[name="supplier_id"]');
  const chosen = sel ? parseInt(sel.value, 10) || 0 : 0;
  const best = l.ncc[0];
  let s = 'Giá nhập gần đây theo NCC: ' + l.ncc.slice(0, 3).map(x => x.name + ' ' + fmt(x.cost) + ' (' + x.date + ')').join(' · ');
  let warn = '';
  const mine = chosen ? l.ncc.find(x => x.supplier_id === chosen) : null;
  if (mine && best.supplier_id !== chosen && mine.cost > best.cost * 1.03) {
    warn = ' ⚠ NCC đang chọn đắt hơn ' + best.name + ' khoảng ' + Math.round((mine.cost / best.cost - 1) * 100) + '%.';
  }
  return '<div style="font-size:11px;color:' + (warn ? '#b45309' : '#64748b') + ';margin-top:3px;">' + esc(s + warn) + '</div>';
}
function loadNcc(l) {
  if (l.variantId || l.ncc !== null) return;
  l.ncc = [];
  fetch('ncc_gia.php?product_id=' + encodeURIComponent(l.id)).then(r => r.json()).then(d => { l.ncc = Array.isArray(d) ? d : []; render(); }).catch(() => {});
}
function costHint(l) {
  const base = l.baseCost || 0;
  if (base <= 0 || l.cost <= 0) return '';
  const diff = (l.cost - base) / base * 100;
  if (Math.abs(diff) < 5) return '';
  const pct = (diff > 0 ? '+' : '') + Math.round(diff) + '%';
  if (diff > 0) {
    let s = '⚠ Giá nhập tăng ' + pct + ' so với lần trước (' + fmt(base) + ' → ' + fmt(l.cost) + ').';
    if (l.sell > base) {
      const margin = (l.sell - base) / l.sell;
      const goiY = Math.ceil(l.cost / (1 - margin) / 500) * 500;
      s += ' Giá bán hiện ' + fmt(l.sell) + ' thì biên lãi giảm từ ' + Math.round(margin * 100) + '% xuống ' + Math.max(0, Math.round((l.sell - l.cost) / l.sell * 100)) + '%; muốn giữ biên nên bán khoảng ' + fmt(goiY) + '.';
    }
    return '<div style="font-size:11px;color:#b45309;margin-top:3px;">' + esc(s) + '</div>';
  }
  return '<div style="font-size:11px;color:#059669;margin-top:3px;">' + esc('✓ Giá nhập giảm ' + pct + ' so với lần trước (' + fmt(base) + ' → ' + fmt(l.cost) + ') — có thể tăng biên lãi hoặc giảm giá bán để bán nhanh hơn.') + '</div>';
}

function render() {
  const body = document.getElementById('lines-body');
  if (!lines.length) {
    body.innerHTML = '<tr id="empty-row"><td colspan="5" class="text-center muted" style="padding:24px;">Chưa có sản phẩm nào</td></tr>';
  } else {
    body.innerHTML = lines.map((l, i) => `
      <tr>
        <td>${esc(l.name)}<input type="hidden" name="product_id[]" value="${l.id}"><input type="hidden" name="variant_id[]" value="${l.variantId ?? ''}">
          <input type="hidden" name="quantity[]" value="${l.qty}"><input type="hidden" name="cost_price[]" value="${l.cost}">
          <div style="margin-top:4px;display:flex;gap:6px;flex-wrap:wrap;">
            <input type="text" name="lot_number[]" value="${esc(l.lot)}" data-i="${i}" data-f="lot" placeholder="Số lô" maxlength="100" style="width:100px;font-size:12px;padding:3px 6px;border:1px solid #cbd5e1;border-radius:4px;">
            <input type="date" name="expiry_date[]" value="${esc(l.exp)}" data-i="${i}" data-f="exp" title="Hạn sử dụng" style="font-size:12px;padding:3px 6px;border:1px solid #cbd5e1;border-radius:4px;">
          </div>
          ${l.packUnit ? `
            <div style="margin-top:4px;">
              <select data-i="${i}" data-f="mode" style="font-size:11px;padding:2px 4px;border:1px solid #cbd5e1;border-radius:4px;">
                <option value="le" ${l.mode === 'le' ? 'selected' : ''}>Nhập theo lẻ</option>
                <option value="lon" ${l.mode === 'lon' ? 'selected' : ''}>Nhập theo ${esc(l.packUnit)} (1 ${esc(l.packUnit)} = ${l.packSize} lẻ)</option>
              </select>
            </div>` : ''}
        </td>
        <td class="text-right">
          <input type="number" min="0.001" step="0.001" value="${l.qtyInput}" data-i="${i}" data-f="qtyInput" style="width:70px;text-align:right;padding:4px;border:1px solid #cbd5e1;border-radius:6px;">
          ${l.mode === 'lon' ? `<div class="muted" style="font-size:11px;">= ${fmtQty(l.qty)} lẻ</div>` : ''}
        </td>
        <td class="text-right">
          <input type="number" min="0" value="${l.costInput}" data-i="${i}" data-f="costInput" style="width:100px;text-align:right;padding:4px;border:1px solid #cbd5e1;border-radius:6px;">
          ${l.mode === 'lon' ? `<div class="muted" style="font-size:11px;">= ${fmt(l.cost)}/lẻ</div>` : ''}
          ${costHint(l)}${nccHint(l)}
        </td>
        <td class="text-right">${fmt(l.qty * l.cost)}</td>
        <td class="text-right"><a href="#" data-i="${i}" class="remove" style="color:#ef4444;font-size:12px;">Xóa</a></td>
      </tr>`).join('');
    body.querySelectorAll('select[data-f="mode"]').forEach(sel => {
      sel.addEventListener('change', () => {
        const i = parseInt(sel.dataset.i, 10);
        lines[i].mode = sel.value;
        // Doi don vi thi so nhap cu (vd "5" thung) khong con hop ly cho don vi moi - dat lai ve
        // 1 de tranh vo tinh nhap sai so luong lon gap boi.
        lines[i].qtyInput = 1;
        recalc();
        render();
      });
    });
    body.querySelectorAll('input[data-f="lot"],input[data-f="exp"]').forEach(inp => {
      // Khong render lai khi go lo/han de khong mat con tro; chi luu vao trang thai dong
      inp.addEventListener('input', () => { lines[parseInt(inp.dataset.i, 10)][inp.dataset.f] = inp.value; });
    });
    body.querySelectorAll('input[data-f="qtyInput"],input[data-f="costInput"]').forEach(inp => {
      inp.addEventListener('input', () => {
        const i = parseInt(inp.dataset.i, 10);
        const f = inp.dataset.f;
        lines[i][f] = parseFloat(inp.value) || 0;
        recalc();
        render();
      });
    });
    body.querySelectorAll('.remove').forEach(a => {
      a.addEventListener('click', (e) => { e.preventDefault(); lines.splice(parseInt(a.dataset.i, 10), 1); render(); });
    });
  }
  updateTotals();
}

function fmtQty(n) { return (Math.round(n * 1000) / 1000).toLocaleString('vi-VN'); }

function updateTotals() {
  const subTotal = lines.reduce((s, l) => s + l.qty * l.cost, 0);
  const discount = parseFloat(document.getElementById('discount-input').value) || 0;
  const extraCost = parseFloat(document.getElementById('extra-cost-input').value) || 0;
  const total = Math.max(0, subTotal - discount) + extraCost;
  document.getElementById('sub-total').textContent = fmt(subTotal);
  document.getElementById('grand-total').textContent = fmt(total);
}
document.getElementById('discount-input').addEventListener('input', updateTotals);
const supSel = document.querySelector('select[name="supplier_id"]');
if (supSel) supSel.addEventListener('change', render);
document.getElementById('extra-cost-input').addEventListener('input', updateTotals);

function fmt(n) { return Math.round(n).toLocaleString('vi-VN'); }
function esc(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

document.addEventListener('click', (e) => {
  if (!e.target.closest('#search-input') && !e.target.closest('#search-results')) {
    searchResults.style.display = 'none';
  }
});

// Tao nhanh hang moi ngay trong phieu nhap khi hang chua co trong danh muc.
function openQuickAdd(q) {
  searchResults.style.display = 'none';
  document.getElementById('qa-error').style.display = 'none';
  const isCode = /^[0-9]{8,14}$/.test(q);
  document.getElementById('qa-name').value = isCode ? '' : q;
  document.getElementById('qa-barcode').value = isCode ? q : '';
  document.getElementById('qa-sku').value = '';
  document.getElementById('qa-unit').value = '';
  document.getElementById('qa-cost').value = 0;
  document.getElementById('qa-sell').value = 0;
  document.getElementById('qa-modal').style.display = 'flex';
  document.getElementById('qa-name').focus();
}
function closeQuickAdd() { document.getElementById('qa-modal').style.display = 'none'; }
document.getElementById('qa-close').addEventListener('click', closeQuickAdd);
document.getElementById('qa-cancel').addEventListener('click', closeQuickAdd);
document.getElementById('qa-save').addEventListener('click', () => {
  const btn = document.getElementById('qa-save');
  const errBox = document.getElementById('qa-error');
  const fd = new FormData();
  fd.append('csrf', <?= json_encode(csrfToken()) ?>);
  fd.append('name', document.getElementById('qa-name').value);
  fd.append('sku', document.getElementById('qa-sku').value);
  fd.append('barcode', document.getElementById('qa-barcode').value);
  fd.append('unit', document.getElementById('qa-unit').value);
  fd.append('cost_price', document.getElementById('qa-cost').value);
  fd.append('sell_price', document.getElementById('qa-sell').value);
  btn.disabled = true;
  fetch('product_quick_add.php', { method: 'POST', body: fd })
    .then(r => r.json().then(d => ({ ok: r.ok, d })))
    .then(({ ok, d }) => {
      btn.disabled = false;
      if (!ok) { errBox.textContent = d.error || 'Không tạo được hàng mới.'; errBox.style.display = 'block'; return; }
      addLine(d.id, d.variant_id, d.name, parseFloat(d.cost_price) || 0, d.pack_unit, parseFloat(d.pack_size) || 1, parseFloat(d.sell_price) || 0);
      searchInput.value = '';
      closeQuickAdd();
    })
    .catch(() => { btn.disabled = false; errBox.textContent = 'Lỗi kết nối, vui lòng thử lại.'; errBox.style.display = 'block'; });
});

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
