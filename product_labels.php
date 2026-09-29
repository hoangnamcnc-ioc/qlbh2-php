<?php
/**
 * In tem gia / ma vach cho san pham - truoc day khong co tinh nang nay, phai dung phan mem/
 * may in tem rieng ben ngoai. Tim san pham (dung lai pos_search.php), them vao danh sach voi
 * so luong tem can in, roi in qua trinh duyet (window.print()) - khong can may in tem chuyen
 * dung, in duoc tren giay A4 thuong roi cat, hoac giay decal tem neu co.
 */
require_once __DIR__ . '/inc_header.php';
?>

<h1 style="font-size:24px;font-weight:600;margin-bottom:4px;">In tem giá / mã vạch</h1>
<p class="muted" style="margin:0 0 20px;font-size:13px;">
  Tìm sản phẩm, thêm vào danh sách với số lượng tem cần in, rồi bấm "In tem". In được trên giấy
  A4 thường (cắt tay) hoặc giấy decal tem cỡ tương ứng - chỉnh khổ giấy/lề ở hộp thoại in của
  trình duyệt cho khớp với giấy đang dùng.
</p>

<div class="no-print">
  <div class="card" style="position:relative;margin-bottom:16px;">
    <label style="display:block;margin-bottom:6px;font-size:13px;font-weight:500;">Tìm sản phẩm (tên, mã, mã vạch)</label>
    <input type="text" id="labelSearchInput" class="input" placeholder="Gõ tên hoặc quét mã vạch..." autocomplete="off">
    <div id="labelSearchResults" style="display:none;position:absolute;left:16px;right:16px;top:74px;z-index:20;background:#fff;border:1px solid #e2e8f0;border-radius:8px;max-height:320px;overflow-y:auto;box-shadow:0 8px 20px rgba(0,0,0,.1);"></div>
  </div>

  <div class="card" style="padding:0;overflow-x:auto;margin-bottom:16px;">
    <table>
      <thead>
        <tr><th>Sản phẩm</th><th>SKU</th><th>Giá bán</th><th class="text-right">Số tem</th><th></th></tr>
      </thead>
      <tbody id="labelListBody">
        <tr id="labelListEmpty"><td colspan="5" class="text-center muted" style="padding:24px;">Chưa có sản phẩm nào trong danh sách in.</td></tr>
      </tbody>
    </table>
  </div>

  <div style="display:flex;gap:10px;align-items:center;">
    <label style="font-size:13px;">Số cột mỗi trang:</label>
    <select id="labelCols" class="input" style="width:auto;">
      <option value="2">2</option>
      <option value="3" selected>3</option>
      <option value="4">4</option>
      <option value="5">5</option>
    </select>
    <label style="font-size:13px;margin-left:12px;"><input type="checkbox" id="labelShowPrice" checked style="width:auto;"> Hiện giá bán</label>
    <label style="font-size:13px;margin-left:12px;"><input type="checkbox" id="labelShowBarcode" checked style="width:auto;"> Hiện mã vạch</label>
    <button type="button" class="btn btn-secondary" id="labelClearBtn" style="margin-left:auto;">Xóa hết danh sách</button>
    <button type="button" class="btn" id="labelPrintBtn">In tem</button>
  </div>
</div>

<div id="labelPrintArea" style="display:none;"></div>

<style>
  @media print {
    body * { visibility: hidden; }
    #labelPrintArea, #labelPrintArea * { visibility: visible; }
    #labelPrintArea { display: block !important; position: absolute; left: 0; top: 0; width: 100%; }
    .no-print, .sidebar, header, nav { display: none !important; }
  }
  .label-tag {
    border: 1px dashed #ccc;
    padding: 8px;
    text-align: center;
    box-sizing: border-box;
    break-inside: avoid;
    page-break-inside: avoid;
  }
  .label-grid {
    display: grid;
    gap: 6px;
  }
  .label-name { font-size: 12px; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .label-price { font-size: 14px; font-weight: 800; margin-top: 2px; }
  .label-barcode-img { max-width: 100%; height: 40px; margin-top: 4px; }
  .label-sku { font-size: 10px; color: #666; font-family: monospace; }
</style>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jsbarcode/3.12.3/JsBarcode.all.min.js"></script>
<script>
let labelList = [];

const searchInput = document.getElementById('labelSearchInput');
const searchResults = document.getElementById('labelSearchResults');
let searchTimer = null;

searchInput.addEventListener('input', () => {
  clearTimeout(searchTimer);
  const q = searchInput.value.trim();
  if (!q) { searchResults.style.display = 'none'; return; }
  searchTimer = setTimeout(() => {
    fetch('pos_search.php?q=' + encodeURIComponent(q))
      .then(r => r.json())
      .then(data => {
        if (!data.length) { searchResults.style.display = 'none'; return; }
        searchResults.innerHTML = data.map((p, i) => `
          <div class="search-item" data-i="${i}" style="padding:8px 12px;font-size:14px;cursor:pointer;display:flex;justify-content:space-between;">
            <span>${escapeHtmlLbl(p.name)} <span class="muted" style="font-family:monospace;font-size:12px;">(${escapeHtmlLbl(p.sku)})</span></span>
            <span class="muted">${formatMoneyLbl(p.sell_price)}</span>
          </div>`).join('');
        searchResults.style.display = 'block';
        searchResults.querySelectorAll('.search-item').forEach(el => {
          el.addEventListener('mouseenter', () => el.style.background = '#f8fafc');
          el.addEventListener('mouseleave', () => el.style.background = '#fff');
          el.addEventListener('click', () => {
            const p = data[parseInt(el.dataset.i, 10)];
            themVaoDanhSach(p);
            searchInput.value = '';
            searchResults.style.display = 'none';
          });
        });
      });
  }, 250);
});
document.addEventListener('click', (e) => {
  if (!e.target.closest('#labelSearchInput') && !e.target.closest('#labelSearchResults')) {
    searchResults.style.display = 'none';
  }
});

function themVaoDanhSach(p) {
  const existing = labelList.find(x => x.variant_id === p.variant_id && x.id === p.id);
  if (existing) {
    existing.soTem += 1;
  } else {
    labelList.push({ id: p.id, variant_id: p.variant_id, name: p.name, sku: p.sku, sell_price: parseFloat(p.sell_price), soTem: 1 });
  }
  renderLabelList();
}

function renderLabelList() {
  const tbody = document.getElementById('labelListBody');
  if (labelList.length === 0) {
    tbody.innerHTML = '<tr id="labelListEmpty"><td colspan="5" class="text-center muted" style="padding:24px;">Chưa có sản phẩm nào trong danh sách in.</td></tr>';
    return;
  }
  tbody.innerHTML = labelList.map((item, idx) => `
    <tr>
      <td>${escapeHtmlLbl(item.name)}</td>
      <td class="muted" style="font-family:monospace;font-size:12px;">${escapeHtmlLbl(item.sku)}</td>
      <td>${formatMoneyLbl(item.sell_price)}</td>
      <td class="text-right"><input type="number" min="1" value="${item.soTem}" data-idx="${idx}" class="label-qty-input input" style="width:70px;text-align:right;"></td>
      <td class="text-right"><button type="button" class="btn btn-danger" data-remove="${idx}" style="padding:4px 10px;font-size:12px;">Xóa</button></td>
    </tr>`).join('');
  tbody.querySelectorAll('.label-qty-input').forEach(inp => {
    inp.addEventListener('input', () => {
      labelList[parseInt(inp.dataset.idx, 10)].soTem = Math.max(1, parseInt(inp.value, 10) || 1);
    });
  });
  tbody.querySelectorAll('[data-remove]').forEach(btn => {
    btn.addEventListener('click', () => {
      labelList.splice(parseInt(btn.dataset.remove, 10), 1);
      renderLabelList();
    });
  });
}

document.getElementById('labelClearBtn').addEventListener('click', () => {
  if (labelList.length && !confirm('Xóa hết danh sách đang in?')) return;
  labelList = [];
  renderLabelList();
});

function escapeHtmlLbl(s) {
  const d = document.createElement('div');
  d.textContent = s == null ? '' : String(s);
  return d.innerHTML;
}
function formatMoneyLbl(n) {
  return Number(n || 0).toLocaleString('vi-VN') + 'đ';
}

document.getElementById('labelPrintBtn').addEventListener('click', () => {
  if (labelList.length === 0) {
    alert('Danh sách in đang trống, hãy tìm và thêm sản phẩm trước.');
    return;
  }
  const cols = document.getElementById('labelCols').value;
  const showPrice = document.getElementById('labelShowPrice').checked;
  const showBarcode = document.getElementById('labelShowBarcode').checked;

  const tags = [];
  labelList.forEach(item => {
    for (let i = 0; i < item.soTem; i++) tags.push(item);
  });

  const area = document.getElementById('labelPrintArea');
  area.innerHTML = '<div class="label-grid" id="labelGridInner" style="grid-template-columns:repeat(' + cols + ',1fr);"></div>';
  const grid = document.getElementById('labelGridInner');
  tags.forEach((item, i) => {
    const tag = document.createElement('div');
    tag.className = 'label-tag';
    tag.innerHTML =
      '<div class="label-name">' + escapeHtmlLbl(item.name) + '</div>' +
      '<div class="label-sku">' + escapeHtmlLbl(item.sku) + '</div>' +
      (showPrice ? '<div class="label-price">' + formatMoneyLbl(item.sell_price) + '</div>' : '') +
      (showBarcode ? '<svg class="label-barcode-img" id="barcode-' + i + '"></svg>' : '');
    grid.appendChild(tag);
  });
  area.style.display = 'block';

  if (showBarcode && window.JsBarcode) {
    tags.forEach((item, i) => {
      try {
        JsBarcode('#barcode-' + i, item.sku || String(item.id), { format: 'CODE128', displayValue: false, height: 35, margin: 0 });
      } catch (e) { /* SKU khong hop le lam ma vach (vd rong) - bo qua rieng tem do */ }
    });
  }

  setTimeout(() => {
    window.print();
    area.style.display = 'none';
  }, 150);
});
</script>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
