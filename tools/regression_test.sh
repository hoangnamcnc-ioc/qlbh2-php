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
curl -s -b $CJ -c $CJ -o /dev/null --form-string csrf="$(tk $A/dang-ky.php $CJ)" --form-string accept_terms=1 --form-string company_name="ZZTEST Reg" --form-string admin_name="ZZ" --form-string email="zzreg_admin@example.com" --form-string password=Test1234 $A/dang-ky.php
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
eq "trang shop hien san pham cua cua hang" "1" "$([ "$(curl -s "$A/shop.php?s=$SLUG" | grep -c 'SP Reg')" -ge 1 ] && echo 1 || echo 0)"
eq "slug sai tra 404" "404" "$(curl -s -o /dev/null -w '%{http_code}' "$A/shop.php?s=khong-ton-tai-reg")"
curl -s -c $W/cj_pub -o /dev/null "$A/shop.php?s=$SLUG"; TT=$(tk "$A/shop.php?s=$SLUG" $W/cj_pub)
curl -s -b $W/cj_pub -c $W/cj_pub -o /dev/null --data-urlencode csrf="$TT" --data-urlencode s="$SLUG" -d "customer_name=Khach+Web&customer_phone=0955000111&customer_address=HN&qty[$PID]=1" $A/shop_order.php
eq "don online vao dung cua hang (danh sach don nhap)" "1" "$(curl -s -b $CJ "$A/orders.php?status=DRAFT" | grep -c 'Khach Web')"

curl -s -c $W/cj_pub -o /dev/null "$A/shop.php?s=$SLUG&t=Ban%203"; TT=$(tk "$A/shop.php?s=$SLUG&t=Ban%203" $W/cj_pub)
eq "QR theo ban: trang shop hien vi tri 'Ban 3'" "1" "$([ "$(curl -s "$A/shop.php?s=$SLUG&t=Ban%203" | grep -c 'Ban 3')" -ge 1 ] && echo 1 || echo 0)"
curl -s -b $W/cj_pub -c $W/cj_pub -o /dev/null --data-urlencode csrf="$TT" --data-urlencode s="$SLUG" --data-urlencode t="Ban 3" -d "receive=pickup&customer_phone=0966000333&qty[$PID]=1" $A/shop_order.php
eq "QR theo ban: don nhan tai cho (khong can dia chi/ten) ghi [Ban 3] vao don" "1" "$([ "$(curl -s -b $CJ "$A/orders.php?status=DRAFT" | grep -c 'Khách Ban 3\|Ban 3')" -ge 1 ] && echo 1 || echo 0)"
eq "trang cau hinh co ma QR cho khach quet" "1" "$([ "$(curl -s -b $CJ $A/online_shop_settings.php | grep -c 'qr-main')" -ge 1 ] && echo 1 || echo 0)"

echo "== 6b. COD: doi soat ghi so quy, don, cong no"
BASEB="\"csrf\":\"$PT\",\"payment_method\":\"CASH\",\"customer_phone\":\"0922000111\",\"customer_name\":\"Khach COD\""
RC=$(checkout "{$BASEB,\"items\":[{\"product_id\":$PID,\"variant_id\":null,\"quantity\":1,\"unit_price\":100000}],\"paid_amount\":0}")
OC=$(echo "$RC" | jv "['order_id']")
CIDC=$(curl -s -b $CJ "$A/customers.php?q=0922000111" | grep -o 'customer_view.php?id=[0-9]*' | head -1 | grep -o '[0-9]*$')
TSH=$(tk "$A/shipment_form.php?order_id=$OC" $CJ)
curl -s -b $CJ -c $CJ -o /dev/null --data-urlencode csrf="$TSH" -d "recipient_name=Khach+COD&carrier_name=GHN&tracking_code=REG123&status=DELIVERED&shipping_fee=20000&cod_amount=100000&fee_payer=CUSTOMER" "$A/shipment_form.php?order_id=$OC"
SHID=$(curl -s -b $CJ "$A/shipments.php?filter=unreconciled" | grep -o 'name="shipment_id" value="[0-9]*"' | head -1 | grep -o '[0-9]*')
curl -s -b $CJ -c $CJ -o /dev/null --data-urlencode csrf="$(tk $A/shipments.php $CJ)" -d "shipment_id=$SHID&method=BANK_TRANSFER" $A/shipment_reconcile.php
S=$(probe "a=state&c=$CIDC&o=$OC")
eq "COD: so quy rong = 80000 (thu 100000 - hang khau tru phi 20000)" "80000" "$(python -c "print(int(float($(echo "$S" | jv "['cash_net']"))))")"
eq "COD: don chuyen sang da thanh toan" "PAID" "$(echo "$S" | jv "['order']['payment_status']")"
eq "COD: cong no khach ve 0" "0" "$(python -c "print(int(float($(echo "$S" | jv "['customer']['debt']"))))")"
curl -s -b $CJ -c $CJ -o /dev/null --data-urlencode csrf="$(tk $A/shipments.php $CJ)" -d "shipment_id=$SHID&method=BANK_TRANSFER" $A/shipment_reconcile.php
S=$(probe "a=state&c=$CIDC&o=$OC")
eq "COD: doi soat 2 lan khong ghi so lan 2" "80000" "$(python -c "print(int(float($(echo "$S" | jv "['cash_net']"))))")"

echo "== 6c. Ma giam gia + hang thanh vien"
T=$(tk $A/coupons.php $CJ)
curl -s -b $CJ -c $CJ -o /dev/null --data-urlencode csrf="$T" -d "code=REGC10&discount_type=PERCENT&discount_value=10&min_order_amount=0&max_uses=5&start_date=2026-01-01&end_date=2027-12-31" $A/coupons.php
RCP=$(checkout "{\"csrf\":\"$PT\",\"payment_method\":\"CASH\",\"coupon_code\":\"REGC10\",\"items\":[{\"product_id\":$PID,\"variant_id\":null,\"quantity\":1,\"unit_price\":100000}]}")
OCP=$(echo "$RCP" | jv "['order_id']")
eq "coupon 10% giam don 100000 con 90000" "90000" "$(python -c "print(int(float($(probe "a=state&c=0&o=$OCP" | jv "['order']['total_amount']"))))")"
T=$(tk $A/customer_tiers.php $CJ)
curl -s -b $CJ -c $CJ -o /dev/null --data-urlencode csrf="$T" -d "action=create&name=Reg+Tier&discount_percent=3&min_spend=0" $A/customer_tiers.php
RT=$(checkout "{$BASEB,\"items\":[{\"product_id\":$PID,\"variant_id\":null,\"quantity\":1,\"unit_price\":100000}]}")
OT=$(echo "$RT" | jv "['order_id']")
eq "hang thanh vien 3% giam don 100000 con 97000" "97000" "$(python -c "print(int(float($(probe "a=state&c=$CIDC&o=$OT" | jv "['order']['total_amount']"))))")"

echo "== 6d. Het han dung thu"
probe "a=expire" >/dev/null
EX=$(curl -s -b $CJ -o /dev/null -w "%{http_code} %{redirect_url}" $A/index.php)
eq "het han dung thu: vao app bi chuyen sang trial_expired" "302 $A/trial_expired.php" "$EX"
eq "het han dung thu: trang thong bao het han mo duoc" "200" "$(curl -s -b $CJ -o /dev/null -w '%{http_code}' $A/trial_expired.php)"
POSX=$(curl -s -b $CJ -o /dev/null -w "%{http_code}" -H 'Content-Type: application/json' -d "{\"csrf\":\"$PT\",\"items\":[{\"product_id\":$PID,\"variant_id\":null,\"quantity\":1,\"unit_price\":100000}],\"payment_method\":\"CASH\"}" $A/pos_checkout.php)
eq "het han dung thu: khong ban duoc hang (pos_checkout tra 402 kem thong bao)" "402" "$POSX"
probe "a=unexpire" >/dev/null
eq "gia han xong: vao app lai binh thuong" "200" "$(curl -s -b $CJ -o /dev/null -w '%{http_code}' $A/index.php)"

echo "== 6e. Dieu chinh gia von"
curl -s -b $CJ -c $CJ -o /dev/null --data-urlencode csrf="$(tk $A/price_adjustments.php $CJ)" -d "product_id=$PID&new_cost_price=71000&reason=reg" $A/price_adjustments.php
eq "dieu chinh gia von 60000 -> 71000 duoc ghi lich su" "1" "$(curl -s -b $CJ $A/price_adjustments.php | grep -c '71.000')"

echo "== 6f. Don dat truoc (nhap) -> duyet het chuoi -> thu tien sau"
RD=$(checkout "{$BASEB,\"draft\":true,\"items\":[{\"product_id\":$PID,\"variant_id\":null,\"quantity\":1,\"unit_price\":100000}]}")
OD=$(echo "$RD" | jv "['order_id']")
for i in 1 2 3 4; do curl -s -b $CJ -c $CJ -o /dev/null --data-urlencode csrf="$PT" -d "order_id=$OD" $A/order_advance.php; done
curl -s -b $CJ -c $CJ -o /dev/null --data-urlencode csrf="$PT" -d "order_id=$OD&amount=60000&method=BANK_TRANSFER" $A/order_pay.php
S=$(probe "a=state&c=$CIDC&o=$OD")
eq "don dat truoc: thu 60000 ghi nhan duoc vao don" "60000" "$(python -c "print(int(float($(echo "$S" | jv "['order']['paid_amount']"))))")"
eq "don dat truoc: so quy co 60000" "60000" "$(python -c "print(int(float($(echo "$S" | jv "['cash_net']"))))")"
curl -s -b $CJ -c $CJ -o /dev/null --data-urlencode csrf="$PT" -d "order_id=$OD&amount=40000" $A/order_pay.php
S=$(probe "a=state&c=$CIDC&o=$OD")
eq "don dat truoc: thu not 40000 -> da thanh toan du" "PAID" "$(echo "$S" | jv "['order']['payment_status']")"

echo "== 6g. Sua chiet khau don da co cong no / da thu tien"
RE=$(checkout "{$BASEB,\"items\":[{\"product_id\":$PID,\"variant_id\":null,\"quantity\":1,\"unit_price\":100000}],\"paid_amount\":40000}")
OE=$(echo "$RE" | jv "['order_id']")
S0=$(probe "a=state&c=$CIDC&o=$OE")
D0=$(python -c "print(int(float($(echo "$S0" | jv "['customer']['debt']"))))")
T0=$(python -c "print(int(float($(echo "$S0" | jv "['order']['total_amount']"))))")
TE=$(tk "$A/order_edit.php?id=$OE" $CJ)
curl -s -b $CJ -c $CJ -o /dev/null --data-urlencode csrf="$TE" -d "discount=10000&note=reg" "$A/order_edit.php?id=$OE"
S=$(probe "a=state&c=$CIDC&o=$OE")
eq "sua chiet khau ve 10000: tong don con 90000" "90000" "$(python -c "print(int(float($(echo "$S" | jv "['order']['total_amount']"))))")"
eq "doi chiet khau: cong no khach giam dung bang phan tong don giam ($T0 -> 90000)" "$((D0-(T0-90000)))" "$(python -c "print(int(float($(echo "$S" | jv "['customer']['debt']"))))")"
curl -s -b $CJ -c $CJ -o /dev/null --data-urlencode csrf="$TE" -d "discount=70000&note=reg" "$A/order_edit.php?id=$OE"
S=$(probe "a=state&c=$CIDC&o=$OE")
eq "chiet khau lam tong < da thu (40000) bi tu choi, tong van 90000" "90000" "$(python -c "print(int(float($(echo "$S" | jv "['order']['total_amount']"))))")"

echo "== 6h. Nha cung cap: nhap hang, tra tien, tra hang NCC, kiem kho"
T=$(tk $A/suppliers.php $CJ)
curl -s -b $CJ -c $CJ -o /dev/null --data-urlencode csrf="$T" -d "name=NCC+Reg&phone=0911000222&address=HN" $A/suppliers.php
SIDR=$(curl -s -b $CJ $A/suppliers.php | grep -o 'supplier_view.php?id=[0-9]*' | head -1 | grep -o '[0-9]*$')
ST0=$(stock)
curl -s -b $CJ -c $CJ -o /dev/null --data-urlencode csrf="$T" -d "supplier_id=$SIDR&product_id[]=$PID&variant_id[]=&quantity[]=10&cost_price[]=60000&paid_amount=200000&reference_no=REGHD" $A/stock_receipt_form.php
eq "nhap hang 10 cai: ton kho tang 10" "$((ST0+10))" "$(stock)"
RIDR=$(curl -s -b $CJ $A/stock_receipts.php | grep -o 'stock_receipt_view.php?id=[0-9]*' | head -1 | grep -o '[0-9]*$')
CODER=$(curl -s -b $CJ "$A/stock_receipt_view.php?id=$RIDR" | grep -o 'PN[A-Z0-9]*' | head -1)
curl -s -b $CJ -c $CJ -o /dev/null --data-urlencode csrf="$T" -d "receipt_id=$RIDR&amount=100000" $A/stock_receipt_pay.php
eq "tra tien NCC them 100000: phieu hien 300.000 (da tra/con no)" "1" "$([ "$(curl -s -b $CJ "$A/stock_receipt_view.php?id=$RIDR" | grep -c '300.000')" -ge 1 ] && echo 1 || echo 0)"
IRI=$(curl -s -b $CJ "$A/supplier_return_form.php?q=$CODER" | grep -o 'qty\[[0-9]*\]' | head -1 | grep -o '[0-9]*')
curl -s -b $CJ -c $CJ -o /dev/null --data-urlencode csrf="$T" -d "receipt_id=$RIDR&qty%5B$IRI%5D=3&reason=reg" $A/supplier_return_form.php
eq "tra NCC 3 cai: ton kho giam 3" "$((ST0+7))" "$(stock)"
ST1=$(stock)
BRR=$(curl -s -b $CJ $A/stock_take_form.php | grep -o 'option value="[0-9]*" selected' | grep -o '[0-9]*' | head -1)
curl -s -b $CJ -c $CJ -o /dev/null --data-urlencode csrf="$T" -d "branch_id=$BRR&note=reg&product_id[]=$PID&variant_id[]=&system_qty[]=$ST1&counted_qty[]=$((ST1-2))" $A/stock_take_form.php
TKR=$(curl -s -b $CJ $A/stock_takes.php | grep -o 'stock_take_view.php?id=[0-9]*' | head -1 | grep -o '[0-9]*$')
curl -s -b $CJ -c $CJ -o /dev/null --data-urlencode csrf="$T" -d "take_id=$TKR" $A/stock_take_balance.php
curl -s -b $CJ -c $CJ -o /dev/null --data-urlencode csrf="$T" -d "take_id=$TKR" $A/stock_take_balance.php
eq "kiem kho lech -2, can bang 2 lan chi tru 1 lan" "$((ST1-2))" "$(stock)"

echo "== 6i. Sua san pham trong don + hang hoan ve"
ST0=$(stock)
RI=$(checkout "{$BASEB,\"items\":[{\"product_id\":$PID,\"variant_id\":null,\"quantity\":2,\"unit_price\":100000}],\"draft\":true}")
OI=$(echo "$RI" | jv "['order_id']")
eq "don dat truoc 2 cai: kho giam 2" "$((ST0-2))" "$(stock)"
II=$(curl -s -b $CJ "$A/order_edit_items.php?id=$OI" | grep -o 'name="qty\[[0-9]*\]"' | head -1 | grep -o '[0-9]*')
TI=$(tk "$A/order_edit_items.php?id=$OI" $CJ)
curl -s -b $CJ -c $CJ -o /dev/null --data-urlencode csrf="$TI" -d "id=$OI&qty%5B$II%5D=5&price%5B$II%5D=100000" $A/order_edit_items.php
eq "sua len 5 cai: kho giam them 3" "$((ST0-5))" "$(stock)"
eq "sua len 5 cai: tong don 494000 (500000 - chiet khau hang thanh vien 6000 giu nguyen)" "494000" "$(python -c "print(int(float($(probe "a=state&c=0&o=$OI" | jv "['order']['total_amount']"))))")"
curl -s -b $CJ -c $CJ -o /dev/null --data-urlencode csrf="$TI" -d "id=$OI&qty%5B$II%5D=1&price%5B$II%5D=90000" $A/order_edit_items.php
eq "sua xuong 1 cai gia 90000: kho hoan lai 4" "$((ST0-1))" "$(stock)"
eq "sua xuong 1 cai gia 90000: tong don 84000 (90000 - chiet khau 6000)" "84000" "$(python -c "print(int(float($(probe "a=state&c=0&o=$OI" | jv "['order']['total_amount']"))))")"
BIG=$(curl -s -b $CJ -c $CJ --data-urlencode csrf="$TI" -d "id=$OI&qty%5B$II%5D=999999&price%5B$II%5D=1" $A/order_edit_items.php | grep -c "Không đủ tồn kho")
eq "tang vuot ton kho bi tu choi" "1" "$BIG"
# Hang hoan ve: van don chuyen sang Da hoan -> tu huy don, hoan kho
for i in 1 2; do curl -s -b $CJ -c $CJ -o /dev/null --data-urlencode csrf="$PT" -d "order_id=$OI" $A/order_advance.php; done
TSR=$(tk "$A/shipment_form.php?order_id=$OI" $CJ)
curl -s -b $CJ -c $CJ -o /dev/null --data-urlencode csrf="$TSR" -d "recipient_name=K&carrier_name=GHN&status=RETURNED&shipping_fee=0&cod_amount=0&fee_payer=SHOP" "$A/shipment_form.php?order_id=$OI"
eq "van don 'Da hoan': don tu huy" "CANCELLED" "$(probe "a=state&c=0&o=$OI" | jv "['order']['status']")"
eq "van don 'Da hoan': kho tra lai du" "$ST0" "$(stock)"

echo "== 6j. Het han van xuat duoc du lieu"
probe "a=expire" >/dev/null
EXP=$(curl -s -b $CJ -o /dev/null -w "%{http_code}" -X POST -d "csrf=$(tk $A/tenant_export.php $CJ)&download=1" $A/tenant_export.php)
eq "het han: admin van tai duoc du lieu cua minh (tenant_export 200)" "200" "$EXP"
probe "a=unexpire" >/dev/null

echo "== 6x. Phap ly: dieu khoan, bao mat, ban quyen, mien tru"
eq "trang Dieu khoan su dung mo cong khai (200)" "200" "$(curl -s -o /dev/null -w '%{http_code}' $A/dieu_khoan.php)"
eq "trang Chinh sach bao mat mo cong khai (200)" "200" "$(curl -s -o /dev/null -w '%{http_code}' $A/chinh_sach_bao_mat.php)"
eq "dieu khoan co noi dung mien tru thue/ke toan va gioi han trach nhiem" "1" "$([ "$(curl -s $A/dieu_khoan.php | grep -c 'Miễn trừ trách nhiệm')" -ge 1 ] && echo 1 || echo 0)"
eq "man hinh quan ly co dong ban quyen + lien ket dieu khoan o chan trang" "1" "$([ "$(curl -s -b $CJ $A/index.php | grep -c 'Điều khoản sử dụng')" -ge 1 ] && echo 1 || echo 0)"
eq "trang dang nhap co dong ban quyen" "1" "$([ "$(curl -s $A/login.php | grep -c 'Bảo lưu mọi quyền')" -ge 1 ] && echo 1 || echo 0)"
eq "bao cao co dong mien tru tham khao" "1" "$([ "$(curl -s -b $CJ $A/reports.php | grep -c 'tham khảo')" -ge 1 ] && echo 1 || echo 0)"
eq "trang dat hang cho khach co dong rieng tu" "1" "$([ "$(curl -s "$A/shop.php?s=$SLUG" | grep -c 'Chính sách bảo mật')" -ge 1 ] && echo 1 || echo 0)"
curl -s -c $W/cj_nt -o /dev/null $A/dang-ky.php
NOTERMS=$(curl -s -b $W/cj_nt -c $W/cj_nt -o /dev/null -w "%{http_code} %{redirect_url}" --form-string csrf="$(tk $A/dang-ky.php $W/cj_nt)" --form-string company_name="ZZTEST Reg" --form-string admin_name="NoTerms" --form-string email="zzreg_noterms@example.com" --form-string password=Test1234 $A/dang-ky.php)
eq "dang ky KHONG tich dong y dieu khoan thi khong tao tai khoan (200, o lai trang)" "200 " "$NOTERMS"
eq "dang ky co ghi lai thoi diem dong y dieu khoan" "1" "$([ "$(probe "a=terms" | jv "['accepted']")" -ge 1 ] && echo 1 || echo 0)"

echo "== 6k. So do chuc nang"
LGT=$(tk $A/login.php $W/cj_land); LAND=$(curl -s -b $W/cj_land -c $W/cj_land -o /dev/null -w "%{redirect_url}" --form-string csrf="$LGT" --form-string email=zzreg_cash@example.com --form-string password=Test1234 $A/login.php)
eq "dang nhap xong vao thang trang So do chuc nang" "$A/so_do_chuc_nang.php" "$LAND"
TA=$(curl -s -b $CJ $A/so_do_chuc_nang.php | grep -c 'class="sdcn-tile"')
TC=$(curl -s -b $W/cj_cash $A/so_do_chuc_nang.php | grep -c 'class="sdcn-tile"')
[ "$TA" -ge 25 ] && ok "quan tri thay day du so do ($TA o)" || fail "quan tri thay day du so do" ">=25 o" "$TA"
[ "$TC" -ge 5 ] && [ "$TC" -lt "$TA" ] && ok "thu ngan chi thay chuc nang duoc phep ($TC o < $TA)" || fail "thu ngan thay it o hon quan tri" "5..$((TA-1))" "$TC"
eq "thu ngan khong thay o Cau hinh/Bao cao trong so do" "0" "$(curl -s -b $W/cj_cash $A/so_do_chuc_nang.php | grep -c 'href="settings.php"\|href="reports.php"')"
for RR in cash chat; do
  MISS=$(curl -s -b $W/cj_$RR $A/so_do_chuc_nang.php | python -c "
import sys,re
h=sys.stdin.buffer.read().decode('utf-8','replace')
side=h[h.find('<aside'):h.find('</aside>')]
menu=set(re.findall(r'href=\"([a-z_0-9]+\.php)\"',side))-{'so_do_chuc_nang.php','huong_dan.php','index.php','change_password.php'}
tiles=set(re.findall(r'class=\"sdcn-tile\" href=\"([a-z_0-9]+\.php)\"',h))
print(len(menu-tiles), len(tiles-menu-{'cham_cong_cua_toi.php'}))
")
  eq "so do chuc nang vai tro $RR: dung va du chuc nang trong menu (thieu, thua)" "0 0" "$MISS"
done
eq "menu co muc Goc y / Bao loi" "1" "$([ "$(curl -s -b $CJ $A/so_do_chuc_nang.php | grep -c 'Góp ý / Báo lỗi')" -ge 1 ] && echo 1 || echo 0)"

echo "== 6m. Chia nhieu hinh thuc thanh toan"
RS=$(checkout "{\"csrf\":\"$PT\",\"items\":[{\"product_id\":$PID,\"variant_id\":null,\"quantity\":1,\"unit_price\":100000}],\"payment_method\":\"CASH\",\"payments\":{\"CASH\":80000,\"BANK_TRANSFER\":50000}}")
OS=$(echo "$RS" | jv "['order_id']")
S=$(probe "a=state&c=0&o=$OS")
eq "chia TT: don du tien (tong 100000 - tien khach dua 130000, thoi 30000)" "PAID" "$(echo "$S" | jv "['order']['payment_status']")"
eq "chia TT: chuyen khoan ghi dung 50000" "50000" "$(python -c "print(int(float($(echo "$S" | jv "['by_method']['BANK_TRANSFER']"))))")"
eq "chia TT: tien mat ghi 50000 (da tru tien thoi 30000)" "50000" "$(python -c "print(int(float($(echo "$S" | jv "['by_method']['CASH']"))))")"
eq "chia TT: tong so quy cua don = 100000" "100000" "$(python -c "print(int(float($(echo "$S" | jv "['cash_net']"))))")"
RS2=$(checkout "{$BASEB,\"items\":[{\"product_id\":$PID,\"variant_id\":null,\"quantity\":1,\"unit_price\":100000}],\"payments\":{\"CASH\":30000,\"QR_CODE\":20000}}")
OS2=$(echo "$RS2" | jv "['order_id']"); S2=$(probe "a=state&c=$CIDC&o=$OS2")
eq "chia TT thieu tien + co khach: don PARTIAL" "PARTIAL" "$(echo "$S2" | jv "['order']['payment_status']")"
eq "chia TT thieu tien: tien da thu 50000 (30000 mat + 20000 QR)" "50000" "$(python -c "print(int(float($(echo "$S2" | jv "['cash_net']"))))")"

echo "== 6n. Khach theo doi don (QR) + diem"
TRK_CODE=$(curl -s -b $CJ "$A/orders.php?status=DRAFT" | grep -o 'DH[A-Z0-9]\{6,\}' | head -1)
curl -s -c $W/cj_trk -o /dev/null "$A/shop_track.php?s=$SLUG&code=$TRK_CODE"
TTK=$(tk "$A/shop_track.php?s=$SLUG&code=$TRK_CODE" $W/cj_trk)
BAD=$(curl -s -b $W/cj_trk -c $W/cj_trk --data-urlencode csrf="$TTK" --data-urlencode s="$SLUG" -d "code=$TRK_CODE&phone=0000000000" $A/shop_track.php | grep -c 'Không tìm thấy đơn hàng khớp')
eq "theo doi don: sai so dien thoai thi khong xem duoc" "1" "$BAD"
OK1=$(curl -s -b $W/cj_trk -c $W/cj_trk --data-urlencode csrf="$TTK" --data-urlencode s="$SLUG" -d "code=$TRK_CODE&phone=0955000111" $A/shop_track.php | grep -c 'Đã nhận đơn')
OK2=$(curl -s -b $W/cj_trk -c $W/cj_trk --data-urlencode csrf="$TTK" --data-urlencode s="$SLUG" -d "code=$TRK_CODE&phone=0966000333" $A/shop_track.php | grep -c 'Đã nhận đơn')
eq "theo doi don: dung ma + so dien thoai thi thay trang thai" "1" "$([ $((OK1+OK2)) -ge 1 ] && echo 1 || echo 0)"
eq "theo doi don: ma don cua cua hang khac/khong ton tai khong xem duoc" "1" "$(curl -s -b $W/cj_trk -c $W/cj_trk --data-urlencode csrf="$TTK" --data-urlencode s="$SLUG" -d "code=DHKHONGCO&phone=0955000111" $A/shop_track.php | grep -c 'Không tìm thấy đơn hàng khớp')"

echo "== 6o. Xuat Excel ke toan, cong no, tong quan chu quan"
curl -s -b $CJ -o $W/acc.xlsx "$A/accounting.php?export=1&nam=$(date +%Y)"
eq "ke toan: xuat Excel tra ve file .xlsx hop le (ZIP/PK)" "PK" "$(head -c 2 $W/acc.xlsx)"
ge "ke toan: file Excel co noi dung" 2000 "$(stat -c %s $W/acc.xlsx 2>/dev/null || wc -c < $W/acc.xlsx)"
eq "cong no phai thu/phai tra mo duoc" "1" "$([ "$(curl -s -b $CJ $A/cong_no.php | grep -c 'Phải thu từ khách')" -ge 1 ] && echo 1 || echo 0)"
eq "cong no: xuat Excel" "1" "$(curl -s -b $CJ -D - -o /dev/null "$A/cong_no.php?export=1" | grep -ci 'spreadsheetml')"
eq "thu ngan khong vao duoc cong no (403)" "403" "$(curl -s -b $W/cj_cash -o /dev/null -w '%{http_code}' $A/cong_no.php)"
eq "tong quan chu quan co Lai gop hom nay + Tien trong so quy" "1" "$([ "$(curl -s -b $CJ $A/index.php | grep -c 'Lãi gộp hôm nay')" -ge 1 ] && [ "$(curl -s -b $CJ $A/index.php | grep -c 'Tiền trong sổ quỹ')" -ge 1 ] && echo 1 || echo 0)"
eq "tong quan thu ngan KHONG thay lai gop/so quy" "0" "$(curl -s -b $W/cj_cash $A/index.php | grep -c 'Lãi gộp hôm nay\|Tiền trong sổ quỹ')"

echo "== 6p. Khoa cham cong theo mang cua hang"
TCC=$(tk $A/cham_cong_cua_toi.php $W/cj_cash)
probe "a=setting&k=attendance_ip_lock&v=1" >/dev/null; probe "a=setting&k=attendance_allowed_ips&v=203.0.113.9" >/dev/null
BLK=$(curl -s -b $W/cj_cash -c $W/cj_cash --data-urlencode csrf="$TCC" -d "action=out" $A/cham_cong_cua_toi.php | grep -c 'ngoài mạng Wi-Fi')
eq "khoa mang: ngoai mang cua hang bi chan" "1" "$BLK"
probe "a=setting&k=attendance_allowed_ips&v=" >/dev/null
eq "khoa mang: chua dang ky mang nao thi khong chan (tranh tu khoa)" "0" "$(curl -s -b $W/cj_cash -c $W/cj_cash --data-urlencode csrf="$TCC" -d "action=out" $A/cham_cong_cua_toi.php | grep -c 'ngoài mạng Wi-Fi')"
TIP=$(tk $A/attendance.php $CJ)
curl -s -b $CJ -c $CJ -o /dev/null --data-urlencode csrf="$TIP" -d "action=ip_add" $A/attendance.php
eq "khoa mang: Quan tri them mang hien tai -> nhan vien trong mang van cham cong duoc" "0" "$(curl -s -b $W/cj_cash -c $W/cj_cash --data-urlencode csrf="$TCC" -d "action=out" $A/cham_cong_cua_toi.php | grep -c 'ngoài mạng Wi-Fi')"
probe "a=setting&k=attendance_ip_lock&v=0" >/dev/null

echo "== 6q. Xuat Excel bao cao ban hang"
RH=$(curl -s -b $CJ -D - -o $W/rep.xlsx "$A/reports.php?export=1&from=$(date -d '-30 days' +%Y-%m-%d)&to=$(date +%Y-%m-%d)" | tr -d '\r' | grep -i '^content-type' | head -1)
eq "bao cao ban hang: xuat Excel tra ve file .xlsx" "1" "$(echo "$RH" | grep -ci 'spreadsheetml')"
ge "bao cao ban hang: file Excel co noi dung" 2000 "$(wc -c < $W/rep.xlsx)"
eq "bao cao ban hang: trang thu ngan khong xuat duoc (403)" "403" "$(curl -s -b $W/cj_cash -o /dev/null -w '%{http_code}' "$A/reports.php?export=1")"

echo "== 6r. Xac minh vi tri GPS khi cham cong"
T=$(tk $A/users.php $CJ)
BRG=$(curl -s -b $CJ $A/stock_take_form.php | grep -o 'option value="[0-9]*" selected' | grep -o '[0-9]*' | head -1)
curl -s -b $CJ -c $CJ -o /dev/null --data-urlencode csrf="$T" -d "action=create&name=GPS+User&email=zzreg_gps@example.com&password=Test1234&role=CASHIER&branch_id=$BRG" $A/users.php
LTG=$(tk $A/login.php $W/cj_gps); curl -s -b $W/cj_gps -c $W/cj_gps -o /dev/null --form-string csrf="$LTG" --form-string email=zzreg_gps@example.com --form-string password=Test1234 $A/login.php
TAD=$(tk $A/attendance.php $CJ)
curl -s -b $CJ -c $CJ -o /dev/null --data-urlencode csrf="$TAD" -d "action=gps_save&enabled=1&radius=100&lat=10.7769&lng=106.7009" $A/attendance.php
TGP=$(tk $A/cham_cong_cua_toi.php $W/cj_gps)
gps_post() { curl -s -b $W/cj_gps -c $W/cj_gps --data-urlencode csrf="$TGP" -d "action=$1&lat=$2&lng=$3&acc=$4" $A/cham_cong_cua_toi.php; }
eq "GPS: khong gui vi tri thi bi chan" "1" "$(gps_post in '' '' 0 | grep -c 'Cần cho phép truy cập vị trí')"
eq "GPS: cach cua hang ~3km bi chan" "1" "$([ "$(gps_post in 10.8040 106.7009 20 | grep -c 'cách cửa hàng')" -ge 1 ] && echo 1 || echo 0)"
eq "GPS: tin hieu kem (sai so 500m) bi chan" "1" "$(gps_post in 10.7769 106.7009 500 | grep -c 'chưa đủ chính xác')"
eq "GPS: trong ban kinh (cach ~15m) cham cong thanh cong" "1" "$(gps_post in 10.77700 106.70100 15 | grep -c 'Đã chấm công vào ca')"
eq "GPS: khoang cach duoc ghi vao ghi chu cham cong" "1" "$([ "$(curl -s -b $W/cj_gps $A/cham_cong_cua_toi.php | grep -c 'GPS vào cách cửa hàng')" -ge 1 ] && echo 1 || echo 0)"
curl -s -b $CJ -c $CJ -o /dev/null --data-urlencode csrf="$(tk $A/attendance.php $CJ)" -d "action=gps_save&radius=100" $A/attendance.php

echo "== 6s. Thanh toan QR ngan hang cho khach dat qua QR"
curl -s -b $CJ -c $CJ -o /dev/null --data-urlencode csrf="$(tk $A/payment_settings.php $CJ)" -d "bank_code=VCB&bank_account=0123456789&bank_account_name=CUA+HANG+TEST" $A/payment_settings.php
curl -s -c $W/cj_pub2 -o /dev/null "$A/shop.php?s=$SLUG"; TQ=$(tk "$A/shop.php?s=$SLUG" $W/cj_pub2)
RQ=$(curl -s -b $W/cj_pub2 -c $W/cj_pub2 -o /dev/null -w "%{redirect_url}" --data-urlencode csrf="$TQ" --data-urlencode s="$SLUG" -d "receive=pickup&customer_phone=0977000444&qty[$PID]=2" $A/shop_order.php)
QCODE=$(echo "$RQ" | grep -o 'code=[A-Z0-9]*' | cut -d= -f2)
OKPAGE=$(curl -s "$A/shop.php?s=$SLUG&ok=1&code=$QCODE")
eq "QR NH: trang dat hang thanh cong hien ma QR VietQR" "1" "$([ "$(echo "$OKPAGE" | grep -c 'img.vietqr.io/image/VCB-0123456789')" -ge 1 ] && echo 1 || echo 0)"
eq "QR NH: ma QR mang dung noi dung = ma don" "1" "$([ "$(echo "$OKPAGE" | grep -c "addInfo=$QCODE")" -ge 1 ] && echo 1 || echo 0)"
eq "QR NH: ma don khong thuoc cua hang thi khong hien QR" "0" "$(curl -s "$A/shop.php?s=$SLUG&ok=1&code=DHKHONGCO" | grep -c 'img.vietqr.io')"
TTR=$(tk "$A/shop_track.php?s=$SLUG&code=$QCODE" $W/cj_pub2)
eq "QR NH: trang theo doi don con no cung hien QR" "1" "$([ "$(curl -s -b $W/cj_pub2 -c $W/cj_pub2 --data-urlencode csrf="$TTR" --data-urlencode s="$SLUG" -d "code=$QCODE&phone=0977000444" $A/shop_track.php | grep -c 'img.vietqr.io')" -ge 1 ] && echo 1 || echo 0)"
OQ=$(curl -s -b $CJ "$A/orders.php?q=$QCODE" | grep -o 'order_view.php?id=[0-9]*' | head -1 | grep -o '[0-9]*$')
for i in 1 2 3 4; do curl -s -b $CJ -c $CJ -o /dev/null --data-urlencode csrf="$PT" -d "order_id=$OQ" $A/order_advance.php; done
curl -s -b $CJ -c $CJ -o /dev/null --data-urlencode csrf="$PT" -d "order_id=$OQ&amount=999999999&method=BANK_TRANSFER" $A/order_pay.php
eq "QR NH: da thanh toan du thi trang khong con hien QR" "0" "$(curl -s "$A/shop.php?s=$SLUG&ok=1&code=$QCODE" | grep -c 'img.vietqr.io')"

echo "== 6t. Tu doi soat chuyen khoan (webhook bien dong so du)"
WK=$(curl -s -b $CJ $A/bank_reconcile.php | grep -o 'value="[a-f0-9]\{40\}"' | head -1 | grep -o '[a-f0-9]\{40\}')
[ -n "$WK" ] && ok "cua hang co khoa webhook rieng" || fail "cua hang co khoa webhook rieng" "40 ky tu hex" "rong"
eq "webhook: thieu khoa -> 401" "401" "$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' -d '{"transferAmount":1000}' $A/bank_webhook.php)"
eq "webhook: sai khoa -> 401" "401" "$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Authorization: Apikey 0000000000000000000000000000000000000000' -H 'Content-Type: application/json' -d '{"transferAmount":1000}' $A/bank_webhook.php)"
eq "webhook: GET khong duoc -> 405" "405" "$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Apikey $WK" $A/bank_webhook.php)"
# Khach dat qua QR (chua tra) roi chuyen khoan dung noi dung = ma don
curl -s -c $W/cj_w -o /dev/null "$A/shop.php?s=$SLUG"; TW=$(tk "$A/shop.php?s=$SLUG" $W/cj_w)
RW=$(curl -s -b $W/cj_w -c $W/cj_w -o /dev/null -w "%{redirect_url}" --data-urlencode csrf="$TW" --data-urlencode s="$SLUG" -d "receive=pickup&customer_phone=0988000555&qty[$PID]=2" $A/shop_order.php)
WCODE=$(echo "$RW" | grep -o 'code=[A-Z0-9]*' | cut -d= -f2)
WORD=$(curl -s -b $CJ "$A/orders.php?q=$WCODE" | grep -o 'order_view.php?id=[0-9]*' | head -1 | grep -o '[0-9]*$')
WCID=$(curl -s -b $CJ "$A/customers.php?q=0988000555" | grep -o 'customer_view.php?id=[0-9]*' | head -1 | grep -o '[0-9]*$')
SW=$(probe "a=state&c=$WCID&o=$WORD"); WTOTAL=$(python -c "print(int(float($(echo "$SW" | jv "['order']['total_amount']"))))")
R1=$(curl -s -X POST -H "Authorization: Apikey $WK" -H 'Content-Type: application/json' -d "{\"id\":9001,\"gateway\":\"VCB\",\"transactionDate\":\"2026-10-13 10:00:00\",\"accountNumber\":\"0123456789\",\"transferType\":\"in\",\"transferAmount\":$WTOTAL,\"content\":\"CK $WCODE thanh toan\"}" $A/bank_webhook.php)
eq "webhook SePay: khop ma don trong noi dung -> MATCHED" "MATCHED" "$(echo "$R1" | jv "['results'][0]['status']")"
SW=$(probe "a=state&c=$WCID&o=$WORD")
eq "tu doi soat: don chuyen sang da thanh toan du" "PAID" "$(echo "$SW" | jv "['order']['payment_status']")"
eq "tu doi soat: so quy ghi dung so tien, hinh thuc chuyen khoan" "$WTOTAL" "$(python -c "print(int(float($(echo "$SW" | jv "['by_method']['BANK_TRANSFER']"))))")"
R2=$(curl -s -X POST -H "Authorization: Apikey $WK" -H 'Content-Type: application/json' -d "{\"id\":9001,\"transferType\":\"in\",\"transferAmount\":$WTOTAL,\"content\":\"CK $WCODE thanh toan\"}" $A/bank_webhook.php)
eq "webhook gui lai cung ma giao dich -> DUPLICATE, khong ghi so lan 2" "DUPLICATE" "$(echo "$R2" | jv "['results'][0]['status']")"
SW=$(probe "a=state&c=$WCID&o=$WORD")
eq "gui lai: so quy van khong doi" "$WTOTAL" "$(python -c "print(int(float($(echo "$SW" | jv "['cash_net']"))))")"
R3=$(curl -s -X POST -H "Authorization: Apikey $WK" -H 'Content-Type: application/json' -d '{"id":9002,"transferType":"in","transferAmount":55000,"content":"khong co ma don"}' $A/bank_webhook.php)
eq "giao dich khong co ma don -> UNMATCHED (cho gan tay)" "UNMATCHED" "$(echo "$R3" | jv "['results'][0]['status']")"
eq "webhook: giao dich tien RA bi bo qua" "0" "$(curl -s -X POST -H "Authorization: Apikey $WK" -H 'Content-Type: application/json' -d '{"id":9003,"transferType":"out","transferAmount":1000,"content":"x"}' $A/bank_webhook.php | jv "['processed']")"
eq "webhook Casso: dang data[] cung nhan duoc" "1" "$(curl -s -X POST -H "secure-token: $WK" -H 'Content-Type: application/json' -d '{"error":0,"data":[{"id":7001,"description":"TT khong ma","amount":20000,"when":"2026-10-13 10:05:00"}]}' $A/bank_webhook.php | jv "['processed']")"
curl -s -c $W/cj_w2 -o /dev/null "$A/shop.php?s=$SLUG"; TW2=$(tk "$A/shop.php?s=$SLUG" $W/cj_w2)
RW2=$(curl -s -b $W/cj_w2 -c $W/cj_w2 -o /dev/null -w "%{redirect_url}" --data-urlencode csrf="$TW2" --data-urlencode s="$SLUG" -d "receive=pickup&customer_phone=0988000666&qty[$PID]=1" $A/shop_order.php)
WCODE2=$(echo "$RW2" | grep -o 'code=[A-Z0-9]*' | cut -d= -f2)
WORD2=$(curl -s -b $CJ "$A/orders.php?q=$WCODE2" | grep -o 'order_view.php?id=[0-9]*' | head -1 | grep -o '[0-9]*$')
UTX=$(curl -s -b $CJ $A/bank_reconcile.php | grep -o 'name="tx" value="[0-9]*"' | head -1 | grep -o '[0-9]*')
curl -s -b $CJ -c $CJ -o /dev/null --data-urlencode csrf="$(tk $A/bank_reconcile.php $CJ)" -d "action=assign&tx=$UTX&code=$WCODE2" $A/bank_reconcile.php
SW2=$(probe "a=state&c=0&o=$WORD2")
eq "gan tay giao dich chua khop vao don: so quy ghi nhan" "1" "$([ "$(python -c "print(int(float($(echo "$SW2" | jv "['cash_net']"))))")" -gt 0 ] && echo 1 || echo 0)"

TE2=$(tk "$A/order_edit.php?id=$WORD2" $CJ)
curl -s -b $CJ -c $CJ -o /dev/null --data-urlencode csrf="$TE2" -d "discount=0&note=sua" "$A/order_edit.php?id=$WORD2"
eq "sua don dat truoc da nhan 1 phan tien chuyen khoan: trang thai thanh toan giu PARTIAL (khong ve UNPAID)" "PARTIAL" "$(probe "a=state&c=0&o=$WORD2" | jv "['order']['payment_status']")"

echo "== 6u. QR thanh toan tai quay (POS) + tu bao da nhan tien"
POSREF="TT"$(python -c "import random;print(''.join(random.choice('ABCDEFGHJKLMNPQRSTUVWXYZ23456789') for _ in range(6)))")
eq "POS: ma thanh toan chua co tien -> received 0" "0" "$(curl -s -b $CJ "$A/bank_status.php?ref=$POSREF" | jv "['received']")"
RP=$(curl -s -X POST -H "Authorization: Apikey $WK" -H 'Content-Type: application/json' -d "{\"id\":9100,\"transferType\":\"in\",\"transferAmount\":100000,\"content\":\"Thanh toan $POSREF\"}" $A/bank_webhook.php)
eq "POS: chuyen khoan co ma TT -> POS_REF" "POS_REF" "$(echo "$RP" | jv "['results'][0]['status']")"
eq "POS: man hinh thu ngan thay da nhan 100000" "100000" "$(python -c "print(int(float($(curl -s -b $CJ "$A/bank_status.php?ref=$POSREF" | jv "['received']"))))")"
RPOS=$(checkout "{\"csrf\":\"$PT\",\"items\":[{\"product_id\":$PID,\"variant_id\":null,\"quantity\":1,\"unit_price\":100000}],\"payment_method\":\"BANK_TRANSFER\",\"pay_ref\":\"$POSREF\"}")
eq "POS: thanh toan don xong" "1" "$([ -n "$(echo "$RPOS" | jv "['order_id']")" ] && echo 1 || echo 0)"
eq "POS: sau khi thanh toan, giao dich duoc gan vao don (khong con cho)" "0" "$(python -c "print(int(float($(curl -s -b $CJ "$A/bank_status.php?ref=$POSREF" | jv "['received']"))))")"
eq "POS: ma thanh toan sai dinh dang bi tu choi" "invalid_ref" "$(curl -s -b $CJ "$A/bank_status.php?ref=ABC" | jv "['error']")"

echo "== 6v. Nhac don online chua thanh toan"
probe "a=backdate" >/dev/null
RM=$(curl -s -b $CJ "$A/don_cho_thanh_toan.php?m=60")
eq "nhac TT: don online chua tra qua 1 gio hien trong danh sach (co nut Goi/SMS)" "1" "$([ "$(echo "$RM" | grep -c 'sms:')" -ge 1 ] && echo 1 || echo 0)"
eq "nhac TT: tin nhan soan san kem link theo doi don" "1" "$([ "$(echo "$RM" | grep -c 'shop_track.php')" -ge 1 ] && echo 1 || echo 0)"
RID=$(echo "$RM" | grep -o 'name="order_id" value="[0-9]*"' | head -1 | grep -o '[0-9]*')
curl -s -b $CJ -c $CJ -o /dev/null --data-urlencode csrf="$(tk "$A/don_cho_thanh_toan.php" $CJ)" -d "order_id=$RID" $A/don_cho_thanh_toan.php
eq "nhac TT: bam 'Da nhac' ghi nhan thoi gian nhac" "1" "$([ "$(curl -s -b $CJ "$A/don_cho_thanh_toan.php?m=60" | grep -c 'Đã nhắc [0-9]')" -ge 1 ] && echo 1 || echo 0)"
eq "nhac TT: cron sai khoa bi tu choi (403)" "403" "$(curl -s -o /dev/null -w '%{http_code}' "$A/payment_reminder_cron.php?key=sai")"
eq "doi soat chuyen khoan: thu ngan khong vao duoc (403)" "403" "$(curl -s -b $W/cj_cash -o /dev/null -w '%{http_code}' $A/bank_reconcile.php)"

echo "== 6w. Hang doi email + gia von binh quan + vai tro chat mo rong"
MQ=$(probe "a=mailq")
eq "hang doi email: don online moi da duoc nhan vao hang doi (khong chan trang)" "1" "$([ "$(echo "$MQ" | python -c "import sys,json;d=json.load(sys.stdin)['by_status'];print(sum(int(v) for v in d.values()))")" -ge 1 ] && echo 1 || echo 0)"
# Gia von binh quan gia quyen
probe "a=setting&k=costing_method&v=AVG" >/dev/null
S0=$(stock)
C0=$(curl -s -b $CJ "$A/stock_receipt_search.php?q=REG1" | python -c "import sys,json;print(int(float(json.load(sys.stdin)[0]['cost_price'])))")
SIDX=$(curl -s -b $CJ $A/suppliers.php | grep -o 'supplier_view.php?id=[0-9]*' | head -1 | grep -o '[0-9]*$')
curl -s -b $CJ -c $CJ -o /dev/null --data-urlencode csrf="$(tk $A/stock_receipt_form.php $CJ)" -d "supplier_id=$SIDX&product_id[]=$PID&variant_id[]=&quantity[]=10&cost_price[]=100000&paid_amount=0" $A/stock_receipt_form.php
C1=$(curl -s -b $CJ "$A/stock_receipt_search.php?q=REG1" | python -c "import sys,json;print(int(float(json.load(sys.stdin)[0]['cost_price'])))")
EXP=$(python -c "print(int(round(($S0*$C0+10*100000)/($S0+10))))")
eq "gia von binh quan gia quyen: ($S0 x $C0 + 10 x 100000) / ($S0 + 10) = $EXP" "$EXP" "$C1"
probe "a=setting&k=costing_method&v=LAST" >/dev/null
curl -s -b $CJ -c $CJ -o /dev/null --data-urlencode csrf="$(tk $A/stock_receipt_form.php $CJ)" -d "supplier_id=$SIDX&product_id[]=$PID&variant_id[]=&quantity[]=1&cost_price[]=65000&paid_amount=0" $A/stock_receipt_form.php
eq "mac dinh (gia nhap gan nhat): gia von = gia nhap moi 65000" "65000" "$(curl -s -b $CJ "$A/stock_receipt_search.php?q=REG1" | python -c "import sys,json;print(int(float(json.load(sys.stdin)[0]['cost_price'])))")"
# Vai tro chat: khong kem khach hang/bao hanh neu khong duoc tich
eq "vai tro chat (chi kho): khong vao duoc Khach hang (403)" "403" "$(curl -s -b $W/cj_chat -o /dev/null -w '%{http_code}' $A/customers.php)"
eq "vai tro chat (chi kho): khong vao duoc Bao hanh (403)" "403" "$(curl -s -b $W/cj_chat -o /dev/null -w '%{http_code}' $A/warranty_cards.php)"
eq "vai tro chat: menu khong con muc Khach hang" "0" "$(curl -s -b $W/cj_chat $A/products.php | grep -c 'customers.php')"
eq "thu ngan thuong van vao duoc Khach hang (200)" "200" "$(curl -s -b $W/cj_cash -o /dev/null -w '%{http_code}' $A/customers.php)"

echo "== 6l. Tim don hang"
eq "tim don theo ten khach 'Khach COD'" "1" "$([ "$(curl -s -b $CJ --get --data-urlencode 'q=Khach COD' $A/orders.php | grep -c 'Khach COD')" -ge 1 ] && echo 1 || echo 0)"
eq "tim don theo SDT khach 0922000111" "1" "$([ "$(curl -s -b $CJ "$A/orders.php?q=0922000111" | grep -c 'order_view.php?id=')" -ge 1 ] && echo 1 || echo 0)"
eq "tim khong ra: khong co don nao" "0" "$(curl -s -b $CJ "$A/orders.php?q=khongcoketqua999" | grep -c 'order_view.php?id=')"

echo "== 6z. Quy trinh tap hoa: lo/han tu phieu nhap, xuat huy, han tra NCC, chu rut tien, han muc chiet khau"
T=$(tk $A/suppliers.php $CJ)
D5=$(python -c "import datetime;print((datetime.date.today()+datetime.timedelta(days=5)).isoformat())")
DYEST=$(python -c "import datetime;print((datetime.date.today()-datetime.timedelta(days=2)).isoformat())")
ST2=$(stock)
curl -s -b $CJ -c $CJ -o /dev/null --data-urlencode csrf="$T" -d "supplier_id=$SIDR&product_id[]=$PID&variant_id[]=&quantity[]=5&cost_price[]=60000&lot_number[]=LOTREG&expiry_date[]=$D5&paid_amount=0&due_date=$DYEST&reference_no=REGHD2" $A/stock_receipt_form.php
eq "nhap hang kem lo: ton kho tang 5" "$((ST2+5))" "$(stock)"
BP=$(curl -s -b $CJ $A/batches.php)
eq "lo LOTREG hien o trang Lo hang" "1" "$([ "$(echo "$BP" | grep -c 'LOTREG')" -ge 1 ] && echo 1 || echo 0)"
eq "lo han 5 ngay nam trong canh bao (Sap het han)" "1" "$([ "$(echo "$BP" | grep -c 'Sắp hết hạn')" -ge 1 ] && echo 1 || echo 0)"
DASH=$(curl -s -b $CJ $A/index.php)
eq "trang chu canh bao hang sap het han" "1" "$([ "$(echo "$DASH" | grep -c 'Hàng hết hạn / sắp hết hạn')" -ge 1 ] && echo 1 || echo 0)"
eq "trang chu canh bao no NCC den han" "1" "$([ "$(echo "$DASH" | grep -c 'Nợ nhà cung cấp đến hạn trả')" -ge 1 ] && echo 1 || echo 0)"
eq "cong no hien phieu NCC qua han" "1" "$([ "$(curl -s -b $CJ $A/cong_no.php | grep -c 'Quá hạn')" -ge 1 ] && echo 1 || echo 0)"
BATCH_ID=$(echo "$BP" | grep -o 'xuat_huy.php?batch_id=[0-9]*' | head -1 | grep -o '[0-9]*$')
[ -n "$BATCH_ID" ] && ok "trang Lo hang co nut xuat huy cho lo can han" || fail "nut xuat huy cho lo can han" "co" "khong"
ST3=$(stock)
TX=$(tk $A/xuat_huy.php $CJ)
curl -s -b $CJ -c $CJ -o /dev/null --data-urlencode csrf="$TX" -d "branch_id=$BRR&item=$PID:0&batch_id=$BATCH_ID&quantity=2&reason=EXPIRED&note=reg" $A/xuat_huy.php
eq "xuat huy 2: ton kho giam 2" "$((ST3-2))" "$(stock)"
XP=$(curl -s -b $CJ $A/xuat_huy.php)
eq "lich su xuat huy co ly do Het han su dung" "1" "$([ "$(echo "$XP" | grep -c 'Hết hạn sử dụng')" -ge 1 ] && echo 1 || echo 0)"
BIG=$(curl -s -b $CJ -c $CJ --data-urlencode csrf="$TX" -d "branch_id=$BRR&item=$PID:0&quantity=99999&reason=LOST" $A/xuat_huy.php | grep -c 'vượt tồn kho')
eq "xuat huy vuot ton kho bi tu choi" "1" "$BIG"
eq "ton kho khong doi sau lan bi tu choi" "$((ST3-2))" "$(stock)"
eq "thu ngan khong vao duoc Xuat huy (403)" "403" "$(curl -s -b $W/cj_cash -o /dev/null -w '%{http_code}' $A/xuat_huy.php)"
eq "thu ngan khong vao duoc Hang ton lau (403)" "403" "$(curl -s -b $W/cj_cash -o /dev/null -w '%{http_code}' $A/ton_cham.php)"
eq "trang Hang ton lau / ban cham mo duoc" "1" "$([ "$(curl -s -b $CJ "$A/ton_cham.php?days=365" | grep -c 'Hàng tồn lâu')" -ge 1 ] && echo 1 || echo 0)"
eq "so do chuc nang co Xuat huy hang" "1" "$([ "$(curl -s -b $CJ $A/so_do_chuc_nang.php | grep -c 'xuat_huy.php')" -ge 1 ] && echo 1 || echo 0)"

# Danh gia NCC + nhom nganh
curl -s -b $CJ -c $CJ -o /dev/null --data-urlencode csrf="$T" -d "action=review&kind=LATE&note=giao+tre+reg" "$A/supplier_view.php?id=$SIDR"
curl -s -b $CJ -c $CJ -o /dev/null --data-urlencode csrf="$T" -d "action=category&category=Banh+keo" "$A/supplier_view.php?id=$SIDR"
SV=$(curl -s -b $CJ "$A/supplier_view.php?id=$SIDR")
eq "danh gia NCC ghi nhan 'Giao chậm'" "1" "$([ "$(echo "$SV" | grep -c 'giao tre reg')" -ge 1 ] && echo 1 || echo 0)"
SL=$(curl -s -b $CJ $A/suppliers.php)
eq "danh sach NCC hien nhom nganh + canh bao" "1" "$([ "$(echo "$SL" | grep -c 'Banh keo')" -ge 1 ] && [ "$(echo "$SL" | grep -c '⚠')" -ge 1 ] && echo 1 || echo 0)"

# Chu rut tien: tach rieng khoi chi phi
curl -s -b $CJ -c $CJ -o /dev/null --data-urlencode csrf="$(tk $A/cashbook.php $CJ)" -d "type=OWNER_DRAW&amount=50000&payment_method=CASH&reason=chu+rut+reg" $A/cashbook.php
CBK=$(curl -s -b $CJ $A/cashbook.php)
eq "so quy ghi 'Chu rut' tach rieng" "1" "$([ "$(echo "$CBK" | grep -c 'Chủ rút')" -ge 1 ] && echo 1 || echo 0)"

# Han muc chiet khau nhap tay cua thu ngan
curl -s -b $CJ -c $CJ -o /dev/null --data-urlencode csrf="$(tk $A/sales_settings.php $CJ)" -d "default_discount_unit=AMOUNT&cashier_return_limit=500000&cashier_discount_limit=10" $A/sales_settings.php
DL=$(curl -s -b $W/cj_cash -H 'Content-Type: application/json' -d "{\"csrf\":\"$PTC\",\"payment_method\":\"CASH\",\"manual_discount_type\":\"PERCENT\",\"manual_discount_value\":25,\"items\":[{\"product_id\":$PID,\"variant_id\":null,\"quantity\":1,\"unit_price\":100000}]}" $A/pos_checkout.php | jv "['error']")
eq "thu ngan giam 25% bi chan khi han muc 10%" "1" "$([ "$(echo "$DL" | grep -c 'hạn mức')" -ge 1 ] && echo 1 || echo 0)"
DL2=$(curl -s -b $W/cj_cash -H 'Content-Type: application/json' -d "{\"csrf\":\"$PTC\",\"payment_method\":\"CASH\",\"manual_discount_type\":\"PERCENT\",\"manual_discount_value\":5,\"items\":[{\"product_id\":$PID,\"variant_id\":null,\"quantity\":1,\"unit_price\":100000}]}" $A/pos_checkout.php | jv "['order_id']")
[ -n "$DL2" ] && ok "thu ngan giam 5% (trong han muc) ban duoc" || fail "giam 5% trong han muc" "co don" "khong"
curl -s -b $CJ -c $CJ -o /dev/null --data-urlencode csrf="$(tk $A/sales_settings.php $CJ)" -d "default_discount_unit=AMOUNT&cashier_return_limit=500000&cashier_discount_limit=0" $A/sales_settings.php

# Kiem kho theo nhom hang (nhom khong co hang: tra mang rong; nhom cua tenant khac khong lo)
eq "kiem kho theo nhom: nhom la tra rong" "[]" "$(curl -s -b $CJ "$A/stock_take_search.php?category_id=999999&branch_id=$BRR")"

echo "== 6zz. Thong minh: du bao nhap hang, goi y xa hang, canh bao bat thuong, tro ly hoi dap"
RS=$(curl -s -b $CJ "$A/reorder_suggestions.php?branch_id=$BRR&target_days=14")
eq "goi y nhap hang co cot Xu huong" "1" "$([ "$(echo "$RS" | grep -c 'Xu hướng')" -ge 1 ] && echo 1 || echo 0)"
eq "trang Hang ton lau mo duoc voi goi y xa hang" "200" "$(curl -s -b $CJ -o /dev/null -w '%{http_code}' "$A/ton_cham.php?days=30")"
CB=$(curl -s -b $CJ $A/canh_bao.php)
eq "trang Canh bao bat thuong mo duoc" "1" "$([ "$(echo "$CB" | grep -c 'Cảnh báo bất thường')" -ge 1 ] && echo 1 || echo 0)"
eq "canh bao: phieu nhap qua han tra NCC" "1" "$([ "$(echo "$CB" | grep -c 'Quá hạn trả nhà cung cấp')" -ge 1 ] && echo 1 || echo 0)"
eq "trang chu hien khung canh bao bat thuong" "1" "$([ "$(curl -s -b $CJ $A/index.php | grep -c 'Cảnh báo bất thường')" -ge 1 ] && echo 1 || echo 0)"
eq "thu ngan khong vao duoc Canh bao (403)" "403" "$(curl -s -b $W/cj_cash -o /dev/null -w '%{http_code}' $A/canh_bao.php)"
eq "thu ngan khong vao duoc Tro ly (403)" "403" "$(curl -s -b $W/cj_cash -o /dev/null -w '%{http_code}' $A/tro_ly.php)"
CSRFT=$(curl -s -b $CJ -c $CJ $A/tro_ly.php | grep -o 'const CSRF = "[^"]*"' | sed 's/.*= "//;s/"//')
ask() { python -c "import json,sys;sys.stdout.buffer.write(json.dumps({'csrf':sys.argv[1],'question':sys.argv[2]}).encode())" "$CSRFT" "$1" > "$W/ask.json"; curl -s -b $CJ -c $CJ -H 'Content-Type: application/json' --data-binary @"$W/ask.json" $A/tro_ly.php; }
eq "tro ly: 'Hom nay ban duoc bao nhieu' -> doanh thu" "doanh_thu" "$(ask 'Hôm nay bán được bao nhiêu?' | jv "['intent']")"
eq "tro ly: 'Doanh thu thang nay' -> doanh thu" "doanh_thu" "$(ask 'Doanh thu tháng này' | jv "['intent']")"
eq "tro ly: 'Mat hang nao ban chay' -> ban chay" "ban_chay" "$(ask 'Mặt hàng nào bán chạy tháng này?' | jv "['intent']")"
eq "tro ly: ban chay liet ke SP Reg" "1" "$([ "$(ask 'Mặt hàng nào bán chạy tháng này?' | grep -c 'SP Reg')" -ge 1 ] && echo 1 || echo 0)"
eq "tro ly: 'Ton kho SP Reg' tra dung san pham" "1" "$([ "$(ask 'Tồn kho SP Reg còn bao nhiêu?' | grep -c 'SP Reg')" -ge 1 ] && echo 1 || echo 0)"
eq "tro ly: 'Hang nao can date' -> can han" "can_han" "$(ask 'Hàng nào cận date?' | jv "['intent']")"
eq "tro ly: 'Khach nao dang no' -> khach no" "khach_no" "$(ask 'Khách nào đang nợ?' | jv "['intent']")"
eq "tro ly: 'No nha cung cap' -> no ncc" "no_ncc" "$(ask 'Nợ nhà cung cấp?' | jv "['intent']")"
eq "tro ly: 'Co gi bat thuong khong' -> bat thuong" "bat_thuong" "$(ask 'Có gì bất thường không?' | jv "['intent']")"
eq "tro ly: 'Hang nao ton lau' -> ton lau" "ton_lau" "$(ask 'Hàng nào tồn lâu?' | jv "['intent']")"
eq "tro ly: 'Lai gop thang nay' -> lai gop" "lai_gop" "$(ask 'Lãi gộp tháng này' | jv "['intent']")"
eq "tro ly: cau la -> khong hieu, co huong dan" "khong_hieu" "$(ask 'asdf qwer zxcv' | jv "['intent']")"
eq "tro ly: thieu CSRF bi tu choi (403)" "403" "$(curl -s -b $CJ -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' -d '{"question":"hi"}' $A/tro_ly.php)"

echo "== 6zzz. Phan tich: ABC, dong tien 14 ngay, khach sap bo, combo, ban tin sang"
PT=$(curl -s -b $CJ "$A/phan_tich.php?days=90")
eq "trang Phan tich kinh doanh mo duoc" "1" "$([ "$(echo "$PT" | grep -c 'Phân tích kinh doanh')" -ge 1 ] && echo 1 || echo 0)"
eq "co muc Du bao dong tien 14 ngay" "1" "$([ "$(echo "$PT" | grep -c 'Dự báo dòng tiền 14 ngày')" -ge 1 ] && echo 1 || echo 0)"
eq "dong tien: phieu nhap qua han tinh vao khoan tra NCC" "1" "$([ "$(echo "$PT" | grep -c 'Phải trả NCC trong 14 ngày')" -ge 1 ] && echo 1 || echo 0)"
eq "co 14 dong du bao theo ngay" "14" "$(echo "$PT" | grep -c 'Quỹ cuối ngày\|<td class="text-right" style="font-weight:600;' | python -c "import sys;print(14 if int(sys.stdin.read())>=14 else 0)")"
eq "ABC: co SP Reg thuoc nhom A (hang duy nhat tao lai)" "1" "$([ "$(echo "$PT" | grep -c 'SP Reg')" -ge 1 ] && echo 1 || echo 0)"
eq "phan tich: muc Khach sap bo va Combo goi y mo duoc" "2" "$(echo "$PT" | grep -c 'Khách quen sắp\|Combo gợi ý' | python -c "import sys;print(2 if int(sys.stdin.read())>=2 else 0)")"
eq "trang chu co Ban tin hom nay" "1" "$([ "$(curl -s -b $CJ $A/index.php | grep -c 'Bản tin hôm nay')" -ge 1 ] && echo 1 || echo 0)"
eq "thu ngan khong vao duoc Phan tich (403)" "403" "$(curl -s -b $W/cj_cash -o /dev/null -w '%{http_code}' $A/phan_tich.php)"
eq "tro ly: 'Ban tin hom nay' -> ban tin" "ban_tin" "$(ask 'Hôm nay tôi cần làm gì?' | jv "['intent']")"
eq "tro ly: 'Dong tien sap toi co thieu tien khong' -> dong tien" "dong_tien" "$(ask 'Dòng tiền sắp tới có thiếu tiền không?' | jv "['intent']")"
eq "tro ly: 'Khach quen nao lau khong ghe' -> khach bo" "khach_bo" "$(ask 'Khách quen nào lâu không ghé?' | jv "['intent']")"
eq "tro ly: 'Phan tich ABC nhom A' -> abc" "abc" "$(ask 'Phân tích ABC nhóm A' | jv "['intent']")"
eq "tro ly: 'Combo goi y' -> combo" "combo" "$(ask 'Có combo nào nên bán kèm không?' | jv "['intent']")"

echo "== 7. Doi chieu so lieu sau toan bo kich ban"
DC=$(curl -s -b $CJ $A/doi_chieu.php)
eq "trang doi chieu mo duoc va khong bao lech" "1" "$(echo "$DC" | grep -c 'Không phát hiện chỗ nào lệch')"

echo
echo "KET QUA: $PASS dat, $FAILN hong"
[ "$FAILN" -eq 0 ]
