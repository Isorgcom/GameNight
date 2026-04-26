FROM php:8.5-apache

# Build and install latest SQLite from source
RUN apt-get update && apt-get install -y gcc make unzip && rm -rf /var/lib/apt/lists/*

RUN curl -fsSL https://www.sqlite.org/2026/sqlite-autoconf-3510300.tar.gz -o /tmp/sqlite.tar.gz \
    && tar -xzf /tmp/sqlite.tar.gz -C /tmp \
    && cd /tmp/sqlite-autoconf-3510300 \
    && CFLAGS="-DSQLITE_ENABLE_COLUMN_METADATA=1" ./configure --prefix=/usr/local \
    && make -j$(nproc) \
    && make install \
    && ldconfig \
    && rm -rf /tmp/sqlite*

# Build pdo_sqlite against the newly installed SQLite
RUN docker-php-ext-configure pdo_sqlite --with-pdo-sqlite=/usr/local \
    && docker-php-ext-install pdo pdo_sqlite

# Enable .htaccess overrides and mod_rewrite
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
    && a2enmod rewrite

# Tune prefork MPM for a small-RAM host. Default MaxRequestWorkers=150 can OOM a
# 512 MB VPS at peak load; cap at 25 to keep memory bounded. PHP can use up to
# memory_limit (128 MB) per request, so 25 workers ~ 3 GB worst case headroom.
RUN printf '<IfModule mpm_prefork_module>\n    StartServers            3\n    MinSpareServers         3\n    MaxSpareServers         8\n    MaxRequestWorkers       25\n    MaxConnectionsPerChild  500\n</IfModule>\n' > /etc/apache2/conf-available/mpm-tuning.conf \
    && a2enconf mpm-tuning

# Raise PHP upload limits
RUN echo "upload_max_filesize=20M\npost_max_size=22M" > /usr/local/etc/php/conf.d/uploads.ini

COPY www/ /var/www/html/

# Create the DB directory owned by www-data so SQLite can write at runtime
RUN mkdir -p /var/db && chown www-data:www-data /var/db

# Entrypoint downloads vendor libs on first start (written to mounted ./www/vendor/)
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
