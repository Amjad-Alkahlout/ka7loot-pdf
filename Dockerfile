# ==============================================================================
# STAGE 1: Frontend Asset Compilation (Node 20 LTS)
# ==============================================================================
FROM node:20-bookworm-slim AS assets

WORKDIR /app

# Install npm dependencies
COPY package.json package-lock.json ./
RUN npm ci

# Copy application source files needed for Vite compilation
COPY vite.config.js ./
COPY resources/ resources/
COPY public/ public/

# Compile production CSS and JS assets
RUN npm run build

# ==============================================================================
# STAGE 2: Production PHP Runtime (Official PHP 8.5-FPM Bookworm)
# ==============================================================================
FROM php:8.5-fpm-bookworm AS production

LABEL maintainer="Amjad Alkahloot <akahloot2005@gmail.com>"
LABEL description="Ka7loot PDF production application container with PDFium, Ghostscript, Poppler, and Node.js runtime"

# Set environment
ENV DEBIAN_FRONTEND=noninteractive
ENV NODE_ENV=production
ENV PDFIUM_PATH=/var/www/html/bin/pdfium/linux-x64/lib/libpdfium.so

# 1. Install system utilities, PDF tools, fonts, and build dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    ghostscript \
    poppler-utils \
    libpng-dev \
    libjpeg62-turbo-dev \
    libwebp-dev \
    libfreetype6-dev \
    libzip-dev \
    libicu-dev \
    fonts-dejavu-core \
    fonts-freefont-ttf \
    curl \
    ca-certificates \
    gnupg \
    unzip \
    fcgiwrap \
    && rm -rf /var/lib/apt/lists/*

# 2. Install Node.js 20 LTS (Required by PDFium CJS runner & MODNet ONNX runner)
RUN mkdir -p /etc/apt/keyrings \
    && curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key | gpg --dearmor -o /etc/apt/keyrings/nodesource.gpg \
    && echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_20.x nodistro main" | tee /etc/apt/sources.list.d/nodesource.list \
    && apt-get update && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

# 3. Configure and install required PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) \
        gd \
        pdo_mysql \
        exif \
        zip \
        intl \
        bcmath \
        opcache

# 4. Install Composer binary from official Composer image
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# 5. Set working directory
WORKDIR /var/www/html

# 6. Install PHP Composer dependencies (production only, no dev, optimized autoloader)
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --optimize-autoloader --no-scripts

# 7. Install Node.js production dependencies (koffi, transformers, reshaper, bidi)
COPY package.json package-lock.json ./
RUN npm ci --omit=dev

# 8. Copy full application codebase (filtered by .dockerignore)
COPY . .

# 9. Copy compiled Vite assets from Stage 1
COPY --from=assets /app/public/build /var/www/html/public/build

# 10. Generate authoritative optimized autoloader
RUN composer dump-autoload --optimize --no-dev

# 11. Copy custom configuration files
COPY docker/php/php.ini $PHP_INI_DIR/conf.d/custom.ini
COPY docker/php/php-fpm.conf /usr/local/etc/php-fpm.d/zz-docker.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh

# 12. Enforce binary and script execution permissions
RUN chmod +x /usr/local/bin/entrypoint.sh \
    && chmod 755 /var/www/html/bin/pdfium/linux-x64/lib/libpdfium.so \
    && chmod +x /var/www/html/bin/*.cjs /var/www/html/bin/*.mjs \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# 13. Enforce immutability on MODNet neural model
RUN if [ -d "/var/www/html/storage/app/models" ]; then chmod -R 555 /var/www/html/storage/app/models; fi

EXPOSE 9000

HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
    CMD php -r "echo 'ok';" || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]

# ==============================================================================
# STAGE 3: Internal Nginx Reverse Proxy (Serving Public Assets & FastCGI)
# ==============================================================================
FROM nginx:1.27-alpine AS web

LABEL maintainer="Amjad Alkahloot <akahloot2005@gmail.com>"
LABEL description="Ka7loot PDF internal web server serving static assets and proxying PHP to app"

WORKDIR /var/www/html

# Copy compiled public web assets from production stage
COPY --from=production /var/www/html/public /var/www/html/public

# Copy custom Nginx configuration
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf

EXPOSE 80

HEALTHCHECK --interval=15s --timeout=3s --start-period=10s --retries=3 \
    CMD wget -qO- http://127.0.0.1/healthz || exit 1

CMD ["nginx", "-g", "daemon off;"]
