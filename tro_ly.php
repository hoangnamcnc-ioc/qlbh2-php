<?php
/**
 * Tro ly hoi dap tieng Viet ve so lieu cua hang ("hom nay ban bao nhieu", "mat hang nao ban chay"...).
 * Hieu cau hoi bang tu khoa va tinh so NGAY TREN DU LIEU CUA CUA HANG - khong gui cau hoi hay du lieu ra dich vu ben ngoai.
 */
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
require_once __DIR__ . '/inc_thong_minh.php';
$currentUser = requireRole('ADMIN', 'MANAGER');

$pdo = db();
$tenantId = currentTenantId();
$branchId = hasRole('ADMIN') ? 0 : effectiveBranchId($currentUser);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string) ($input['csrf'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['error' => 'Phiên làm việc không hợp lệ, vui lòng tải lại trang.']);
        exit;
    }
    $question = mb_substr(trim((string) ($input['question'] ?? '')), 0, 300);
    if ($question === '') {
        echo json_encode(['error' => 'Hãy nhập câu hỏi.']);
        exit;
    }
    try {
        $r = traLoiCauHoi($pdo, $tenantId, $branchId, $question);
        logActivity('ASSISTANT_ASK', 'intent=' . $r['intent']);
        echo json_encode($r, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $ex) {
        error_log('tro_ly: ' . $ex->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Không trả lời được câu hỏi này, vui lòng thử lại.']);
    }
    exit;
}

require_once __DIR__ . '/inc_header.php';
$samples = ['Hôm nay bán được bao nhiêu?', 'Doanh thu tuần này', 'Mặt hàng nào bán chạy tháng này?', 'Hàng nào lãi nhất tháng này?',
    'Hàng nào sắp hết, cần nhập?', 'Hàng nào cận date?', 'Khách nào đang nợ?', 'Nợ nhà cung cấp?', 'Hàng nào tồn lâu?', 'Có gì bất thường không?'];
?>

<h1 style="font-size:24px;font-weight:600;margin:0 0 8px;">Trợ lý hỏi đáp</h1>
<p class="muted" style="margin:0 0 16px;font-size:13px;max-width:700px;">
  Hỏi bằng tiếng Việt về số liệu cửa hàng<?= $branchId ? ' (chi nhánh của bạn)' : '' ?>. Câu trả lời được tính ngay từ dữ liệu của bạn,
  không gửi ra dịch vụ bên ngoài.
</p>

<div class="card" style="max-width:760px;">
  <div id="chat" style="min-height:220px;max-height:55vh;overflow-y:auto;display:flex;flex-direction:column;gap:10px;margin-bottom:12px;">
    <div class="bot" style="background:#f1f5f9;padding:10px 12px;border-radius:10px;max-width:90%;font-size:14px;">Xin chào! Bạn muốn xem số liệu gì? Chọn câu gợi ý bên dưới hoặc tự gõ câu hỏi.</div>
  </div>
  <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px;">
    <?php foreach ($samples as $sm): ?><button type="button" class="btn btn-secondary chip" style="padding:3px 10px;font-size:12px;"><?= e($sm) ?></button><?php endforeach; ?>
  </div>
  <form id="ask-form" style="display:flex;gap:8px;">
    <input id="q" class="input" maxlength="300" placeholder="Ví dụ: hôm nay bán được bao nhiêu?" autocomplete="off" style="flex:1;">
    <button class="btn" type="submit" id="send">Hỏi</button>
  </form>
</div>

<script>
const CSRF = <?= json_encode(csrfToken()) ?>;
const chat = document.getElementById('chat');
function bubble(cls, node) {
  const d = document.createElement('div');
  d.className = cls;
  d.style.cssText = 'padding:10px 12px;border-radius:10px;max-width:90%;font-size:14px;' +
    (cls === 'me' ? 'background:#2563eb;color:#fff;align-self:flex-end;' : 'background:#f1f5f9;');
  d.appendChild(node);
  chat.appendChild(d);
  chat.scrollTop = chat.scrollHeight;
}
function textNode(lines) {
  const wrap = document.createElement('div');
  lines.forEach(l => { const p = document.createElement('div'); p.textContent = l; wrap.appendChild(p); });
  return wrap;
}
function ask(question) {
  question = question.trim();
  if (!question) return;
  bubble('me', textNode([question]));
  document.getElementById('q').value = '';
  const btn = document.getElementById('send');
  btn.disabled = true;
  fetch('tro_ly.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ csrf: CSRF, question }) })
    .then(r => r.json())
    .then(d => {
      btn.disabled = false;
      const wrap = textNode(d.error ? [d.error] : d.answer);
      (d.links || []).forEach(([label, url]) => {
        const a = document.createElement('a');
        a.href = url; a.textContent = label + ' →';
        a.style.cssText = 'display:inline-block;margin:6px 10px 0 0;font-size:13px;';
        wrap.appendChild(a);
      });
      bubble('bot', wrap);
    })
    .catch(() => { btn.disabled = false; bubble('bot', textNode(['Lỗi kết nối, vui lòng thử lại.'])); });
}
document.getElementById('ask-form').addEventListener('submit', e => { e.preventDefault(); ask(document.getElementById('q').value); });
document.querySelectorAll('.chip').forEach(c => c.addEventListener('click', () => ask(c.textContent)));
</script>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
