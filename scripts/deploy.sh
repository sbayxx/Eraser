#!/usr/bin/env bash
#
# Deploy the web/ directory to the Gigahost subdomain webroot via FTP.
# Credentials come from config/deploy.php (git-ignored); nothing is hardcoded.
#
# Usage:  bash scripts/deploy.sh
#
# Target layout on Gigahost: webroots/by-route/eraser.governit.dk_/  (subdomain webroot).
# The webserver maps https://eraser.governit.dk/ to this folder, so the
# CONTENT of web/ must be placed there (not the folder itself).

set -uo pipefail
cd "$(dirname "$0")/.." || exit 1

# --- Load deploy config ------------------------------------------------------
read -r FTP_HOST FTP_USER FTP_PASS FTP_DIR <<<"$(php -r '
$c = require "config/deploy.php";
printf("%s %s %s %s\n", $c["ftp"]["host"], $c["ftp"]["user"], $c["ftp"]["password"], $c["ftp"]["remote_dir"]);
')"

case "$FTP_DIR" in
  */) : ;;
  *) FTP_DIR="$FTP_DIR/" ;;
esac
echo "Deployer: indhold af web/  ->  ftp://$FTP_HOST/$FTP_DIR"

# --- Build remote file list (incl. dotfiles like .htaccess) ----
LIST="$(mktemp)"
php -r '
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator("web", FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile()) { continue; }
    if ($f->getFilename() === ".DS_Store") { continue; }
    // web/downloads/ holds ~440 MB of client binaries; those are pushed once
    // (and when updated) by scripts/upload-downloads.sh instead of every deploy.
    if (str_starts_with($it->getSubPathName(), "downloads/")) { continue; }
    echo $it->getSubPathName(), "\n";
}
' >"$LIST"

TOTAL="$(wc -l <"$LIST" | tr -d ' ')"
OK=0; FAIL=0; FAILED_FILES=""

while IFS= read -r REL; do
    REMOTE="$FTP_DIR$REL"
    if curl -sS --max-time 90 --ftp-create-dirs \
        -T "web/$REL" \
        -u "$FTP_USER:$FTP_PASS" \
        "ftp://$FTP_HOST/$REMOTE"; then
        # Best-effort 644 (non-fatal if the host rejects SITE CHMOD).
        curl -sS --max-time 20 -u "$FTP_USER:$FTP_PASS" \
            --quote "SITE CHMOD 644 $REMOTE" -o /dev/null \
            "ftp://$FTP_HOST/" >/dev/null 2>&1 || true
        OK=$((OK+1))
    else
        FAIL=$((FAIL+1))
        FAILED_FILES="$FAILED_FILES $REL"
    fi
done <"$LIST"

rm -f "$LIST"

echo "------------------------------------------------------------"
echo "Uploadet $OK/$TOTAL filer."
if [ -n "$FAILED_FILES" ]; then
    echo "Fejlet:$FAILED_FILES"
    exit 1
fi

echo ""
echo "Verificerer over HTTPS ..."
for url in "https://eraser.governit.dk/" "https://eraser.governit.dk/health" "https://eraser.governit.dk/api/v1/meta" "https://eraser.governit.dk/login.php"; do
    code="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 20 "$url")"
    echo "  GET $url -> $code"
done

echo ""
echo "Deploy færdig."