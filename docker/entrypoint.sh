#!/bin/sh
set -e

# Render 會透過環境變數 PORT 告訴容器要監聽哪個port，沒有的話預設用10000
PORT="${PORT:-10000}"

sed -i "s/80/${PORT}/g" /etc/apache2/ports.conf
sed -i "s/:80/:${PORT}/g" /etc/apache2/sites-enabled/000-default.conf

exec apache2-foreground
