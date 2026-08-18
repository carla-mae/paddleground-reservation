FROM php:8.2-apache

# --- PHP extensions the app needs (mysqli is used throughout: db.php, book.php, etc.) ---
RUN docker-php-ext-install mysqli pdo pdo_mysql

# --- Apache setup ---
RUN a2enmod rewrite headers

# Copy the whole project (index.php, auth/, admin/, customer/, staff/, config/,
# includes/, assets/, PHPMailer/, cron/, uploads/) into Apache's web root.
COPY . /var/www/html/

# uploads/receipts must exist and be writable (upload_receipt.php / payment.php write here)
RUN mkdir -p /var/www/html/uploads/receipts \
    && chown -R www-data:www-data /var/www/html/uploads \
    && chmod -R 775 /var/www/html/uploads

# Render injects a $PORT env var and expects the container to listen on it.
# This entrypoint rewrites Apache's port config to match at startup.
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 10000

ENTRYPOINT ["/entrypoint.sh"]
