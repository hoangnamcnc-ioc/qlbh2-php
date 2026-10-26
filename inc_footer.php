    </div>
<?php require_once __DIR__ . '/inc_legal.php'; echo legalFooterHtml(); ?>
  </div>
</div>
<div id="qlbhLoadingBar" style="position:fixed;top:0;left:0;height:3px;width:0;background:var(--brand,#2563eb);z-index:9999;transition:width .3s ease;opacity:0;"></div>
<script>
// Thanh loading mong o dau trang khi dang tai trang danh sach lon (chuyen trang/loc/phan
// trang) - truoc day khong co gi bao hieu dang xu ly, trang trang mot luc khien tuong nham
// bi treo. Chi ap dung cho dieu huong THUONG (submit form, click link cung site, khong phai
// nut mo tab moi/tai lieu tai ve) - trinh duyet se tu chuyen trang binh thuong, thanh nay chi
// de bao hieu ngay lap tuc, khong can cho JS xu ly gi them.
(function () {
  var bar = document.getElementById('qlbhLoadingBar');
  function batDauLoad() {
    bar.style.opacity = '1';
    bar.style.width = '0%';
    requestAnimationFrame(function () { bar.style.width = '80%'; });
  }
  document.addEventListener('submit', function (e) {
    if (e.target.tagName === 'FORM' && !e.defaultPrevented) batDauLoad();
  }, true);
  document.addEventListener('click', function (e) {
    var a = e.target.closest('a[href]');
    if (!a || a.target === '_blank' || a.hasAttribute('download')) return;
    var href = a.getAttribute('href');
    if (!href || href.startsWith('#') || href.startsWith('javascript:')) return;
    if (e.defaultPrevented || e.ctrlKey || e.metaKey || e.shiftKey) return;
    batDauLoad();
  }, true);
})();
</script>
<div id="fbModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.5);z-index:1000;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:12px;padding:24px;width:420px;max-width:92vw;max-height:90vh;overflow:auto;">
    <h3 style="margin:0 0 4px;font-size:17px;">Góp ý / Báo lỗi</h3>
    <p style="margin:0 0 16px;color:#64748b;font-size:13px;">Phản hồi của bạn được gửi thẳng tới đội ngũ phát triển QLBH-CLOUD.</p>
    <div id="fbAlert" style="display:none;margin-bottom:12px;"></div>
    <form id="fbForm">
      <input type="text" name="website" style="position:absolute;left:-9999px;" tabindex="-1" autocomplete="off">
      <div class="field">
        <label>Loại phản hồi</label>
        <select name="loai" class="input" required>
          <option value="bao_loi">Báo lỗi</option>
          <option value="gop_y" selected>Góp ý</option>
          <option value="khac">Khác</option>
        </select>
      </div>
      <div class="field">
        <label>Nội dung</label>
        <textarea name="noi_dung" class="input" rows="5" required minlength="5" placeholder="Mô tả chi tiết vấn đề hoặc góp ý của bạn..."></textarea>
      </div>
      <div class="field">
        <label>Số điện thoại/Zalo để liên hệ lại (không bắt buộc)</label>
        <input type="text" name="lien_he" class="input">
      </div>
      <div class="field">
        <label>Ảnh chụp màn hình lỗi (không bắt buộc, tối đa 5MB)</label>
        <input type="file" id="fbAnhInput" class="input" accept="image/png,image/jpeg,image/webp,image/gif">
      </div>
      <div style="display:flex;gap:8px;justify-content:flex-end;">
        <button type="button" id="fbCancelBtn" class="btn btn-secondary">Đóng</button>
        <button type="submit" class="btn">Gửi phản hồi</button>
      </div>
    </form>
  </div>
</div>
<script>
(function () {
  var toggleBtn = document.getElementById('fbToggleBtn');
  var modal = document.getElementById('fbModal');
  var form = document.getElementById('fbForm');
  var alertBox = document.getElementById('fbAlert');
  toggleBtn.addEventListener('click', function (e) { e.preventDefault(); modal.style.display = 'flex'; alertBox.style.display = 'none'; });
  document.getElementById('fbCancelBtn').addEventListener('click', function () { modal.style.display = 'none'; });
  modal.addEventListener('click', function (e) { if (e.target === modal) modal.style.display = 'none'; });
  // Gui anh dinh kem duoi dang chuoi base64 trong 1 truong text thuong (KHONG dinh kem nhu 1
  // file nhi phan that trong multipart) - may chu kt-soft.vn co WAF chan mot so request
  // multipart/form-data co kem file nhi phan toi feedback_api.php (tra ve 403 ngay tu web
  // server), trong khi POST binh thuong (ke ca chua chuoi base64 rat dai) van di qua binh
  // thuong. Vi vay input file o tren KHONG co thuoc tinh "name" - de FormData(form) tu dong bo
  // qua no, tranh vo tinh dinh kem file nhi phan that.
  function docFileThanhBase64(file) {
    return new Promise(function (resolve, reject) {
      var reader = new FileReader();
      reader.onload = function () { resolve(reader.result); };
      reader.onerror = reject;
      reader.readAsDataURL(file);
    });
  }
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var submitBtn = form.querySelector('button[type=submit]');
    var anhInput = document.getElementById('fbAnhInput');
    var anhFile = anhInput.files[0];
    if (anhFile && anhFile.size > 5 * 1024 * 1024) {
      alertBox.style.display = 'block';
      alertBox.className = 'alert alert-error';
      alertBox.textContent = 'Ảnh đính kèm vượt quá 5MB, vui lòng chọn ảnh nhỏ hơn.';
      return;
    }
    submitBtn.disabled = true;
    var guiDi = anhFile ? docFileThanhBase64(anhFile) : Promise.resolve(null);
    guiDi.then(function (anhBase64) {
      var data = new FormData(form);
      data.append('san_pham', 'QLBH-CLOUD');
      data.append('nguoi_gui', <?= json_encode($currentUser['full_name'] ?? $currentUser['email'] ?? '') ?>);
      if (anhBase64) data.append('anh_base64', anhBase64);
      return fetch('https://kt-soft.vn/feedback_api.php', { method: 'POST', body: data });
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        submitBtn.disabled = false;
        alertBox.style.display = 'block';
        if (res.ok) {
          alertBox.className = 'alert alert-success';
          alertBox.textContent = 'Đã gửi, cảm ơn bạn! Chúng tôi sẽ xem xét sớm.';
          form.reset();
          setTimeout(function () { modal.style.display = 'none'; }, 1500);
        } else {
          alertBox.className = 'alert alert-error';
          alertBox.textContent = res.error || 'Có lỗi xảy ra, vui lòng thử lại.';
        }
      })
      .catch(function () {
        submitBtn.disabled = false;
        alertBox.style.display = 'block';
        alertBox.className = 'alert alert-error';
        alertBox.textContent = 'Không kết nối được, vui lòng kiểm tra mạng và thử lại.';
      });
  });
})();
</script>
<?php
// Tim nhanh (Ctrl+K): danh sach trang menu nguoi dung duoc phep vao (da loc theo vai tro o inc_header) + tim san pham/khach/don/NCC qua tim_nhanh.php
$qsNav = [];
foreach (($navGroups ?? []) as $qsG => $qsItems) {
    foreach ($qsItems as $qsFile => $qsLabel) {
        $qsNav[] = ['t' => $qsLabel, 'u' => $qsFile, 'g' => $qsG];
    }
}
?>
<div id="qs-modal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:1100;align-items:flex-start;justify-content:center;padding-top:12vh;">
  <div style="background:#fff;border-radius:12px;width:min(560px,94vw);box-shadow:0 20px 50px rgba(0,0,0,.3);overflow:hidden;">
    <input id="qs-input" type="text" placeholder="Tìm trang, sản phẩm, khách hàng, mã đơn, nhà cung cấp…  (Esc để đóng)" autocomplete="off"
           style="width:100%;box-sizing:border-box;border:0;border-bottom:1px solid #e2e8f0;padding:14px 16px;font-size:15px;outline:none;">
    <div id="qs-list" style="max-height:52vh;overflow-y:auto;"></div>
  </div>
</div>
<button id="qs-open" type="button" title="Tìm nhanh (Ctrl+K)" style="position:fixed;right:12px;bottom:12px;z-index:50;padding:8px 14px;border:1px solid #cbd5e1;border-radius:999px;background:#fff;color:#334155;font-size:13px;cursor:pointer;box-shadow:0 2px 8px rgba(0,0,0,.12);">🔎 Tìm nhanh <span style="color:#94a3b8;">Ctrl+K</span></button>
<script>
(function () {
  const NAV = <?= json_encode($qsNav, JSON_UNESCAPED_UNICODE) ?>;
  const modal = document.getElementById('qs-modal'), input = document.getElementById('qs-input'), list = document.getElementById('qs-list'), openBtn = document.getElementById('qs-open');
  if (!modal || !input || !list) return;
  if (/\/pos\.php$/.test(location.pathname)) openBtn.style.display = 'none';   // man hinh ban hang co thanh thanh toan o day; van dung duoc Ctrl+K
  let items = [], sel = 0, timer = null;
  const norm = s => (s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/đ/g, 'd');
  function pagesFor(q) {
    const n = norm(q);
    return NAV.filter(p => !n || norm(p.t).includes(n) || norm(p.u).includes(n)).slice(0, 8).map(p => ({ icon: '📄', label: p.t, sub: p.g, url: p.u }));
  }
  function render(arr) {
    items = arr; sel = 0;
    list.textContent = '';
    if (!arr.length) { const d = document.createElement('div'); d.style.cssText = 'padding:14px 16px;color:#64748b;font-size:14px;'; d.textContent = 'Không tìm thấy kết quả.'; list.appendChild(d); return; }
    arr.forEach((it, i) => {
      const row = document.createElement('a');
      row.href = it.url;
      row.dataset.i = i;
      row.style.cssText = 'display:flex;gap:10px;align-items:baseline;padding:9px 16px;text-decoration:none;color:#0f172a;font-size:14px;cursor:pointer;' + (i === 0 ? 'background:#eff6ff;' : '');
      const a = document.createElement('span'); a.textContent = it.icon;
      const b = document.createElement('span'); b.textContent = it.label; b.style.fontWeight = '600';
      const c = document.createElement('span'); c.textContent = it.sub || ''; c.style.cssText = 'color:#64748b;font-size:12px;margin-left:auto;';
      row.append(a, b, c);
      row.addEventListener('mouseenter', () => highlight(i));
      list.appendChild(row);
    });
  }
  function highlight(i) {
    sel = i;
    list.querySelectorAll('a').forEach((r, k) => { r.style.background = k === i ? '#eff6ff' : ''; });
    const cur = list.querySelectorAll('a')[i]; if (cur) cur.scrollIntoView({ block: 'nearest' });
  }
  function open() { modal.style.display = 'flex'; input.value = ''; render(pagesFor('')); input.focus(); }
  function close() { modal.style.display = 'none'; }
  openBtn.addEventListener('click', open);
  modal.addEventListener('mousedown', e => { if (e.target === modal) close(); });
  document.addEventListener('keydown', e => {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); modal.style.display === 'flex' ? close() : open(); return; }
    if (modal.style.display !== 'flex') return;
    if (e.key === 'Escape') { e.preventDefault(); close(); }
    else if (e.key === 'ArrowDown') { e.preventDefault(); if (items.length) highlight((sel + 1) % items.length); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); if (items.length) highlight((sel - 1 + items.length) % items.length); }
    else if (e.key === 'Enter') { e.preventDefault(); if (items[sel]) location.href = items[sel].url; }
  });
  input.addEventListener('input', () => {
    const q = input.value.trim();
    const pages = pagesFor(q);
    render(pages);
    clearTimeout(timer);
    if (q.length < 2) return;
    timer = setTimeout(() => {
      fetch('tim_nhanh.php?q=' + encodeURIComponent(q)).then(r => r.json()).then(d => {
        if (input.value.trim() !== q) return;
        const extra = [];
        (d.products || []).forEach(x => extra.push({ icon: '📦', label: x.label, sub: x.sub, url: x.url }));
        (d.customers || []).forEach(x => extra.push({ icon: '👤', label: x.label, sub: x.sub, url: x.url }));
        (d.orders || []).forEach(x => extra.push({ icon: '🧾', label: x.label, sub: x.sub, url: x.url }));
        (d.suppliers || []).forEach(x => extra.push({ icon: '🚚', label: x.label, sub: x.sub, url: x.url }));
        render(pages.concat(extra));
      }).catch(() => {});
    }, 220);
  });
})();
</script>
<button id="pwa-install" type="button" style="display:none;position:fixed;left:12px;bottom:12px;z-index:50;padding:8px 14px;border:0;border-radius:999px;background:#2563eb;color:#fff;font-size:13px;font-weight:600;box-shadow:0 4px 12px rgba(0,0,0,.2);cursor:pointer;">📲 Cài ứng dụng</button>
<script>
// PWA: dang ky service worker (chi giu trang bao mat mang + anh, khong luu trang da dang nhap) va nut "Cai ung dung" khi trinh duyet cho phep
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function () { navigator.serviceWorker.register('sw.js').catch(function () {}); });
}
(function () {
  var deferred = null, btn = document.getElementById('pwa-install');
  if (!btn || /\/pos\.php$/.test(location.pathname)) return;   // man hinh ban hang co thanh thanh toan o day: khong chen nut
  window.addEventListener('beforeinstallprompt', function (e) { e.preventDefault(); deferred = e; btn.style.display = 'block'; });
  btn.addEventListener('click', function () {
    if (!deferred) return;
    deferred.prompt();
    deferred.userChoice.finally(function () { deferred = null; btn.style.display = 'none'; });
  });
  window.addEventListener('appinstalled', function () { btn.style.display = 'none'; });
})();
</script>
</body>
</html>
