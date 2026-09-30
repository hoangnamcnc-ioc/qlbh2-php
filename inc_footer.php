    </div>
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
</body>
</html>
