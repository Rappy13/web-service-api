FROM php:8.2-apache

# 安裝 PDO MySQL 驅動 (db_config.php 需要用到)
RUN docker-php-ext-install pdo pdo_mysql

# 安裝 curl 擴充套件 (mailer.php 呼叫 Resend API 需要用到)
RUN apt-get update && apt-get install -y --no-install-recommends libcurl4-openssl-dev \
    && docker-php-ext-install curl \
    && rm -rf /var/lib/apt/lists/*

# 把前端頁面複製到網站根目錄
COPY public/ /var/www/html/

# 把API複製到 /api/ 路徑下
COPY api/ /var/www/html/api/

# 啟動時把 Apache 監聽的 port 換成 Render 指派的 $PORT
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 10000

ENTRYPOINT ["/entrypoint.sh"]
