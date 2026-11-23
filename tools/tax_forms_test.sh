#!/usr/bin/env bash
# Kiem thu cac mau to khai thue (to_khai_thue.php) + so do nhom Thue tren app.kt-soft.vn voi cua hang thu co du lieu mau.
set -u
export PYTHONIOENCODING=utf-8
HOST="${FTP_HOST:-103.75.184.20}"; REMOTE_DIR="${FTP_DIR:-domains/app.kt-soft.vn}"; A="${SITE_URL:-https://app.kt-soft.vn}"
[ -z "${FTP_USERPASS:-}" ] && [ -f "$HOME/.qlbh_ftp" ] && FTP_USERPASS=$(head -n1 "$HOME/.qlbh_ftp" | tr -d "$(printf '\r\n')") && export FTP_USERPASS
: "${FTP_USERPASS:?Can FTP_USERPASS}"
cd "$(dirname "$0")/.." || exit 1
W=$(mktemp -d); KEY=$(python -c "import secrets;print(secrets.token_hex(16))")
ftp_put() { curl -s --ftp-create-dirs --user "$FTP_USERPASS" -T "$1" "ftp://$HOST/$REMOTE_DIR/$2"; }
ftp_del() { curl -s --user "$FTP_USERPASS" -Q "DELE $REMOTE_DIR/$1" "ftp://$HOST/" >/dev/null; }
sed "s/__KEY__/$KEY/" tools/test_probe.php > "$W/probe.php"; ftp_put "$W/probe.php" "_chk_probe.php"
probe() { curl -s "$A/_chk_probe.php?key=$KEY&$1"; }
trap 'probe "a=cleanup" >/dev/null; ftp_del "_chk_probe.php"; rm -rf "$W"' EXIT
tk() { curl -s -b "$2" -c "$2" "$1" | grep -o 'name="csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'; }
probe "a=cleanup" >/dev/null
AD="$W/ad"
curl -s -b $AD -c $AD -o /dev/null --form-string csrf="$(tk $A/dang-ky.php $AD)" --form-string accept_terms=1 --form-string demo_data=1 --form-string company_name="ZZTEST Reg" --form-string admin_name="Admin" --form-string email="zzreg_admin@example.com" --form-string password="Test1234" $A/dang-ky.php
ok=0; bad=0
chk() { # chk <ten> <url> <chuoi phai co>
  out=$(curl -s -b $AD -w '\n%{http_code}' "$A/$2"); code=${out##*$'\n'}
  if [ "$code" = 200 ] && ! echo "$out" | grep -qE 'Fatal error|Warning:|Notice:|Deprecated:|Parse error|Uncaught' && echo "$out" | grep -q "$3"; then ok=$((ok+1)); echo "  dat   $1"; else bad=$((bad+1)); echo "  HONG  $1 (http $code)"; echo "$out" | grep -E 'Fatal error|Warning:|Notice:|Uncaught' | head -3; fi
}
Y=$(date +%Y)
for m in tonghop sansang dubao 01tkn 01cnkd 02qtt giam43 thumua ddkd bkstk bkhtk; do chk "mau $m" "to_khai_thue.php?mau=$m&nam=$Y&quy=2" "Tờ khai thuế hộ kinh doanh"; done
chk "01cnkd co ma chi tieu" "to_khai_thue.php?mau=01cnkd&nam=$Y&quy=1" "Tổng cộng"
chk "02qtt co chi tieu 13" "to_khai_thue.php?mau=02qtt&nam=$Y" "[13]"
chk "in tờ khai" "to_khai_thue.php?mau=01cnkd&nam=$Y&quy=1&in=1" "CỘNG HÒA"
chk "so do nhom Thue" "nhom.php?g=Thu%E1%BA%BF" "Tờ khai quý 01/CNKD"
chk "menu co nhom Thue" "to_khai_thue.php" "Kê khai thuế"
code=$(curl -s -b $AD -o "$W/x.xlsx" -w '%{http_code}' "$A/to_khai_thue.php?mau=01cnkd&nam=$Y&quy=1&export=1"); size=$(stat -c %s "$W/x.xlsx" 2>/dev/null || echo 0)
if [ "$code" = 200 ] && [ "$size" -gt 1000 ]; then ok=$((ok+1)); echo "  dat   xuat Excel ($size byte)"; else bad=$((bad+1)); echo "  HONG  xuat Excel http $code size $size"; fi
echo "KET QUA: $ok dat, $bad hong"
[ $bad -eq 0 ]
