#!/usr/bin/env bash
# Kiem thu CACH LY GIUA CAC CUA HANG cho cac chuc nang ke toan/nhan su/san xuat/bao gia (chi phi, S3a, tai san, bao gia, hoa don, ho so nhan su, nghi phep, san xuat, kiem ke quy):
#   tools/isolation_new_test.sh   (doc mat khau FTP tu ~/.qlbh_ftp). Thoat 0 neu khong ro ri va khong sua/xoa cheo duoc.
A=https://app.kt-soft.vn
cd "$(dirname "$0")/.." || exit 1
set -u
export PYTHONIOENCODING=utf-8
CRED=$(head -1 ~/.qlbh_ftp | tr -d '\r\n')
W=$(mktemp -d)
tk() { curl -s -b "$2" -c "$2" "$1" | grep -o 'name="csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'; }
reg() { # $1 = jar
  curl -s -c $1 -b $1 "$A/dang-ky.php" -o $W/dk.html; local T=$(grep -o 'name="csrf" value="[^"]*"' $W/dk.html | head -1 | sed 's/.*value="//;s/"//')
  curl -s -c $1 -b $1 -o /dev/null -d "csrf=$T&company_name=ZZTEST Reg&admin_name=Tester&email=zzt$RANDOM$RANDOM@example.com&phone=&password=Test1234&demo_data=1&accept_terms=1" "$A/dang-ky.php"
}
reg $W/a; reg $W/b
post() { local jar=$1 url=$2; shift 2; local T=$(tk "$A/$url" $jar); curl -s -b $jar -c $jar -o /dev/null -w "%{http_code} " --data-urlencode "csrf=$T" "$@" "$A/$url"; }
TD=$(date +%Y-%m-%d)
echo -n "A data: "
post $W/a chi_phi.php -d "action=add&reason=LEAKMARK1&amount=100000&expense_group=RENT&payment_method=BANK_TRANSFER&deductible=1"
post $W/a thue_khac.php -d "action=add&entry_date=$TD&tax_type=BVMT&description=LEAKMARK2&tax_base=1&amount_payable=1&amount_paid=0"
post $W/a phan_bo.php -d "action=asset_add&name=LEAKMARK3&acquired_date=$TD&in_service_date=$TD&cost=1000000&salvage=0&life_months=12"
post $W/a phan_bo.php -d "action=item_add&kind=PREPAID&name=LEAKMARK4&total=1200000&start_month=$(date +%Y-%m)&months=12"
PID=$(curl -s -b $W/a "$A/pos_search.php?q=a" | python -c "import sys,json; print(json.load(sys.stdin)[0]['id'])")
post $W/a quote_form.php -d "customer_name=LEAKMARK5&customer_phone=0900000009&valid_days=10&discount=0" --data-urlencode "lines_json=[{\"id\":$PID,\"qty\":1,\"price\":1000,\"name\":\"x\"}]"
post $W/a hoa_don.php -d "action=add&direction=SALE&symbol=ZZ&number=1&issue_date=$TD&partner_name=LEAKMARK6&amount_before_tax=1000&vat_rate=0&vat_amount="
post $W/a kiem_ke_quy.php -d "count_date=$TD&q[500000]=1&other_amount=0&note=LEAKMARK7"
post $W/a nghi_phep.php -d "action=request&leave_type=ANNUAL&from_date=$(date -d '+4 days' +%Y-%m-%d)&to_date=$(date -d '+4 days' +%Y-%m-%d)&reason=LEAKMARK8"
UA=$(curl -s -b $W/a "$A/nhan_su.php" | grep -o 'nhan_su.php?id=[0-9]*' | head -1 | grep -o '[0-9]*$')
post $W/a "nhan_su.php?id=$UA" -d "user_id=$UA&contract_type=INDEFINITE&insured=0&insured_salary=0&region=1&dependents=0&annual_leave_days=12&note=LEAKMARK9"
M1=$(curl -s -b $W/a "$A/stock_receipt_search.php?q=a" | python -c "import sys,json; d=[x for x in json.load(sys.stdin) if not x['variant_id']]; print(d[0]['id'], d[1]['id'], d[2]['id'])")
read F M2 M3 <<< "$M1"
post $W/a san_xuat.php -d "action=save_recipe&product_id=$F" --data-urlencode "lines_json=[{\"id\":$M2,\"qty\":1}]"
post $W/a san_xuat.php -d "action=produce&product_id=$F&qty=1&prod_date=$TD&note=LEAKMARK10" --data-urlencode "lines_json=[{\"id\":$M2,\"qty\":1}]"
echo
# ids cua A
QA=$(curl -s -b $W/a "$A/quotes.php" | grep -o 'quote_view.php?id=[0-9]*' | head -1 | grep -o '[0-9]*$')
CA=$(curl -s -b $W/a "$A/chi_phi.php" | grep -o 'name="id" value="[0-9]*"' | head -1 | grep -o '[0-9]*')
IA=$(curl -s -b $W/a "$A/hoa_don.php" | grep -o 'name="id" value="[0-9]*"' | head -1 | grep -o '[0-9]*')
LA=$(curl -s -b $W/a "$A/nghi_phep.php" | grep -o 'name="id" value="[0-9]*"' | head -1 | grep -o '[0-9]*')
echo "A ids: quote=$QA expense=$CA invoice=$IA leave=$LA user=$UA finished=$F"
LEAK=0
chk() { local out; out=$(curl -s -b $W/b "$A/$1"); if echo "$out" | grep -q "LEAKMARK"; then echo "  RO RI  $1"; LEAK=$((LEAK+1)); fi; }
for p in "chi_phi.php?from=2000-01-01&to=2099-12-31" thue_khac.php "phan_bo.php?tab=asset" "phan_bo.php?tab=prepaid" "phan_bo.php?tab=deferred" quotes.php "quote_view.php?id=$QA" hoa_don.php "hoa_don.php?tab=purchase" "hoa_don.php?tab=missing" kiem_ke_quy.php nghi_phep.php "nhan_su.php" "nhan_su.php?id=$UA" "san_xuat.php?tab=history" "san_xuat.php?tab=recipe&pid=$F" "san_xuat.php?a=recipe&pid=$F" accounting.php payroll.php "nhom.php?g=Nh%C3%A2n%20s%E1%BB%B1"; do chk "$p"; done
# IDOR ghi: B co gang sua/xoa du lieu cua A
echo -n "B tan cong: "
post $W/b chi_phi.php -d "action=classify&id=$CA&expense_group=OTHER&deductible=0&has_document=0"
post $W/b hoa_don.php -d "action=void&id=$IA"
post $W/b quote_view.php -d "id=$QA&action=delete"
post $W/b nghi_phep.php -d "id=$LA&action=approve"
post $W/b phan_bo.php -d "action=asset_delete&id=1"
UB=$(curl -s -b $W/b "$A/nhan_su.php" | grep -o 'nhan_su.php?id=[0-9]*' | head -1 | grep -o '[0-9]*$')
post $W/b "nhan_su.php?id=$UB" -d "user_id=$UA&contract_type=FIXED&contract_end=2030-01-01&insured=0&insured_salary=0&region=1&dependents=0&annual_leave_days=12&note=HACKED"
echo
# A van nguyen ven?
curl -s -b $W/a "$A/chi_phi.php" | grep -c "LEAKMARK1" | sed 's/^/A con LEAKMARK1 (mong 1+): /'
curl -s -b $W/a "$A/hoa_don.php" | python -c "import sys; s=sys.stdin.read(); print('A hoa don con hieu luc:', 'LEAKMARK6' in s and 'text-decoration:line-through' not in s[s.find('LEAKMARK6')-400:s.find('LEAKMARK6')])"
curl -s -b $W/a "$A/quote_view.php?id=$QA" | grep -c "LEAKMARK5" | sed 's/^/A con bao gia (mong 1+): /'
curl -s -b $W/a "$A/nghi_phep.php" | python -c "import sys; s=sys.stdin.read(); print('A don phep van cho duyet:', 'Chờ duyệt' in s or 'Đơn chờ duyệt' in s)"
curl -s -b $W/a "$A/nhan_su.php?id=$UA" | python -c "import sys; s=sys.stdin.read(); ok=('LEAKMARK9' in s) and ('HACKED' not in s); print('A ho so nhan su nguyen ven:', ok); sys.exit(0 if ok else 1)" || { echo '  RO RI  ho so nhan su bi sua cheo'; LEAK=$((LEAK+1)); }
echo "RO RI: $LEAK"
STATUS=$LEAK
# don dep
KEY2=$(python -c "import secrets;print(secrets.token_hex(16))"); sed "s/__KEY__/$KEY2/" tools/test_probe.php > $W/pr.php
curl -s --user "$CRED" -T $W/pr.php ftp://103.75.184.20/domains/app.kt-soft.vn/_chk_probe.php; curl -s "$A/_chk_probe.php?key=$KEY2&a=cleanup"; echo
curl -s --user "$CRED" -Q "DELE domains/app.kt-soft.vn/_chk_probe.php" ftp://103.75.184.20/ >/dev/null
curl -s --user "$CRED" ftp://103.75.184.20/domains/app.kt-soft.vn/ -l | grep -c "_chk" | sed 's/^/file _chk con lai: /'
exit ${STATUS:-0}
