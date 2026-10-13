#!/usr/bin/env bash
# Kiem thu DU LIEU MAU: dang ky co/khong tao du lieu mau, tinh nhat quan so sach, xoa sach de bat dau chinh thuc,
# va xoa KHONG anh huong cua hang khac.   FTP_USERPASS='user:matkhau' tools/demo_test.sh
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
PASS=0; FAILN=0
ok()   { PASS=$((PASS+1)); echo "  dat   $1"; }
fail() { FAILN=$((FAILN+1)); echo "  HONG  $1  (mong doi: $2 | thuc te: $3)"; }
eq()   { [ "$2" = "$3" ] && ok "$1" || fail "$1" "$2" "$3"; }
ge()   { [ "$3" -ge "$2" ] 2>/dev/null && ok "$1 ($3)" || fail "$1" ">=$2" "$3"; }
signup() { # signup <cookie> <email> <demo 1|0>
  local extra=""; [ "$3" = "1" ] && extra="--form-string demo_data=1"
  curl -s -b $1 -c $1 -o /dev/null --form-string csrf="$(tk $A/dang-ky.php $1)" --form-string accept_terms=1 --form-string company_name="ZZTEST Reg" --form-string admin_name="Chu $2" --form-string email="$2@example.com" --form-string password=Test1234 $extra $A/dang-ky.php; }
stat() { curl -s -b "$1" "$A/du_lieu_mau.php" | python -c "
import sys,re
h=re.sub(r'<[^>]+>',' ',sys.stdin.read())
for k in ['Sản phẩm','Khách hàng','Nhà cung cấp','Đơn hàng','Phiếu nhập','Phiếu thu/chi']:
    m=re.search(re.escape(k)+r'\s+(\d+)',h)
    print(k+'='+(m.group(1) if m else '?'))
"; }

probe "a=cleanup" >/dev/null
echo "== 1. Dang ky CO du lieu mau"
signup $W/a zzdemo_a 1
S=$(stat $W/a)
ge "co san pham mau" 20 "$(echo "$S" | grep '^Sản phẩm=' | cut -d= -f2)"
ge "co khach hang mau" 10 "$(echo "$S" | grep '^Khách hàng=' | cut -d= -f2)"
ge "co don hang mau (1 tuan)" 70 "$(echo "$S" | grep '^Đơn hàng=' | cut -d= -f2)"
ge "co phieu thu/chi" 80 "$(echo "$S" | grep '^Phiếu thu/chi=' | cut -d= -f2)"
eq "hien banner du lieu mau" "1" "$([ "$(curl -s -b $W/a $A/index.php | grep -c 'dữ liệu mẫu')" -ge 1 ] && echo 1 || echo 0)"
DC=$(curl -s -b $W/a $A/doi_chieu.php)
eq "doi chieu: cong no/tien thu/don huy/ton kho deu khop (chi con 1 muc: ca lech 20.000)" "1" "$(echo "$DC" | grep -c 'Phát hiện <b>1</b> mục')"
eq "doi chieu: ca lech 20.000 hien trong bang" "1" "$([ "$(echo "$DC" | grep -c '20.000')" -ge 1 ] && echo 1 || echo 0)"
RP=$(curl -s -b $W/a "$A/reports.php?from=$(date -d '-8 days' +%Y-%m-%d)&to=$(date +%Y-%m-%d)" | grep -c 'Doanh thu')
ge "bao cao doanh thu hien duoc" 1 "$RP"
eq "trang POS mo duoc khi co du lieu mau" "200" "$(curl -s -b $W/a -o /dev/null -w '%{http_code}' $A/pos.php)"
eq "khong tao them du lieu mau khi da co (chan POST create)" "$(echo "$S" | grep '^Sản phẩm=' | cut -d= -f2)" "$(curl -s -b $W/a -c $W/a -o /dev/null --data-urlencode csrf="$(tk $A/du_lieu_mau.php $W/a)" -d "action=create" $A/du_lieu_mau.php; stat $W/a | grep '^Sản phẩm=' | cut -d= -f2)"

# Doanh thu phai NHAT QUAN giua bao cao (tong, theo kenh, theo ngay), trang tong quan va ke toan thue (da tru hang tra lai)
FR=$(date -d '-8 days' +%Y-%m-%d); TO=$(date +%Y-%m-%d)
REP=$(curl -s -b $W/a "$A/reports.php?from=$FR&to=$TO" | python -c "
import sys,re
h=sys.stdin.buffer.read().decode('utf-8','replace')
h=re.sub(r'<style.*?</style>|<script.*?</script>','',h,flags=re.S)
t=re.sub(r'\\s+',' ',re.sub(r'<[^>]+>',' ',h))
num=lambda x:int(x.replace('.',''))
head=re.search(r'Doanh thu ([\\d.]+) Số đơn hàng',t)
chan=re.search(r'Doanh thu theo kênh bán hàng Kênh bán Số đơn Doanh thu Trực tiếp \\d+ ([\\d.]+)',t)
print((num(head.group(1)) if head else -1), (num(chan.group(1)) if chan else -2))
")
set -- $REP
eq "bao cao: doanh thu tong = doanh thu theo kenh (da tru hang tra lai)" "$1" "$2"
ACC=$(curl -s -b $W/a "$A/accounting.php?nam=$(date +%Y)" | python -c "
import sys,re
h=sys.stdin.buffer.read().decode('utf-8','replace')
h=re.sub(r'<style.*?</style>|<script.*?</script>','',h,flags=re.S)
t=re.sub(r'\\s+',' ',re.sub(r'<[^>]+>',' ',h))
m=re.search(r'Doanh thu năm \\d{4} ([\\d.]+)',t)
print(int(m.group(1).replace('.','')) if m else -3)
")
eq "ke toan thue: doanh thu nam = doanh thu bao cao (cung quy tac tru hang tra lai)" "$1" "$ACC"

SLUGA=$(curl -s -b $W/a $A/online_shop_settings.php | grep -o 'name="shop_slug" value="[^"]*"' | sed 's/.*value="//;s/"//')
IMG=$(curl -s "$A/shop.php?s=$SLUGA" | grep -o 'uploads/products/demo[^"]*' | head -1)
eq "du lieu mau co anh san pham tren trang dat hang cua khach" "200" "$([ -n "$IMG" ] && curl -s -o /dev/null -w '%{http_code}' "$A/$IMG" || echo none)"
eq "du lieu mau: tong quan chu quan co chi so lai gop va tien so quy" "1" "$([ "$(curl -s -b $W/a $A/index.php | grep -c 'Lãi gộp hôm nay')" -ge 1 ] && echo 1 || echo 0)"
eq "du lieu mau: co cong no phai thu/phai tra de xem" "1" "$([ "$(curl -s -b $W/a $A/cong_no.php | grep -c 'order\|customer_view.php')" -ge 1 ] && echo 1 || echo 0)"

echo "== 2. Cua hang khac dang hoat dong (kiem tra xoa khong anh huong)"
signup $W/b zzdemo_b 0
eq "dang ky KHONG chon du lieu mau: khong co banner" "0" "$(curl -s -b $W/b $A/index.php | grep -c 'dữ liệu mẫu')"
T=$(tk $A/product_form.php $W/b)
curl -s -b $W/b -c $W/b -o /dev/null --form-string csrf="$T" --form-string name="SP That B" --form-string sku=REALB --form-string unit=cai --form-string product_type=NORMAL --form-string cost_price=1000 --form-string sell_price=2000 --form-string initial_qty=5 --form-string min_stock=0 $A/product_form.php
eq "cua hang B (khong mau) khong tao duoc du lieu mau khi da co du lieu" "0" "$(curl -s -b $W/b -c $W/b -o /dev/null --data-urlencode csrf="$(tk $A/du_lieu_mau.php $W/b)" -d "action=create" $A/du_lieu_mau.php; curl -s -b $W/b $A/index.php | grep -c 'dữ liệu mẫu')"
eq "cua hang B khong xoa duoc (khong o che do mau)" "1" "$(curl -s -b $W/b -c $W/b -o /dev/null --data-urlencode csrf="$(tk $A/du_lieu_mau.php $W/b)" -d "action=wipe&confirm_text=XOA&confirm_check=1" $A/du_lieu_mau.php; curl -s -b $W/b "$A/pos_search.php?q=REALB" | grep -c REALB)"

echo "== 3. Xoa du lieu mau cua A"
TW=$(tk $A/du_lieu_mau.php $W/a)
N1=$(curl -s -b $W/a -c $W/a --data-urlencode csrf="$TW" -d "action=wipe&confirm_text=SAI&confirm_check=1" $A/du_lieu_mau.php | grep -c 'gõ đúng chữ XOA')
eq "go sai chu xac nhan thi KHONG xoa" "1" "$N1"
eq "du lieu van con sau khi go sai" "1" "$([ "$(stat $W/a | grep '^Sản phẩm=' | cut -d= -f2)" -ge 20 ] && echo 1 || echo 0)"
curl -s -b $W/a -c $W/a -o /dev/null --data-urlencode csrf="$TW" -d "action=wipe&confirm_text=xoa&confirm_check=1" $A/du_lieu_mau.php
eq "sau khi xoa: san pham ve 0" "0" "$(curl -s -b $W/a "$A/products.php" | grep -c 'name="sku"\|product_form.php?id=')"
eq "sau khi xoa: het banner du lieu mau" "0" "$(curl -s -b $W/a $A/index.php | grep -c 'dữ liệu mẫu')"
eq "sau khi xoa: file anh san pham mau da bi xoa khoi may chu" "404" "$(curl -s -o /dev/null -w '%{http_code}' "$A/$IMG?nocache=$RANDOM")"
eq "sau khi xoa: dang nhap admin van binh thuong (200)" "200" "$(curl -s -b $W/a -o /dev/null -w '%{http_code}' $A/index.php)"
eq "sau khi xoa: nhan vien mau da bi xoa" "0" "$(curl -s -b $W/a $A/users.php | grep -c 'demo\.invalid\|Thu ngân Lan')"
eq "sau khi xoa: cua hang trong, cho phep tao lai du lieu mau" "1" "$([ "$(curl -s -b $W/a $A/du_lieu_mau.php | grep -c 'Tạo dữ liệu mẫu 1 tuần')" -ge 1 ] && echo 1 || echo 0)"
DC=$(curl -s -b $W/a $A/doi_chieu.php)
eq "sau khi xoa: doi chieu sach (khong con muc lech)" "1" "$(echo "$DC" | grep -c 'Không phát hiện chỗ nào lệch')"
eq "cua hang B nguyen ven sau khi xoa cua hang A" "1" "$(curl -s -b $W/b "$A/pos_search.php?q=REALB" | grep -c REALB)"

echo "== 4. Tao du lieu mau sau (cua hang dang trong) roi xoa lai"
curl -s -b $W/a -c $W/a -o /dev/null --data-urlencode csrf="$(tk $A/du_lieu_mau.php $W/a)" -d "action=create" $A/du_lieu_mau.php
ge "tao sau khi dang ky: co don hang mau" 70 "$(stat $W/a | grep '^Đơn hàng=' | cut -d= -f2)"
TW=$(tk $A/du_lieu_mau.php $W/a)
curl -s -b $W/a -c $W/a -o /dev/null --data-urlencode csrf="$TW" -d "action=wipe&confirm_text=XOA&confirm_check=1" $A/du_lieu_mau.php
eq "xoa lai lan 2: cua hang trong" "1" "$([ "$(curl -s -b $W/a $A/du_lieu_mau.php | grep -c 'Tạo dữ liệu mẫu 1 tuần')" -ge 1 ] && echo 1 || echo 0)"

echo "== 5. Du lieu that nhap sau khi tao mau van duoc canh bao/duoc xoa cung (kiem tra thong bao)"
curl -s -b $W/a -c $W/a -o /dev/null --data-urlencode csrf="$(tk $A/du_lieu_mau.php $W/a)" -d "action=create" $A/du_lieu_mau.php
eq "trang xoa co canh bao 'kể cả những gì bạn đã tự nhập thêm'" "1" "$([ "$(curl -s -b $W/a $A/du_lieu_mau.php | grep -c 'đã tự nhập thêm')" -ge 1 ] && echo 1 || echo 0)"

echo
echo "KET QUA: $PASS dat, $FAILN hong"
[ "$FAILN" -eq 0 ]
