#!/usr/bin/env bash
# Kiem thu CACH LY GIUA CAC CUA HANG (tenant) tren app.kt-soft.vn:
#   FTP_USERPASS='user:matkhau' tools/isolation_test.sh
# Tao 2 cua hang thu (ten 'ZZTEST Reg'). Cua hang A co du lieu danh dau 'LEAKMARK' o nhieu loai doi tuong;
# dang nhap cua hang B roi (1) mo MOI trang danh sach, (2) mo cac trang xem/sua/in voi id cua A,
# (3) goi cac API tim kiem va xuat file - neu bat ky noi nao tra ve chuoi LEAKMARK la ro ri du lieu.
# Sau do xoa 2 cua hang thu. Thoat 0 neu khong ro ri.
set -u
export PYTHONIOENCODING=utf-8
HOST="${FTP_HOST:-103.75.184.20}"; REMOTE_DIR="${FTP_DIR:-domains/app.kt-soft.vn}"; A="${SITE_URL:-https://app.kt-soft.vn}"
: "${FTP_USERPASS:?Can dat bien moi truong FTP_USERPASS=user:matkhau}"
cd "$(dirname "$0")/.." || exit 1
W=$(mktemp -d)
KEY=$(python -c "import secrets;print(secrets.token_hex(16))")
ftp_put() { curl -s --ftp-create-dirs --user "$FTP_USERPASS" -T "$1" "ftp://$HOST/$REMOTE_DIR/$2"; }
ftp_del() { curl -s --user "$FTP_USERPASS" -Q "DELE $REMOTE_DIR/$1" "ftp://$HOST/" >/dev/null; }
sed "s/__KEY__/$KEY/" tools/test_probe.php > "$W/probe.php"; ftp_put "$W/probe.php" "_chk_probe.php"
probe() { curl -s "$A/_chk_probe.php?key=$KEY&$1"; }
trap 'probe "a=cleanup" >/dev/null; ftp_del "_chk_probe.php"; rm -rf "$W"' EXIT
tk() { curl -s -b "$2" -c "$2" "$1" | grep -o 'name="csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'; }
LEAK=0
leak() { LEAK=$((LEAK+1)); echo "  RO RI  $1"; }

probe "a=cleanup" >/dev/null
CA="$W/a"; CB="$W/b"
for X in a b; do
  curl -s -b $W/$X -c $W/$X -o /dev/null --form-string csrf="$(tk $A/dang-ky.php $W/$X)" --form-string accept_terms=1 --form-string company_name="ZZTEST Reg" --form-string admin_name="Admin $X" --form-string email="zziso_$X@example.com" --form-string password=Test1234 $A/dang-ky.php
done
echo "== Tao du lieu LEAKMARK o cua hang A"
post() { # post <cookie> <url> <data...>  (dang form-urlencoded, tu lay csrf tu chinh trang)
  local c=$1 u=$2; shift 2; local t; t=$(tk "$A/$u" "$c"); curl -s -b "$c" -c "$c" -o /dev/null -w "%{http_code}" --data-urlencode csrf="$t" "$@" "$A/$u"; }
post $CA suppliers.php -d "name=LEAKMARK+NCC&phone=0911222333&address=LEAKMARK" >/dev/null
post $CA coupons.php -d "code=LEAKMARK1&discount_type=PERCENT&discount_value=10&min_order_amount=0&max_uses=5&start_date=2026-01-01&end_date=2027-12-31" >/dev/null
post $CA warranty_policies.php -d "name=LEAKMARK+BH&duration_months=6&note=LEAKMARK" >/dev/null
post $CA promotions.php -d "name=LEAKMARK+KM&discount_percent=5&min_order_amount=0&start_date=2026-01-01&end_date=2027-12-31" >/dev/null
post $CA branches.php -d "action=create&name=LEAKMARK+CN&address=LEAKMARK&phone=1" >/dev/null
post $CA custom_roles.php -d "action=create&name=LEAKMARK+ROLE&permissions[]=products" >/dev/null
post $CA categories.php -d "name=LEAKMARK+CAT" >/dev/null
post $CA brands.php -d "name=LEAKMARK+BRAND" >/dev/null
post $CA customer_form.php -d "name=LEAKMARK+KHACH&phone=0988777666&address=LEAKMARK" >/dev/null
T=$(tk $A/product_form.php $CA)
curl -s -b $CA -c $CA -o /dev/null --form-string csrf="$T" --form-string name="LEAKMARK SP" --form-string sku=LEAKSKU --form-string unit=cai --form-string product_type=NORMAL --form-string cost_price=1000 --form-string sell_price=2000 --form-string initial_qty=50 --form-string min_stock=0 $A/product_form.php
PID=$(curl -s -b $CA "$A/pos_search.php?q=LEAKSKU" | python -c "import sys,json;print(json.load(sys.stdin)[0]['id'])" 2>/dev/null)
SID=$(curl -s -b $CA $A/suppliers.php | grep -o 'supplier_view.php?id=[0-9]*' | head -1 | grep -o '[0-9]*$')
PT=$(curl -s -b $CA -c $CA $A/pos.php | grep -o 'csrfToken = "[^"]*"' | head -1 | sed 's/.*= "//;s/"//')
OID=$(curl -s -b $CA -H 'Content-Type: application/json' -d "{\"csrf\":\"$PT\",\"items\":[{\"product_id\":$PID,\"variant_id\":null,\"quantity\":2,\"unit_price\":2000}],\"payment_method\":\"CASH\",\"customer_phone\":\"0988777666\",\"note\":\"LEAKMARK ghi chu\",\"paid_amount\":1000}" $A/pos_checkout.php | python -c "import sys,json;print(json.load(sys.stdin)['order_id'])" 2>/dev/null)
post $CA purchase_order_form.php -d "supplier_id=$SID&product_id[]=$PID&variant_id[]=&quantity[]=3&cost_price[]=1000&note=LEAKMARK+PO" >/dev/null
post $CA stock_receipt_form.php -d "supplier_id=$SID&product_id[]=$PID&variant_id[]=&quantity[]=4&cost_price[]=1000&reference_no=LEAKMARK-HD&note=LEAKMARK+PN" >/dev/null
BR=$(curl -s -b $CA $A/stock_take_form.php | grep -o 'option value="[0-9]*" selected' | grep -o '[0-9]*' | head -1)
post $CA stock_take_form.php -d "branch_id=$BR&note=LEAKMARK+KIEM&product_id[]=$PID&variant_id[]=&system_qty[]=50&counted_qty[]=49" >/dev/null
# Du lieu moi cua quy trinh tap hoa (lo/han, xuat huy, danh gia + nhom NCC, nhom hang) danh dau LEAKMARK
post $CA stock_receipt_form.php -d "supplier_id=$SID&product_id[]=$PID&variant_id[]=&quantity[]=2&cost_price[]=1000&lot_number[]=LEAKMARK-LOT&expiry_date[]=2026-10-20&due_date=2026-10-01&reference_no=LEAKMARK-HD2" >/dev/null
post $CA xuat_huy.php -d "branch_id=$BR&item=$PID:0&quantity=1&reason=OTHER&note=LEAKMARK+XUATHUY" >/dev/null
post $CA "supplier_view.php?id=$SID" -d "action=review&kind=LATE&note=LEAKMARK+DANHGIA" >/dev/null
post $CA "supplier_view.php?id=$SID" -d "action=category&category=LEAKMARK+NHOMNCC" >/dev/null
CATA=$(curl -s -b $CA $A/stock_take_form.php | grep -o 'value="[0-9]*">LEAKMARK CAT' | head -1 | grep -o '[0-9]*' | head -1)
BATA=$(curl -s -b $CA $A/batches.php | grep -o 'xuat_huy.php?batch_id=[0-9]*' | head -1 | grep -o '[0-9]*$')
for pg in xuat_huy.php batches.php suppliers.php "supplier_view.php?id=$SID"; do [ "$(curl -s -b $CA "$A/$pg" | grep -c LEAKMARK)" -gt 0 ] || { echo "  LOI TEST: A khong thay du lieu LEAKMARK o $pg"; exit 1; }; done
[ -n "$PID" ] && [ -n "$OID" ] || { echo "Khong tao duoc du lieu thu (PID=$PID OID=$OID) - dung"; exit 1; }
CUSTA=$(curl -s -b $CA "$A/customers.php?q=LEAKMARK" | grep -o 'customer_view.php?id=[0-9]*' | head -1 | grep -o '[0-9]*$')
echo "   A: san pham #$PID, don #$OID, NCC #$SID, khach #$CUSTA"

echo "== Dang nhap cua hang B, xac nhan khong thay LEAKMARK"
# Kiem tra phan biet: A tu thay du lieu cua minh (de chac chan LEAKMARK thuc su ton tai).
[ "$(curl -s -b $CA "$A/customers.php?q=LEAKMARK" | grep -c LEAKMARK)" -gt 0 ] && echo "  (A thay du lieu cua chinh no: dung)" || { echo "  LOI TEST: A khong thay du lieu cua minh"; exit 1; }

# 1) Moi trang (GET khong tham so) - bo qua cac trang co tac dung phu / dang xuat / khoa.
SKIP="logout.php lock.php login.php backup.php backup_cron.php backup_download.php daily_cron.php config.php config.sample.php seed.php test_isolation.php tenant_export.php"
N=0
for f in $(ls *.php | grep -v "^inc_\|^fix_\|^_"); do
  case " $SKIP " in *" $f "*) continue;; esac
  N=$((N+1))
  code=$(curl -s -b $CB -o $W/body -w "%{http_code}" "$A/$f")
  if [ "$code" = "200" ] && grep -q LEAKMARK $W/body; then leak "trang $f"; fi
done
echo "   da mo $N trang danh sach/chuc nang voi tai khoan B"

# 2) Trang xem/sua/in voi id cua A (quet quanh id cua A)
sweep() { # sweep <file> <param> <base_id>
  local f=$1 p=$2 id=$3
  for d in -1 0 1; do
    local n=$((id+d)); [ $n -lt 1 ] && continue
    code=$(curl -s -b $CB -o $W/body -w "%{http_code}" "$A/$f?$p=$n")
    if [ "$code" = "200" ] && grep -q LEAKMARK $W/body; then leak "$f?$p=$n"; fi
  done
}
POID=$(curl -s -b $CA $A/purchase_orders.php | grep -o 'purchase_order_view.php?id=[0-9]*' | head -1 | grep -o '[0-9]*$')
RID=$(curl -s -b $CA $A/stock_receipts.php | grep -o 'stock_receipt_view.php?id=[0-9]*' | head -1 | grep -o '[0-9]*$')
TKID=$(curl -s -b $CA $A/stock_takes.php | grep -o 'stock_take_view.php?id=[0-9]*' | head -1 | grep -o '[0-9]*$')
for spec in "order_view.php id $OID" "order_print.php id $OID" "order_edit.php id $OID" "customer_view.php id $CUSTA" "customer_form.php id $CUSTA" \
            "supplier_view.php id $SID" "product_form.php id $PID" "purchase_order_view.php id ${POID:-0}" "stock_receipt_view.php id ${RID:-0}" "stock_take_view.php id ${TKID:-0}" \
            "order_return_form.php order_id $OID" "shipment_form.php order_id $OID" "warranty_card_form.php order_item_id $OID"; do
  set -- $spec; [ "$3" = "0" ] && continue; sweep $1 $2 $3
done
echo "   da quet trang xem/sua/in voi id cua A"

# 3) API tim kiem + xuat file bang tai khoan B
for u in "pos_search.php?q=LEAKSKU" "pos_search.php?q=LEAKMARK" "customer_lookup.php?q=LEAKMARK" "customer_lookup.php?phone=0988777666" "coupon_check.php?code=LEAKMARK1&sub_total=100000" \
         "purchase_order_search.php?q=LEAKMARK" "stock_receipt_search.php?q=LEAKMARK" "stock_take_search.php?q=LEAKSKU" "stock_transfer_search.php?q=LEAKSKU" "price_adjustment_search.php?q=LEAKSKU" \
         "products_export.php" "customers_export.php" "orders_export.php" "cashbook_export.php" "reports.php" "inventory.php?q=LEAKSKU"; do
  code=$(curl -s -b $CB -o $W/body -w "%{http_code}" "$A/$u")
  if grep -q "LEAKMARK" $W/body; then leak "$u (HTTP $code)"; fi
done
# Tim don bang tu khoa cua A: o tim kiem tu in lai tu khoa nen chi kiem tra KHONG co dong don nao
ORD_ROWS=$(curl -s -b $CB --get --data-urlencode "q=LEAKMARK" $A/orders.php | grep -c 'order_view.php?id=')
[ "$ORD_ROWS" -eq 0 ] || leak "orders.php?q=LEAKMARK tra ve $ORD_ROWS don cua cua hang khac"
echo "   da kiem tra API tim kiem va xuat file"

# 4) Thao tac ghi cheo: B khong duoc sua/huy don cua A
T=$(tk $A/pos.php $CB)
curl -s -b $CB -c $CB -o /dev/null --data-urlencode csrf="$T" -d "order_id=$OID&reason=__OTHER__&reason_other=hack" $A/order_cancel.php
S=$(probe "a=state&c=$CUSTA&o=$OID")
[ "$(echo "$S" | python -c "import sys,json;print(json.load(sys.stdin)['order']['status'])" 2>/dev/null)" = "CANCELLED" ] && leak "B huy duoc don cua A (order_cancel.php)"
T=$(tk $A/pos.php $CB)
curl -s -b $CB -c $CB -o /dev/null --data-urlencode csrf="$T" -d "order_id=$OID&amount=1000" $A/order_pay.php
S2=$(probe "a=state&c=$CUSTA&o=$OID")
[ "$(echo "$S" | python -c "import sys,json;print(json.load(sys.stdin)['order']['paid_amount'])")" != "$(echo "$S2" | python -c "import sys,json;print(json.load(sys.stdin)['order']['paid_amount'])")" ] && leak "B thu no vao don cua A (order_pay.php)"
# Ghi cheo cua quy trinh tap hoa: B khong duoc xuat huy hang / lo cua A, khong duoc danh gia NCC cua A
stockA() { curl -s -b $CA "$A/pos_search.php?q=LEAKSKU" | python -c "import sys,json;print(int(float(json.load(sys.stdin)[0]['qty'])))" 2>/dev/null; }
SA0=$(stockA)
T=$(tk $A/xuat_huy.php $CB)
curl -s -b $CB -c $CB -o /dev/null --data-urlencode csrf="$T" -d "branch_id=$BR&item=$PID:0&batch_id=${BATA:-0}&quantity=1&reason=LOST&note=hack" $A/xuat_huy.php
[ "$(stockA)" = "$SA0" ] || leak "B xuat huy duoc hang cua A (xuat_huy.php) - ton A $SA0 -> $(stockA)"
T=$(tk $A/xuat_huy.php $CB)
curl -s -b $CB -c $CB -o /dev/null --data-urlencode csrf="$T" -d "action=review&kind=PRICE_UP&note=LEAKHACKREVIEW" "$A/supplier_view.php?id=$SID"
[ "$(curl -s -b $CA "$A/supplier_view.php?id=$SID" | grep -c LEAKHACKREVIEW)" -eq 0 ] || leak "B ghi danh gia vao NCC cua A (supplier_view.php)"
curl -s -b $CB -c $CB -o /dev/null --data-urlencode csrf="$T" -d "action=category&category=LEAKHACKCAT" "$A/supplier_view.php?id=$SID"
[ "$(curl -s -b $CA $A/suppliers.php | grep -c LEAKHACKCAT)" -eq 0 ] || leak "B doi nhom nganh NCC cua A"
if [ -n "${CATA:-}" ]; then
  [ "$(curl -s -b $CB "$A/stock_take_search.php?category_id=$CATA&branch_id=$BR" | grep -c LEAKMARK)" -eq 0 ] || leak "stock_take_search.php?category_id cua A"
fi
ST=$(curl -s -b $CB "$A/pos_goi_y.php?ids=$PID")
[ "$ST" = "{}" ] || leak "pos_goi_y.php?ids= tra thong ke san pham cua cua hang khac: $ST"
GYB=$(curl -s -b $CB $A/pos_goi_y.php)
[ "$(echo "$GYB" | grep -c LEAKMARK)" -eq 0 ] || leak "pos_goi_y.php (goi y/nhac viec) lo du lieu cua hang khac"
echo "   da thu ghi cheo quy trinh tap hoa (xuat huy, danh gia NCC, nhom hang)"
echo "   da thu ghi cheo (huy don, thu no)"

echo
[ "$LEAK" -eq 0 ] && echo "KET QUA: KHONG co ro ri du lieu giua cac cua hang" || echo "KET QUA: $LEAK CHO RO RI"
[ "$LEAK" -eq 0 ]
