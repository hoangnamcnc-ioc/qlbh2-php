#!/usr/bin/env bash
# Chay di tru CSDL tap hoa tren app.kt-soft.vn: tai script tam (khoa ngau nhien), goi 1 lan, xoa ngay.
#   FTP_USERPASS='user:matkhau' tools/run_migrate_tap_hoa.sh
set -u
HOST="${FTP_HOST:-103.75.184.20}"
REMOTE_DIR="${FTP_DIR:-domains/app.kt-soft.vn}"
A="${SITE_URL:-https://app.kt-soft.vn}"
# Mat khau FTP: lay tu bien moi truong, neu chua co thi doc tu tep ~/.qlbh_ftp (1 dong user:matkhau) - khong can go mat khau vao cua so chat/lich su lenh
[ -z "${FTP_USERPASS:-}" ] && [ -f "$HOME/.qlbh_ftp" ] && FTP_USERPASS=$(head -n1 "$HOME/.qlbh_ftp" | tr -d "$(printf '\r\n')") && export FTP_USERPASS
: "${FTP_USERPASS:?Can dat FTP_USERPASS=user:matkhau}"
cd "$(dirname "$0")/.." || exit 1
KEY=$(python -c "import secrets;print(secrets.token_hex(16))")
W=$(mktemp -d); trap 'rm -rf "$W"' EXIT
sed "s/__KEY__/$KEY/" tools/migrate_tap_hoa.php > "$W/mig.php"
curl -s --user "$FTP_USERPASS" -T "$W/mig.php" "ftp://$HOST/$REMOTE_DIR/_chk_mig.php" || { echo "upload loi"; exit 1; }
curl -s "$A/_chk_mig.php?k=$KEY"
curl -s --user "$FTP_USERPASS" -Q "DELE $REMOTE_DIR/_chk_mig.php" "ftp://$HOST/" >/dev/null
echo "da xoa script tam: $(curl -s -o /dev/null -w '%{http_code}' "$A/_chk_mig.php?k=$KEY") (mong doi 404)"
