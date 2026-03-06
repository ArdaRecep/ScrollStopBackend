FROM php:8.4-cli

RUN apt-get update && apt-get install -y \
    git unzip libzip-dev ca-certificates \
    nodejs npm ffmpeg \
    libnss3 libatk-bridge2.0-0 libatk1.0-0 libcups2 libdrm2 libxkbcommon0 \
    libxcomposite1 libxdamage1 libxfixes3 libxrandr2 libgbm1 libasound2 \
    libpangocairo-1.0-0 libpango-1.0-0 libcairo2 libatspi2.0-0 libx11-xcb1 \
  && docker-php-ext-install zip pdo pdo_mysql \
  && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . .

RUN composer install --no-dev --optimize-autoloader --no-interaction \
  && npm --prefix remotion-renderer install --omit=dev --no-audit --no-fund

EXPOSE 8080
CMD php artisan serve --host=0.0.0.0 --port=8080
