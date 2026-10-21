/* Service worker QLBH-CLOUD: bien app thanh "ung dung cai duoc" (PWA).
 * THAN TRONG: KHONG luu bat ky trang da dang nhap nao vao bo nho dem (tranh lo/loi thoi du lieu kinh doanh). Chi:
 *  - luu san trang bao mat ket noi (offline.html) + bieu tuong, de khi mat mang van hien thong bao dep thay vi loi trinh duyet;
 *  - anh trong /img/ lay tu bo nho dem truoc (ban hang khong bi cham vi tai lai logo).
 * Cac yeu cau khac (trang, API, thanh toan) di thang ra mang nhu binh thuong. Ban hang offline van do co che hang doi trong pos.php.
 */
const CACHE = 'qlbh-shell-v1';
const OFFLINE_URL = 'offline.html';
const PRECACHE = [OFFLINE_URL, 'img/pwa-192.png', 'img/pwa-512.png', 'img/logo.png'];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll(PRECACHE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;

  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).catch(() => caches.match(OFFLINE_URL)));
    return;
  }
  if (url.pathname.includes('/img/')) {
    e.respondWith(caches.match(req).then((hit) => hit || fetch(req)));
  }
});
