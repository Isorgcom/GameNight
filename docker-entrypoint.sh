#!/bin/bash
set -e

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
    curl -fsSL https://cdn.jsdelivr.net/npm/jodit@4.2.7/build/jodit.min.js  -o "$VENDOR/jodit/jodit.min.js"
    curl -fsSL https://cdn.jsdelivr.net/npm/jodit@4.2.7/build/jodit.min.css -o "$VENDOR/jodit/jodit.min.css"
fi

if [ ! -f "$VENDOR/quill/quill.min.js" ]; then
    echo "[entrypoint] Downloading Quill 1.3.7..."
    mkdir -p "$VENDOR/quill"
    curl -fsSL https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.min.js       -o "$VENDOR/quill/quill.min.js"
    curl -fsSL https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.core.min.css -o "$VENDOR/quill/quill.core.min.css"
    curl -fsSL https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.snow.min.css -o "$VENDOR/quill/quill.snow.min.css"
fi

if [ ! -f "$VENDOR/quill-better-table/quill-better-table.js" ]; then
    echo "[entrypoint] Downloading quill-better-table 1.2.3..."
    mkdir -p "$VENDOR/quill-better-table"
    curl -fsSL https://cdn.jsdelivr.net/npm/quill-better-table@1.2.3/dist/quill-better-table.js  -o "$VENDOR/quill-better-table/quill-better-table.js"
    curl -fsSL https://cdn.jsdelivr.net/npm/quill-better-table@1.2.3/dist/quill-better-table.css -o "$VENDOR/quill-better-table/quill-better-table.css"
fi

exec docker-php-entrypoint apache2-foreground
