#!/bin/bash
set -euo pipefail

VENDOR="/var/www/html/vendor"

# Download vendor libraries on first start if not present.
# Files are written to the mounted ./www/vendor/ path so they
# persist on the host and are only downloaded once.

if [ ! -f "$VENDOR/phpmailer/PHPMailer.php" ]; then
    echo "[entrypoint] Downloading PHPMailer 7.0.2..."
    mkdir -p "$VENDOR/phpmailer"
    curl -fsSL https://raw.githubusercontent.com/PHPMailer/PHPMailer/v7.0.2/src/Exception.php -o "$VENDOR/phpmailer/Exception.php"
    curl -fsSL https://raw.githubusercontent.com/PHPMailer/PHPMailer/v7.0.2/src/PHPMailer.php  -o "$VENDOR/phpmailer/PHPMailer.php"
    curl -fsSL https://raw.githubusercontent.com/PHPMailer/PHPMailer/v7.0.2/src/SMTP.php        -o "$VENDOR/phpmailer/SMTP.php"
fi

if [ ! -f "$VENDOR/jodit/jodit.min.js" ]; then
    echo "[entrypoint] Downloading Jodit 4.2.7..."
    mkdir -p "$VENDOR/jodit"
    curl -fsSL https://cdn.jsdelivr.net/npm/jodit@4.2.7/es2021/jodit.min.js  -o "$VENDOR/jodit/jodit.min.js"
    curl -fsSL https://cdn.jsdelivr.net/npm/jodit@4.2.7/es2021/jodit.min.css -o "$VENDOR/jodit/jodit.min.css"
fi

PHPADMIN="/var/www/html/phpadmin"
if [ ! -f "$PHPADMIN/phpliteadmin.php" ]; then
    echo "[entrypoint] Downloading pla-ng 2.0.4..."
    mkdir -p "$PHPADMIN"
    curl -fsSL "https://github.com/emanueleg/pla-ng/releases/download/v2.0.4/phpliteadmin.php" -o "$PHPADMIN/phpliteadmin.php"
fi

if [ ! -f "$VENDOR/qrcode.min.js" ]; then
    echo "[entrypoint] Downloading qrcode-generator 1.4.4..."
    curl -fsSL https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js -o "$VENDOR/qrcode.min.js"
fi

if [ ! -f "$VENDOR/nosleep.min.js" ]; then
    echo "[entrypoint] Downloading NoSleep.js 0.12.0..."
    curl -fsSL https://cdn.jsdelivr.net/npm/nosleep.js@0.12.0/dist/NoSleep.min.js -o "$VENDOR/nosleep.min.js"
fi

# HLS playback for the Timer BETA video cell. Safari plays .m3u8 natively;
# every other browser needs MSE, which this provides. Vendored rather than
# CDN-loaded on purpose: served from our own origin it satisfies
# script-src 'self' and needs no CSP exception.
if [ ! -f "$VENDOR/hls.min.js" ]; then
    echo "[entrypoint] Downloading hls.js 1.5.17..."
    curl -fsSL https://cdn.jsdelivr.net/npm/hls.js@1.5.17/dist/hls.min.js -o "$VENDOR/hls.min.js"
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
UPHT
chown www-data:www-data "$UPLOADS/.htaccess" 2>/dev/null || true

# ── Scheduled tasks: run cron.php every 5 minutes in the background ──
# Auto-generate a cron token if one doesn't exist yet
CRON_TOKEN=$(php -r "require '/var/www/html/db.php'; \$t = get_setting('cron_token',''); if (\$t==='') { \$t = bin2hex(random_bytes(20)); set_setting('cron_token', \$t); } echo \$t;" 2>/dev/null || echo "")
if [ -n "$CRON_TOKEN" ]; then
    echo "[entrypoint] Starting background scheduler (every 5 min)..."
    (while true; do
        sleep 300
        curl -s "http://localhost/cron.php?token=${CRON_TOKEN}" > /dev/null 2>&1 || true
    done) &
else
    echo "[entrypoint] WARNING: Could not set up cron token. Scheduled tasks disabled."
fi

exec docker-php-entrypoint apache2-foreground
