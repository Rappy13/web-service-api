FROM php:8.2-apache

# 安裝 PDO MySQL 驅動 (db_config.php 需要用到)
RUN docker-php-ext-install pdo pdo_mysql

# composer 安裝套件需要 unzip 才能解壓縮下載的zip套件包
RUN apt-get update && apt-get install -y --no-install-recommends unzip git \
    && rm -rf /var/lib/apt/lists/*

# 安裝 composer 並抓取 PHPMailer 套件
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY composer.json ./
RUN composer install --no-dev --optimize-autoloader --no-interaction

# 把前端頁面複製到網站根目錄
COPY public/ /var/www/html/

# 把API複製到 /api/ 路徑下
COPY api/ /var/www/html/api/

# 啟動時把 Apache 監聽的 port 換成 Render 指派的 $PORT
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 10000

ENTRYPOINT ["/entrypoint.sh"]
