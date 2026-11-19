#!/bin/bash
set -euo pipefail

VENDOR="/var/www/html/vendor"

# Download vendor libraries on first start if not present.
# Files are written to the mounted ./www/vendor/ path so they
# persist on the host and are only downloaded once.
#
# Every file is pinned to a SHA-256. These are served from our own origin, so a
# browser has no CDN integrity attribute to check them against; the check
# happens here instead. A download that does not match is discarded and logged
# (the container still starts: the editor, QR or video feature that needed it
# is simply missing). Bumping a version means updating its hash in the same
# change: `curl -fsSL <url> | sha256sum`.

# fetch_verified URL OUT SHA256: download to OUT, keep it only if it matches.
fetch_verified() {
    local url="$1" out="$2" want="$3" got
    if ! curl -fsSL "$url" -o "$out"; then
        echo "[entrypoint] WARNING: download failed: $url"
        rm -f "$out"; return 1
    fi
    got="$(sha256sum "$out" | cut -d' ' -f1)"
    if [ "$got" != "$want" ]; then
        echo "[entrypoint] ERROR: checksum mismatch for $url (expected $want, got $got); not installed."
        rm -f "$out"; return 1
    fi
}
# fetch_pinned URL DEST SHA256: verified download, then swapped into place.
fetch_pinned() {
    fetch_verified "$1" "$2.new" "$3" && mv "$2.new" "$2"
}

if [ ! -f "$VENDOR/phpmailer/PHPMailer.php" ]; then
    echo "[entrypoint] Downloading PHPMailer 7.0.2..."
    mkdir -p "$VENDOR/phpmailer"
    fetch_pinned https://raw.githubusercontent.com/PHPMailer/PHPMailer/v7.0.2/src/Exception.php "$VENDOR/phpmailer/Exception.php" 22ab858ae438d98f58f41f38ad2191d1b0d59570aebea0463a7948cfae1021b7 || true
    fetch_pinned https://raw.githubusercontent.com/PHPMailer/PHPMailer/v7.0.2/src/PHPMailer.php "$VENDOR/phpmailer/PHPMailer.php" 01038c4104554fe7fdb958eb4653883c5cf93af76a3c8797794001c35c1bf553 || true
    fetch_pinned https://raw.githubusercontent.com/PHPMailer/PHPMailer/v7.0.2/src/SMTP.php "$VENDOR/phpmailer/SMTP.php" f7c8210e4047871f9f0b0639d7387d5574738fb9984f744d58d85378da77c68e || true
fi

# Jodit: fetched when missing OR when the pinned version changes (the .version
# marker), so a bump here reaches an install whose vendor copy already exists.
# Downloaded beside the live files and swapped in only when both parts arrived.
JODIT_VERSION="4.18.10"
if [ ! -f "$VENDOR/jodit/jodit.min.js" ] || [ "$(cat "$VENDOR/jodit/.version" 2>/dev/null)" != "$JODIT_VERSION" ]; then
    echo "[entrypoint] Downloading Jodit $JODIT_VERSION..."
    mkdir -p "$VENDOR/jodit"
    if fetch_verified "https://cdn.jsdelivr.net/npm/jodit@${JODIT_VERSION}/es2021/jodit.min.js"  "$VENDOR/jodit/jodit.min.js.new"  06223c1281f1cd5539b06bb61dbe4362363159f490f1ff66801fc0f72f5ea281 \
    && fetch_verified "https://cdn.jsdelivr.net/npm/jodit@${JODIT_VERSION}/es2021/jodit.min.css" "$VENDOR/jodit/jodit.min.css.new" d9ba17faee055dfae5f2aad40e3b6c3d154aa8084a187dbbc33d15114531070f; then
        mv "$VENDOR/jodit/jodit.min.js.new" "$VENDOR/jodit/jodit.min.js"
        mv "$VENDOR/jodit/jodit.min.css.new" "$VENDOR/jodit/jodit.min.css"
        echo "$JODIT_VERSION" > "$VENDOR/jodit/.version"
    else
        echo "[entrypoint] WARNING: Jodit $JODIT_VERSION download failed; keeping the existing copy."
        rm -f "$VENDOR/jodit/jodit.min.js.new" "$VENDOR/jodit/jodit.min.css.new"
    fi
fi

PHPADMIN="/var/www/html/phpadmin"
if [ ! -f "$PHPADMIN/phpliteadmin.php" ]; then
    echo "[entrypoint] Downloading pla-ng 2.0.4..."
    mkdir -p "$PHPADMIN"
    fetch_pinned "https://github.com/emanueleg/pla-ng/releases/download/v2.0.4/phpliteadmin.php" "$PHPADMIN/phpliteadmin.php" 07f7b5d8147d286414225edf0b15535c7560097a2efe26553ca410f128025652 || true
fi

if [ ! -f "$VENDOR/qrcode.min.js" ]; then
    echo "[entrypoint] Downloading qrcode-generator 1.4.4..."
    fetch_pinned https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js "$VENDOR/qrcode.min.js" bb2365e4902f4f84852cf4025e6f6a60325a682aeafa43fb63b7fc8f098d1ef2 || true
fi

# The styled QR drawer (rounded modules, coloured corners, the icon in the
# middle) behind pk-qr.js. Served from our own origin like the rest; pk-qr.js
# falls back to the plain code from qrcode.min.js when this file is missing.
if [ ! -f "$VENDOR/qr-code-styling.min.js" ]; then
    echo "[entrypoint] Downloading qr-code-styling 1.9.2..."
    fetch_pinned https://cdn.jsdelivr.net/npm/qr-code-styling@1.9.2/lib/qr-code-styling.js "$VENDOR/qr-code-styling.min.js" 429de523c7563fc0647e618f5a8ea567d5a16412a13ef5d6a082b4502f5a54f9 || true
fi

if [ ! -f "$VENDOR/nosleep.min.js" ]; then
    echo "[entrypoint] Downloading NoSleep.js 0.12.0..."
    fetch_pinned https://cdn.jsdelivr.net/npm/nosleep.js@0.12.0/dist/NoSleep.min.js "$VENDOR/nosleep.min.js" 887b763e53ecaeba7bdddcacb29f7ffaf9da8a3576c2cca7ea4a1ecd14ff731c || true
fi

# HLS playback for the Timer BETA video cell. Safari plays .m3u8 natively;
# every other browser needs MSE, which this provides. Vendored rather than
# CDN-loaded on purpose: served from our own origin it satisfies
# script-src 'self' and needs no CSP exception.
if [ ! -f "$VENDOR/hls.min.js" ]; then
    echo "[entrypoint] Downloading hls.js 1.5.17..."
    fetch_pinned https://cdn.jsdelivr.net/npm/hls.js@1.5.17/dist/hls.min.js "$VENDOR/hls.min.js" 484054e8cd03d3f6d1781fb7f402bdc318d8a4c527f933a95c624e27cc9a9470 || true
fi

# Self-hosted Google Fonts for the tournament timer theme system.
# Downloaded from fonts.bunny.net (privacy-friendly Google Fonts mirror).
if [ ! -f "$VENDOR/fonts/fonts.css" ]; then
    echo "[entrypoint] Downloading timer fonts..."
    mkdir -p "$VENDOR/fonts"
    curl -fsSL -o "$VENDOR/fonts/inter-400.woff2"          https://fonts.bunny.net/inter/files/inter-latin-400-normal.woff2
    curl -fsSL -o "$VENDOR/fonts/inter-700.woff2"          https://fonts.bunny.net/inter/files/inter-latin-700-normal.woff2
    curl -fsSL -o "$VENDOR/fonts/bebas-neue-400.woff2"     https://fonts.bunny.net/bebas-neue/files/bebas-neue-latin-400-normal.woff2
    curl -fsSL -o "$VENDOR/fonts/orbitron-400.woff2"       https://fonts.bunny.net/orbitron/files/orbitron-latin-400-normal.woff2
    curl -fsSL -o "$VENDOR/fonts/orbitron-700.woff2"       https://fonts.bunny.net/orbitron/files/orbitron-latin-700-normal.woff2
    curl -fsSL -o "$VENDOR/fonts/press-start-2p-400.woff2" https://fonts.bunny.net/press-start-2p/files/press-start-2p-latin-400-normal.woff2
    cat > "$VENDOR/fonts/fonts.css" <<'FONTSCSS'
/* Self-hosted Google Fonts (via fonts.bunny.net mirror). */
@font-face { font-family: "Inter"; font-style: normal; font-weight: 400; font-display: swap; src: url("/vendor/fonts/inter-400.woff2") format("woff2"); }
@font-face { font-family: "Inter"; font-style: normal; font-weight: 700; font-display: swap; src: url("/vendor/fonts/inter-700.woff2") format("woff2"); }
@font-face { font-family: "Bebas Neue"; font-style: normal; font-weight: 400; font-display: swap; src: url("/vendor/fonts/bebas-neue-400.woff2") format("woff2"); }
@font-face { font-family: "Orbitron"; font-style: normal; font-weight: 400; font-display: swap; src: url("/vendor/fonts/orbitron-400.woff2") format("woff2"); }
@font-face { font-family: "Orbitron"; font-style: normal; font-weight: 700; font-display: swap; src: url("/vendor/fonts/orbitron-700.woff2") format("woff2"); }
@font-face { font-family: "Press Start 2P"; font-style: normal; font-weight: 400; font-display: swap; src: url("/vendor/fonts/press-start-2p-400.woff2") format("woff2"); }
FONTSCSS
fi

# ── Upload dir exec-guard ────────────────────────────────────────────────────
# uploads/ is a bind-mounted volume, so the repo's www/uploads/.htaccess is
# shadowed and never reaches the container. Write the guard into the volume on
# every start so user-uploaded files can never be executed as PHP/CGI.
UPLOADS="/var/www/html/uploads"
mkdir -p "$UPLOADS"
cat > "$UPLOADS/.htaccess" <<'UPHT'
# Defense-in-depth: user-uploaded files are static assets only. Never execute.
php_flag engine off
RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .php8 .phar
RemoveType .php .phtml .php3 .php4 .php5 .php7 .php8 .phar
Options -ExecCGI -Indexes
<FilesMatch "\.(php|phtml|php[0-9]|phar|pl|py|cgi|sh)$">
    Require all denied
</FilesMatch>
# Uploads keep their names when replaced (header_banner.png, og_image.png), so
# the parent .htaccess's year-long immutable rule would pin a stale banner in
# every browser. One day instead. A <FilesMatch> on purpose: Apache applies
# Files sections after directory-level directives, so a bare "Header set" here
# would lose to the parent's FilesMatch. Keep identical to www/uploads/.htaccess.
<IfModule mod_headers.c>
    <FilesMatch "\.(css|js|png|jpe?g|webp|gif|svg|ico|woff2?)$">
        Header set Cache-Control "public, max-age=86400"
    </FilesMatch>
</IfModule>
UPHT
chown www-data:www-data "$UPLOADS/.htaccess" 2>/dev/null || true

# ── Scheduled tasks: run cron.php every 5 minutes in the background ──
# Auto-generate a cron token if one doesn't exist yet
CRON_TOKEN=$(php -r "require '/var/www/html/db.php'; \$t = get_setting('cron_token',''); if (\$t==='') { \$t = bin2hex(random_bytes(20)); set_setting('cron_token', \$t); } echo \$t;" 2>/dev/null || echo "")
if [ -n "$CRON_TOKEN" ]; then
    echo "[entrypoint] Starting background scheduler (every 5 min)..."
    (while true; do
        sleep 300
        # The token goes in a header, not the URL, so it never lands in the access log.
        curl -s -H "X-Cron-Token: ${CRON_TOKEN}" "http://localhost/cron.php" > /dev/null 2>&1 || true
    done) &
else
    echo "[entrypoint] WARNING: Could not set up cron token. Scheduled tasks disabled."
fi

exec docker-php-entrypoint apache2-foreground
