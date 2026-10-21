#!/usr/bin/env bash
# Kiem thu MA TRAN VAI TRO tren app.kt-soft.vn:
#   FTP_USERPASS='user:matkhau' tools/roles_test.sh
# Tao cua hang thu 'ZZTEST Reg' co ADMIN, MANAGER, CASHIER va 4 vai tro tuy chinh (chi san pham / chi tai chinh /
# chi nhan su / chi marketing), dang nhap tung vai tro, mo MOI trang va doi chieu voi quyen ky vong (xem roles_matrix.py).
# Sau do xoa cua hang thu. Thoat 0 neu khong lech quyen va khong loi.
set -u
export PYTHONIOENCODING=utf-8
HOST="${FTP_HOST:-103.75.184.20}"; REMOTE_DIR="${FTP_DIR:-domains/app.kt-soft.vn}"; A="${SITE_URL:-https://app.kt-soft.vn}"
# Mat khau FTP: lay tu bien moi truong, neu chua co thi doc tu tep ~/.qlbh_ftp (1 dong user:matkhau) - khong can go mat khau vao cua so chat/lich su lenh
[ -z "${FTP_USERPASS:-}" ] && [ -f "$HOME/.qlbh_ftp" ] && FTP_USERPASS=$(head -n1 "$HOME/.qlbh_ftp" | tr -d '
') && export FTP_USERPASS
: "${FTP_USERPASS:?Can dat bien moi truong FTP_USERPASS=user:matkhau}"
cd "$(dirname "$0")/.." || exit 1
W=$(mktemp -d); KEY=$(python -c "import secrets;print(secrets.token_hex(16))")
ftp_put() { curl -s --ftp-create-dirs --user "$FTP_USERPASS" -T "$1" "ftp://$HOST/$REMOTE_DIR/$2"; }
ftp_del() { curl -s --user "$FTP_USERPASS" -Q "DELE $REMOTE_DIR/$1" "ftp://$HOST/" >/dev/null; }
sed "s/__KEY__/$KEY/" tools/test_probe.php > "$W/probe.php"; ftp_put "$W/probe.php" "_chk_probe.php"
probe() { curl -s "$A/_chk_probe.php?key=$KEY&$1"; }
trap 'probe "a=cleanup" >/dev/null; ftp_del "_chk_probe.php"; rm -rf "$W"' EXIT
tk() { curl -s -b "$2" -c "$2" "$1" | grep -o 'name="csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'; }

probe "a=cleanup" >/dev/null
mkdir -p "$W/ck"; AD="$W/ck/admin"
curl -s -b $AD -c $AD -o /dev/null --form-string csrf="$(tk $A/dang-ky.php $AD)" --form-string accept_terms=1 --form-string company_name="ZZTEST Reg" --form-string admin_name="Admin" --form-string email="zzreg_admin@example.com" --form-string password="Test1234" $A/dang-ky.php
BR=$(curl -s -b $AD $A/stock_take_form.php | grep -o 'option value="[0-9]*" selected' | grep -o '[0-9]*' | head -1)
[ -n "$BR" ] || { echo "Khong tao duoc cua hang thu - dung"; exit 1; }
T=$(tk $A/users.php $AD)
for spec in "products:products" "finance:finance" "hr:hr" "marketing:marketing"; do
  n=${spec%%:*}; g=${spec##*:}
  curl -s -b $AD -c $AD -o /dev/null --data-urlencode csrf="$T" -d "action=create&name=VT_$n&permissions[]=$g" $A/custom_roles.php
done
USERS=$(curl -s -b $AD $A/users.php)
mk() { # mk <ten dang nhap> <role>
  curl -s -b $AD -c $AD -o /dev/null --data-urlencode csrf="$T" -d "action=create&name=$1&email=zzreg_$1@example.com&password=Test1234&role=$2&branch_id=$BR" $A/users.php; }
mk manager MANAGER; mk cashier CASHIER
for n in products finance hr marketing; do
  rid=$(curl -s -b $AD $A/users.php | python -c "
import re,sys
h=sys.stdin.read()
m=re.search(r'value=\"(custom_\d+)\"[^>]*>\s*VT_$n\b', h)
print(m.group(1) if m else '')")
  [ -n "$rid" ] || { echo "Khong tim thay vai tro tuy chinh VT_$n"; exit 1; }
  mk c_$n "$rid"
done
for n in manager cashier c_products c_finance c_hr c_marketing; do
  LT=$(tk $A/login.php $W/ck/$n)
  curl -s -b $W/ck/$n -c $W/ck/$n -o /dev/null --form-string csrf="$LT" --form-string email=zzreg_$n@example.com --form-string password=Test1234 $A/login.php
done
python tools/roles_matrix.py "$A" "$W/ck" "$(pwd)"
