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

exec docker-php-entrypoint apache2-foreground
