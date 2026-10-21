#!/usr/bin/env bash
# Deploy an toan len app.kt-soft.vn qua FTP:
#   1. Sao luu ban dang chay tren server cua tung file (vao tools/backups/<thoi gian>/)
#   2. Tai len thanh _chk_<ten>.php va chay php -l TREN SERVER - co file loi thi dung, khong dong gi file that
#   3. Doi ten de len file that, so sanh tung byte voi ban local, don file tam
#
# Cach dung (khong luu mat khau trong file nao):
#   FTP_USERPASS='user:matkhau' tools/deploy.sh pos.php order_cancel.php ...
#   FTP_USERPASS=... tools/deploy.sh --rollback tools/backups/20261008-101500   # tra ve ban cu
set -u
HOST="${FTP_HOST:-103.75.184.20}"
REMOTE_DIR="${FTP_DIR:-domains/app.kt-soft.vn}"
SITE="${SITE_URL:-https://app.kt-soft.vn}"
# Mat khau FTP: lay tu bien moi truong, neu chua co thi doc tu tep ~/.qlbh_ftp (1 dong user:matkhau) - khong can go mat khau vao cua so chat/lich su lenh
[ -z "${FTP_USERPASS:-}" ] && [ -f "$HOME/.qlbh_ftp" ] && FTP_USERPASS=$(head -n1 "$HOME/.qlbh_ftp" | tr -d "$(printf '\r\n')") && export FTP_USERPASS
: "${FTP_USERPASS:?Can dat bien moi truong FTP_USERPASS=user:matkhau}"
cd "$(dirname "$0")/.." || exit 1
BASE="ftp://$HOST/$REMOTE_DIR"
ftp_put() { curl -s --ftp-create-dirs --user "$FTP_USERPASS" -T "$1" "$BASE/$2"; }
ftp_get() { curl -s --fail --user "$FTP_USERPASS" "$BASE/$1" -o "$2"; }
ftp_del() { curl -s --user "$FTP_USERPASS" -Q "DELE $REMOTE_DIR/$1" "ftp://$HOST/" >/dev/null; }
ftp_mv()  { curl -s --user "$FTP_USERPASS" -Q "RNFR $REMOTE_DIR/$1" -Q "RNTO $REMOTE_DIR/$2" "ftp://$HOST/" >/dev/null; }

if [ "${1:-}" = "--rollback" ]; then
  DIR="${2:?Chi ra thu muc sao luu}"
  for f in "$DIR"/*; do
    n=$(basename "$f"); echo "tra ve $n"; ftp_put "$f" "$n"
  done
  echo "Da tra ve $(ls "$DIR" | wc -l) file."; exit 0
fi

[ $# -gt 0 ] || { echo "Dung: tools/deploy.sh file1.php [file2.php ...]"; exit 1; }
STAMP=$(date +%Y%m%d-%H%M%S); BK="tools/backups/$STAMP"; mkdir -p "$BK"
for f in "$@"; do
  [ -f "$f" ] || { echo "Khong co file local: $f"; exit 1; }
  ftp_get "$f" "$BK/$f" || rm -f "$BK/$f"   # file moi chua co tren server thi khong co ban sao luu
done
echo "Da sao luu $(ls "$BK" | wc -l) file cu vao $BK"

PHP_FILES=(); for f in "$@"; do case "$f" in *.php) PHP_FILES+=("$f");; esac; done
LINT=$(mktemp)
echo '<?php header("Content-Type: text/plain"); foreach (glob(__DIR__."/_chk_*.php") as $f) { if (basename($f)=="_chk_lint.php") continue; $o=[]; exec("php -l ".escapeshellarg($f)." 2>&1",$o); echo basename($f).": ".implode(" ",$o)."\n"; }' > "$LINT"
for f in "${PHP_FILES[@]}"; do ftp_put "$f" "_chk_$(basename "$f")"; done
ftp_put "$LINT" "_chk_lint.php"; rm -f "$LINT"
RESULT=$(curl -s "$SITE/_chk_lint.php")
cleanup_tmp() { for f in "${PHP_FILES[@]}"; do ftp_del "_chk_$(basename "$f")"; done; ftp_del "_chk_lint.php"; }
BAD=$(echo "$RESULT" | grep -v "No syntax errors" | grep -c "_chk_")
if [ "$BAD" -gt 0 ] || [ "$(echo "$RESULT" | grep -c "No syntax errors")" -ne "${#PHP_FILES[@]}" ]; then
  echo "LOI CU PHAP - khong deploy:"; echo "$RESULT" | grep -v "No syntax errors"; cleanup_tmp; exit 2
fi
echo "Lint server: ${#PHP_FILES[@]} file PHP khong loi cu phap"

for f in "$@"; do
  case "$f" in
    *.php) ftp_mv "_chk_$(basename "$f")" "$f" ;;
    *)     ftp_put "$f" "$f" ;;
  esac
done
ftp_del "_chk_lint.php"
FAIL=0
for f in "$@"; do
  if curl -s --user "$FTP_USERPASS" "$BASE/$f" | cmp -s - "$f"; then echo "  ok   $f"; else echo "  LECH $f"; FAIL=1; fi
done
LEFT=$(curl -s --user "$FTP_USERPASS" "$BASE/" | grep -c "_chk_")
echo "File tam con lai tren server: $LEFT"
[ "$FAIL" -eq 0 ] && echo "DEPLOY XONG. Neu co su co: tools/deploy.sh --rollback $BK" || { echo "CO FILE LECH - kiem tra lai"; exit 3; }
