#!/usr/bin/env bash
# Kiem thu hoi quy QLBH-CLOUD tren app.kt-soft.vn bang 1 lenh:
#   FTP_USERPASS='user:matkhau' tools/regression_test.sh
# Tao cua hang thu tam 'ZZTEST Reg', chay lai cac kich ban nghiep vu da kiem tay (ban hang, huy don,
# tra hang co chiet khau, chong bam dup, ca ban hang, han muc tra hang, vai tro chat, cham cong,
# shop online, doi chieu so lieu), so sanh voi so mong doi, roi XOA cua hang thu va file tam.
# Thoat 0 neu tat ca dat, 1 neu co kich ban hong.
set -u
export PYTHONIOENCODING=utf-8
HOST="${FTP_HOST:-103.75.184.20}"
REMOTE_DIR="${FTP_DIR:-domains/app.kt-soft.vn}"
A="${SITE_URL:-https://app.kt-soft.vn}"
: "${FTP_USERPASS:?Can dat bien moi truong FTP_USERPASS=user:matkhau}"
cd "$(dirname "$0")/.." || exit 1
W=$(mktemp -d); trap 'rm -rf "$W"' EXIT
KEY=$(python -c "import secrets;print(secrets.token_hex(16))")
PASS=0; FAILN=0
ok()   { PASS=$((PASS+1)); echo "  dat   $1"; }
fail() { FAILN=$((FAILN+1)); echo "  HONG  $1  (mong doi: $2 | thuc te: $3)"; }
eq()   { [ "$2" = "$3" ] && ok "$1" || fail "$1" "$2" "$3"; }
tk()   { curl -s -b "$2" -c "$2" "$1" | grep -o 'name="csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'; }
jv()   { python -c "import sys,json;d=json.load(sys.stdin);print(eval('d'+sys.argv[1]))" "$1" 2>/dev/null; }

ftp_put() { curl -s --ftp-create-dirs --user "$FTP_USERPASS" -T "$1" "ftp://$HOST/$REMOTE_DIR/$2"; }
ftp_del() { curl -s --user "$FTP_USERPASS" -Q "DELE $REMOTE_DIR/$1" "ftp://$HOST/" >/dev/null; }
sed "s/__KEY__/$KEY/" tools/test_probe.php > "$W/probe.php"
ftp_put "$W/probe.php" "_chk_probe.php"
probe() { curl -s "$A/_chk_probe.php?key=$KEY&$1"; }
finish() { probe "a=cleanup" >/dev/null; ftp_del "_chk_probe.php"; }
trap 'finish; rm -rf "$W"' EXIT

echo "== Chuan bi cua hang thu"
probe "a=cleanup" >/dev/null   # xoa du lieu thu cua lan chay truoc neu con sot
CJ="$W/cj_a"
curl -s -b $CJ -c $CJ -o /dev/null --form-string csrf="$(tk $A/dang-ky.php $CJ)" --form-string company_name="ZZTEST Reg" --form-string admin_name="ZZ" --form-string email="zzreg_admin@example.com" --form-string password=Test1234 $A/dang-ky.php
T=$(tk $A/product_form.php $CJ)
curl -s -b $CJ -c $CJ -o /dev/null --form-string csrf="$T" --form-string name="SP Reg" --form-string sku=REG1 --form-string unit=cai --form-string product_type=NORMAL --form-string cost_price=60000 --form-string sell_price=100000 --form-string initial_qty=20 --form-string min_stock=0 $A/product_form.php
PID=$(curl -s -b $CJ "$A/pos_search.php?q=REG1" | python -c "import sys,json;print(json.load(sys.stdin)[0]['id'])" 2>/dev/null)
[ -n "$PID" ] || { echo "Khong tao duoc cua hang/san pham thu - dung"; exit 1; }
stock() { curl -s -b $CJ "$A/pos_search.php?q=REG1" | python -c "import sys,json;print(int(float(json.load(sys.stdin)[0]['qty'])))" 2>/dev/null; }
PT=$(curl -s -b $CJ -c $CJ $A/pos.php | grep -o 'csrfToken = "[^"]*"' | head -1 | sed 's/.*= "//;s/"//')
checkout() { curl -s -b $CJ -c $CJ -H 'Content-Type: application/json' -d "$1" $A/pos_checkout.php; }
BASE="\"csrf\":\"$PT\",\"payment_method\":\"CASH\",\"customer_phone\":\"0911000111\",\"customer_name\":\"Khach Reg\""
ITEM2="\"items\":[{\"product_id\":$PID,\"variant_id\":null,\"quantity\":2,\"unit_price\":100000}]"

echo "== 1. Ban hang tra mot phan + chong bam dup"
R1=$(checkout "{$BASE,$ITEM2,\"idem_key\":\"reg-1\",\"paid_amount\":120000}")
R1b=$(checkout "{$BASE,$ITEM2,\"idem_key\":\"reg-1\",\"paid_amount\":120000}")
O1=$(echo "$R1" | jv "['order_id']"); eq "gui 2 lan cung khoa chi tao 1 don" "$O1" "$(echo "$R1b" | jv "['order_id']")"
CID=$(curl -s -b $CJ "$A/customers.php?q=0911000111" | grep -o 'customer_view.php?id=[0-9]*' | head -1 | grep -o '[0-9]*$')
S=$(probe "a=state&c=$CID&o=$O1")
eq "cong no sau khi ban = 80000" "80000" "$(python -c "print(int(float($(echo "$S" | jv "['customer']['debt']"))))")"
eq "diem tich luy = 20" "20" "$(echo "$S" | jv "['customer']['loyalty_points']")"
eq "ton kho = 18" "18" "$(stock)"
NEG=$(checkout "{$BASE,\"items\":[{\"product_id\":$PID,\"variant_id\":null,\"quantity\":1,\"unit_price\":-5}]}" | jv "['error']")
[ -n "$NEG" ] && ok "tu choi don gia am" || fail "tu choi don gia am" "co loi" "khong loi"

echo "== 2. Huy don hoan dung so quy / cong no / diem / kho"
TO=$(tk "$A/order_view.php?id=$O1" $CJ)
curl -s -b $CJ -c $CJ -o /dev/null -d "csrf=$TO&order_id=$O1&reason=__OTHER__&reason_other=reg" $A/order_cancel.php
S=$(probe "a=state&c=$CID&o=$O1")
eq "don chuyen CANCELLED" "CANCELLED" "$(echo "$S" | jv "['order']['status']")"
eq "so quy rong cua don = 0" "0" "$(python -c "print(int(float($(echo "$S" | jv "['cash_net']"))))")"
eq "cong no ve 0" "0" "$(python -c "print(int(float($(echo "$S" | jv "['customer']['debt']"))))")"
eq "diem ve 0" "0" "$(echo "$S" | jv "['customer']['loyalty_points']")"
eq "ton kho ve 20" "20" "$(stock)"

echo "== 3. Tra hang don co chiet khau"
R3=$(checkout "{$BASE,$ITEM2,\"manual_discount_type\":\"AMOUNT\",\"manual_discount_value\":20000,\"paid_amount\":100000}")
O3=$(echo "$R3" | jv "['order_id']"); C3=$(echo "$R3" | jv "['code']")
IT=$(curl -s -b $CJ "$A/order_return_form.php?q=$C3" | grep -o 'qty\[[0-9]*\]' | head -1 | grep -o '[0-9]*')
curl -s -b $CJ -c $CJ -o /dev/null --data-urlencode "csrf=$PT" -d "order_id=$O3&qty%5B$IT%5D=1&reason=reg" $A/order_return_form.php
S=$(probe "a=state&c=$CID&o=$O3")
eq "tra 1 cai: so quy rong = 90000 (100000 thu - 10000 hoan)" "90000" "$(python -c "print(int(float($(echo "$S" | jv "['cash_net']"))))")"
eq "cong no 80000 duoc tru het" "0" "$(python -c "print(int(float($(echo "$S" | jv "['customer']['debt']"))))")"
eq "diem = 18 - 9 = 9" "9" "$(echo "$S" | jv "['customer']['loyalty_points']")"
eq "ton kho = 19" "19" "$(stock)"

echo "== 4. Ca ban hang"
TS=$(tk $A/ca_ban_hang.php $CJ)
curl -s -b $CJ -c $CJ -o /dev/null --data-urlencode csrf="$TS" -d "action=open&opening_cash=100000" $A/ca_ban_hang.php
SEC=$(curl -s -b $CJ -c $CJ -o /dev/null -w "%{http_code}" --data-urlencode csrf="$TS" -d "action=open&opening_cash=100000" $A/ca_ban_hang.php)
eq "mo ca lan 2 bi tu choi (trang bao loi, khong redirect)" "200" "$SEC"
SH=$(curl -s -b $CJ $A/ca_ban_hang.php | grep -o 'name="shift_id" value="[0-9]*"' | head -1 | grep -o '[0-9]*')
C1=$(curl -s -b $CJ -c $CJ -o /dev/null -w "%{http_code}" --data-urlencode csrf="$TS" -d "action=close&shift_id=$SH&counted_cash=100000" $A/ca_ban_hang.php)
C2=$(curl -s -b $CJ -c $CJ -o /dev/null -w "%{http_code}" --data-urlencode csrf="$TS" -d "action=close&shift_id=$SH&counted_cash=100000" $A/ca_ban_hang.php)
eq "dong ca lan 1 thanh cong (302)" "302" "$C1"; eq "dong ca lan 2 bi tu choi (200)" "200" "$C2"

echo "== 5. Phan quyen: thu ngan, han muc tra hang, vai tro chat, cham cong"
BR=$(curl -s -b $CJ $A/stock_take_form.php | grep -o 'option value="[0-9]*" selected' | grep -o '[0-9]*' | head -1)
T=$(tk $A/users.php $CJ)
curl -s -b $CJ -c $CJ -o /dev/null --data-urlencode csrf="$T" -d "action=create&name=KhoChat&permissions[]=products&permissions[]=strict" $A/custom_roles.php
CR=$(curl -s -b $CJ $A/users.php | grep -o 'value="custom_[0-9]*"' | sort -u | grep -o 'custom_[0-9]*' | head -1)
for U in "cash CASHIER" "chat $CR"; do set -- $U; curl -s -b $CJ -c $CJ -o /dev/null --data-urlencode csrf="$T" -d "action=create&name=$1&email=zzreg_$1@example.com&password=Test1234&role=$2&branch_id=$BR" $A/users.php; done
for U in cash chat; do LT=$(tk $A/login.php $W/cj_$U); curl -s -b $W/cj_$U -c $W/cj_$U -o /dev/null --form-string csrf="$LT" --form-string email=zzreg_$U@example.com --form-string password=Test1234 $A/login.php; done
eq "thu ngan khong vao duoc bao cao (403)" "403" "$(curl -s -b $W/cj_cash -o /dev/null -w '%{http_code}' $A/reports.php)"
echo -n ""; eq "thu ngan khong thay cot Gia von" "0" "$(curl -s -b $W/cj_cash $A/products.php | grep -c 'Giá vốn')"
eq "quan tri thay cot Gia von" "1" "$(curl -s -b $CJ $A/products.php | grep -c 'Giá vốn')"
eq "vai tro chat khong vao duoc POS (403)" "403" "$(curl -s -b $W/cj_chat -o /dev/null -w '%{http_code}' $A/pos.php)"
eq "vai tro chat van vao duoc san pham (200)" "200" "$(curl -s -b $W/cj_chat -o /dev/null -w '%{http_code}' $A/products.php)"
PTC=$(curl -s -b $W/cj_cash -c $W/cj_cash $A/pos.php | grep -o 'csrfToken = "[^"]*"' | head -1 | sed 's/.*= "//;s/"//')
R5=$(curl -s -b $W/cj_cash -H 'Content-Type: application/json' -d "{\"csrf\":\"$PTC\",\"payment_method\":\"CASH\",\"items\":[{\"product_id\":$PID,\"variant_id\":null,\"quantity\":6,\"unit_price\":100000}]}" $A/pos_checkout.php)
O5=$(echo "$R5" | jv "['order_id']"); C5=$(echo "$R5" | jv "['code']")
IT5=$(curl -s -b $W/cj_cash "$A/order_return_form.php?q=$C5" | grep -o 'qty\[[0-9]*\]' | head -1 | grep -o '[0-9]*')
BLK=$(curl -s -b $W/cj_cash -c $W/cj_cash --data-urlencode "csrf=$PTC" -d "order_id=$O5&qty%5B$IT5%5D=6&reason=reg" $A/order_return_form.php | grep -c "vượt hạn mức")
eq "thu ngan tra 600000 bi chan boi han muc 500000" "1" "$BLK"
TC=$(tk $A/cham_cong_cua_toi.php $W/cj_cash)
curl -s -b $W/cj_cash -c $W/cj_cash -o /dev/null --data-urlencode csrf="$TC" -d "action=in" $A/cham_cong_cua_toi.php
eq "cham cong vao ca lan 2 bi bao da cham" "1" "$(curl -s -b $W/cj_cash -c $W/cj_cash --data-urlencode csrf="$TC" -d "action=in" $A/cham_cong_cua_toi.php | grep -c 'đã chấm công vào ca rồi')"

echo "== 6. Shop online theo tung cua hang"
SLUG=$(curl -s -b $CJ $A/online_shop_settings.php | grep -o 'name="shop_slug" value="[^"]*"' | sed 's/.*value="//;s/"//')
[ -n "$SLUG" ] && ok "cua hang co duong dan rieng ($SLUG)" || fail "cua hang co duong dan rieng" "co slug" "rong"
eq "trang shop hien san pham cua cua hang" "1" "$(curl -s "$A/shop.php?s=$SLUG" | grep -c 'SP Reg')"
eq "slug sai tra 404" "404" "$(curl -s -o /dev/null -w '%{http_code}' "$A/shop.php?s=khong-ton-tai-reg")"
curl -s -c $W/cj_pub -o /dev/null "$A/shop.php?s=$SLUG"; TT=$(tk "$A/shop.php?s=$SLUG" $W/cj_pub)
curl -s -b $W/cj_pub -c $W/cj_pub -o /dev/null --data-urlencode csrf="$TT" --data-urlencode s="$SLUG" -d "customer_name=Khach+Web&customer_phone=0955000111&customer_address=HN&qty[$PID]=1" $A/shop_order.php
eq "don online vao dung cua hang (danh sach don nhap)" "1" "$(curl -s -b $CJ "$A/orders.php?status=DRAFT" | grep -c 'Khach Web')"

echo "== 7. Doi chieu so lieu sau toan bo kich ban"
DC=$(curl -s -b $CJ $A/doi_chieu.php)
eq "trang doi chieu mo duoc va khong bao lech" "1" "$(echo "$DC" | grep -c 'Không phát hiện chỗ nào lệch')"

echo
echo "KET QUA: $PASS dat, $FAILN hong"
[ "$FAILN" -eq 0 ]
