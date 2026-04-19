# Stage 1: Install PHP dependencies
FROM setiawanheribambang/frankenphp-base:8.4 AS php-builder
WORKDIR /app

# Copy the shared package and the app's composer files
COPY packages/shared-core /packages/shared-core
COPY app/leading/composer.json app/leading/composer.lock ./

# Adjust the path for the shared package in composer.json
RUN sed -i 's|"url": "../../packages/shared-core"|"url": "/packages/shared-core"|' composer.json

# Install dependencies (no-scripts to avoid artisan calls without APP_KEY)
RUN composer install --no-interaction --no-scripts --no-autoloader --no-dev --prefer-dist

# Stage 2: Build frontend assets
FROM node:20-slim AS asset-builder
WORKDIR /app
COPY app/leading/package.json app/leading/package-lock.json* ./
RUN npm install
COPY app/leading .
COPY --from=php-builder /app/vendor ./vendor
RUN npm run build

# Stage 3: Final PHP Runtime
FROM setiawanheribambang/frankenphp-base:8.4
WORKDIR /app

# Copy application code first
COPY app/leading .

# Copy optimized vendor from builder (overwrites any local vendor)
COPY --from=php-builder /app/vendor ./vendor

# Copy compiled assets from asset-builder
COPY --from=asset-builder /app/public/build ./public/build

# Generate autoloader and install octane worker file ONLY
# Do NOT run php artisan optimize — it caches config at build time which overrides runtime env vars
RUN composer dump-autoload --optimize --no-dev && \
    php artisan octane:install --server=frankenphp --no-interaction && \
    chown -R www-data:www-data storage bootstrap/cache

# Ensure storage dirs exist
RUN mkdir -p storage/framework/{sessions,views,cache/data} storage/logs bootstrap/cache

# Environment Configuration
ENV FRANKENPHP_CONFIG="" \
    OCTANE_SERVER="frankenphp" \
    SERVER_NAME=":80" \
    APP_ENV=local \
    APP_DEBUG=true

EXPOSE 80 443 443/udp

ENTRYPOINT ["php", "artisan", "octane:frankenphp", "--host=0.0.0.0", "--port=80"]
