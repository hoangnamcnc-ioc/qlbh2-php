    </div>
  </div>
</div>
<button type="button" id="fbToggleBtn" title="Gửi góp ý / báo lỗi" style="position:fixed;right:20px;bottom:20px;z-index:999;width:52px;height:52px;border-radius:50%;background:var(--brand,#2563eb);color:#fff;border:none;font-size:22px;cursor:pointer;box-shadow:0 4px 12px rgba(0,0,0,.2);">💬</button>
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
  toggleBtn.addEventListener('click', function () { modal.style.display = 'flex'; alertBox.style.display = 'none'; });
  document.getElementById('fbCancelBtn').addEventListener('click', function () { modal.style.display = 'none'; });
  modal.addEventListener('click', function (e) { if (e.target === modal) modal.style.display = 'none'; });
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var submitBtn = form.querySelector('button[type=submit]');
    submitBtn.disabled = true;
    var data = new FormData(form);
    data.append('san_pham', 'QLBH-CLOUD');
    data.append('nguoi_gui', <?= json_encode($currentUser['full_name'] ?? $currentUser['email'] ?? '') ?>);
    fetch('https://kt-soft.vn/feedback_api.php', { method: 'POST', body: data })
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
