<?php
/**
 * Cau hinh giao hang (§5 va §14 dac ta): phi mac dinh + bieu phi theo khu vuc.
 *
 * Luu trong store_settings duoi dang JSON thay vi tao bang moi: bieu phi cua mot cua hang nho chi
 * vai dong, khong dang de them bang + migration tren CSDL dang chay that. store_settings da co
 * khoa chinh (tenant_id, setting_key) nen moi cua hang tu co bieu phi rieng.
 *
 * Cach dung: o man hinh ban hang va tao don giao hang, khi go dia chi thi he thong do tu khoa khu
 * vuc trong dia chi do va tu dien phi tuong ung - nguoi ban van sua tay duoc.
 */
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN', 'MANAGER');

$error = null;
$saved = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $defaultFee = max(0, (float) ($_POST['default_fee'] ?? 0));
    $names = $_POST['zone_name'] ?? [];
    $keywords = $_POST['zone_keywords'] ?? [];
    $fees = $_POST['zone_fee'] ?? [];

    $zones = [];
    foreach ($names as $i => $name) {
        $name = trim((string) $name);
        $kw = trim((string) ($keywords[$i] ?? ''));
        if ($name === '' || $kw === '') {
            continue;
        }
        // Tach tu khoa bang dau phay, bo khoang trang thua va cac o rong
        $kwList = array_values(array_filter(array_map('trim', explode(',', $kw)), static fn($v) => $v !== ''));
        if (!$kwList) {
            continue;
        }
        $zones[] = [
            'name' => mb_substr($name, 0, 100),
            'keywords' => array_slice($kwList, 0, 30),
            'fee' => max(0, (float) ($fees[$i] ?? 0)),
        ];
        if (count($zones) >= 20) {
            break;
        }
    }

    setSetting('shipping_default_fee', (string) $defaultFee);
    setSetting('shipping_zones', json_encode($zones, JSON_UNESCAPED_UNICODE));
    logActivity('SHIPPING_SETTINGS_SAVE', 'so_khu_vuc=' . count($zones));
    $saved = true;
}

$defaultFee = (float) getSetting('shipping_default_fee', '0');
$zones = json_decode(getSetting('shipping_zones', '[]'), true);
if (!is_array($zones)) {
    $zones = [];
}

require_once __DIR__ . '/inc_header.php';
?>

<a href="settings.php" class="muted" style="font-size:14px;">← Cấu hình</a>
<h1 style="font-size:24px;font-weight:600;margin:8px 0 4px;">Cấu hình giao hàng</h1>
<p class="muted" style="margin:0 0 20px;font-size:13px;max-width:720px;">
  Khai báo sẵn phí giao theo khu vực để không phải nhớ và gõ lại mỗi đơn. Khi nhân viên nhập địa chỉ
  giao hàng, hệ thống dò các từ khóa dưới đây trong địa chỉ và <b>tự điền phí</b> — vẫn sửa tay được
  nếu đơn đó khác thường.
</p>

<?php if ($saved): ?><div class="alert alert-success">Đã lưu cấu hình giao hàng.</div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<form method="post">
  <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">

  <div class="card" style="max-width:720px;margin-bottom:16px;">
    <div class="field" style="max-width:260px;">
      <label for="f-default">Phí giao mặc định</label>
      <input class="input" id="f-default" type="number" min="0" name="default_fee" value="<?= (float) $defaultFee ?>">
      <p class="muted" style="font-size:12px;margin:4px 0 0;">Dùng khi địa chỉ không khớp khu vực nào bên dưới.</p>
    </div>
  </div>

  <div class="card" style="padding:0;overflow-x:auto;max-width:900px;margin-bottom:16px;">
    <table>
      <thead>
        <tr>
          <th style="width:200px;">Tên khu vực</th>
          <th>Từ khóa nhận dạng trong địa chỉ (cách nhau bằng dấu phẩy)</th>
          <th class="text-right" style="width:140px;">Phí giao</th>
          <th style="width:60px;"></th>
        </tr>
      </thead>
      <tbody id="zones-body"></tbody>
    </table>
  </div>

  <div style="max-width:900px;display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;">
    <button type="button" id="add-zone" class="btn btn-secondary">+ Thêm khu vực</button>
    <button type="submit" class="btn">Lưu cấu hình</button>
  </div>
</form>

<script>
const zones = <?= json_encode($zones, JSON_UNESCAPED_UNICODE) ?>;
const body = document.getElementById('zones-body');

function esc(t) { return String(t ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

function row(z) {
  const tr = document.createElement('tr');
  tr.innerHTML = `
    <td><input class="input" name="zone_name[]" value="${esc(z.name || '')}" placeholder="vd: Nội thành"></td>
    <td><input class="input" name="zone_keywords[]" value="${esc((z.keywords || []).join(', '))}" placeholder="vd: Quận 1, Quận 3, Bình Thạnh"></td>
    <td><input class="input" type="number" min="0" name="zone_fee[]" value="${Number(z.fee) || 0}" style="text-align:right;"></td>
    <td class="text-right"><a href="#" class="remove" style="color:#ef4444;font-size:12px;">Xóa</a></td>`;
  tr.querySelector('.remove').addEventListener('click', (e) => { e.preventDefault(); tr.remove(); ensureRow(); });
  body.appendChild(tr);
}

function ensureRow() {
  if (!body.children.length) row({ name: '', keywords: [], fee: 0 });
}

zones.forEach(row);
ensureRow();
document.getElementById('add-zone').addEventListener('click', () => row({ name: '', keywords: [], fee: 0 }));
</script>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
