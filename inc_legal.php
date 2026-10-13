<?php
/**
 * Thong tin phap ly dung chung: ten don vi cung cap, lien he, phien ban dieu khoan, khung trang cong khai (khong can dang nhap).
 * DOI CAC HANG SO DUOI DAY neu doi ten phap nhan / thong tin lien he - ap dung cho tat ca trang dieu khoan, chinh sach, chan trang.
 */
const LEGAL_COMPANY = 'KT-SOFT';
const LEGAL_PRODUCT = 'QLBH-CLOUD';
const LEGAL_PHONE = '0945289666';
const LEGAL_EMAIL = 'hoangnamcnc@gmail.com';
const LEGAL_WEBSITE = 'https://kt-soft.vn';
const LEGAL_TERMS_VERSION = '2026-10-13';
const LEGAL_UPDATED = '13/10/2026';

/** Dong ban quyen + lien ket dieu khoan/bao mat (HTML, da escape) - dung o chan trang cac trang. */
function legalFooterHtml(string $style = ''): string
{
    return '<div class="legal-footer" style="font-size:12px;color:#94a3b8;text-align:center;padding:14px 8px;' . $style . '">'
        . '© ' . date('Y') . ' ' . htmlspecialchars(LEGAL_COMPANY) . '. Bảo lưu mọi quyền. · '
        . '<a href="dieu_khoan.php" style="color:inherit;text-decoration:underline;">Điều khoản sử dụng</a> · '
        . '<a href="chinh_sach_bao_mat.php" style="color:inherit;text-decoration:underline;">Chính sách bảo mật</a></div>';
}

function legalPageStart(string $title): void
{
    ?><!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($title) ?> - <?= htmlspecialchars(LEGAL_PRODUCT) ?></title>
<link rel="icon" type="image/png" sizes="32x32" href="img/logo-32.png">
<style>
  body { margin:0; background:#f1f5f9; color:#0f172a; font-family:-apple-system,Segoe UI,Roboto,sans-serif; line-height:1.65; }
  .wrap { max-width:820px; margin:0 auto; padding:24px 16px 40px; }
  .doc { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:28px 30px; }
  h1 { font-size:24px; margin:0 0 4px; }
  h2 { font-size:17px; margin:26px 0 6px; }
  p, li { font-size:14.5px; }
  .muted { color:#64748b; font-size:13px; }
  a { color:#2563eb; }
  .note { background:#fffbeb; border:1px solid #fde68a; color:#92400e; border-radius:10px; padding:10px 14px; font-size:13px; margin:14px 0; }
  @media (max-width:560px) { .doc { padding:18px 16px; } }
</style>
</head>
<body>
<div class="wrap">
  <p style="margin:0 0 12px;"><a href="javascript:history.length > 1 ? history.back() : (location.href = 'login.php')">← Quay lại</a></p>
  <div class="doc">
<?php
}

function legalPageEnd(): void
{
    ?>
  </div>
  <?= legalFooterHtml() ?>
</div>
</body>
</html>
<?php
}
