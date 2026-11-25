#!/usr/bin/env bash
#
# Upload web/downloads/ (client program binaries, ~440 MB) to the Gigahost
# subdomain webroot via FTP. These files are excluded from deploy.sh, so run
# this once after the first upload and again whenever the binaries change.
#
# Usage:  bash scripts/upload-downloads.sh [filename ...]
#         (no arguments = upload every file in web/downloads/)

set -uo pipefail
cd "$(dirname "$0")/.." || exit 1

read -r FTP_HOST FTP_USER FTP_PASS FTP_DIR <<<"$(php -r '
$c = require "config/deploy.php";
printf("%s %s %s %s\n", $c["ftp"]["host"], $c["ftp"]["user"], $c["ftp"]["password"], $c["ftp"]["remote_dir"]);
')"

case "$FTP_DIR" in
  */) : ;;
  *) FTP_DIR="$FTP_DIR/" ;;
esac

if [ "$#" -gt 0 ]; then
    FILES=("$@")
else
    shopt -s nullglob
    FILES=(web/downloads/*)
fi

echo "Uploader ${#FILES[@]} fil(er) -> ftp://$FTP_HOST/${FTP_DIR}downloads/"
OK=0; FAIL=0; FAILED=""
for f in "${FILES[@]}"; do
    base="$(basename "$f")"
    printf '  %s ... ' "$base"
    if curl -sS --max-time 1800 --ftp-create-dirs \
        -T "$f" \
        -u "$FTP_USER:$FTP_PASS" \
        "ftp://$FTP_HOST/${FTP_DIR}downloads/$base"; then
        curl -sS --max-time 30 -u "$FTP_USER:$FTP_PASS" \
            --quote "SITE CHMOD 644 ${FTP_DIR}downloads/$base" -o /dev/null \
            "ftp://$FTP_HOST/" >/dev/null 2>&1 || true
        echo "ok"
        OK=$((OK+1))
    else
        echo "FEJL"
        FAIL=$((FAIL+1)); FAILED="$FAILED $base"
    fi
done

echo "------------------------------------------------------------"
echo "Uploadet $OK/${#FILES[@]} fil(er)."
[ "$FAIL" -gt 0 ] && { echo "Fejlet:$FAILED"; exit 1; }
exit 0
