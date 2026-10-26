#!/usr/bin/env bash
set -Eeuo pipefail

REPO="https://github.com/MsVlogsm/ISP-Mikrotik-Billing-OneClick.git"
APP_DIR="${APP_DIR:-/var/www/isp-billing}"
APP_USER="${APP_USER:-www-data}"
DOMAIN="${DOMAIN:-bill.xlinkbd.net}"
BILLING_PORT="${BILLING_PORT:-8081}"
PORTAL_PORT="${PORTAL_PORT:-8082}"
DB_NAME="${DB_NAME:-isp_billing}"
DB_USER="${DB_USER:-isp_billing}"
PHP_VERSION="8.3"
NODE_MAJOR="22"

log(){ printf '\n[ONE-CLICK] %s\n' "$*"; }
die(){ echo "ERROR: $*" >&2; exit 1; }
trap 'die "Installation failed at line $LINENO. Check the output above."' ERR

[[ $EUID -eq 0 ]] || die "Run as root: sudo bash install.sh"

. /etc/os-release
[[ "${ID:-}" == "ubuntu" ]] || die "Ubuntu LTS is required. Detected: ${PRETTY_NAME:-unknown}"
case "${VERSION_ID:-}" in 22.04|24.04) ;; *) die "Supported Ubuntu versions: 22.04 and 24.04 LTS." ;; esac

if [[ ! -f "$APP_DIR/install.sh" ]]; then
  log "Preparing application directory"
  mkdir -p "$(dirname "$APP_DIR")"
  if [[ -d "$APP_DIR" && -n "$(ls -A "$APP_DIR" 2>/dev/null || true)" ]]; then
    die "$APP_DIR already exists. Set APP_DIR to another path or remove the old deployment first."
  fi
  apt-get update
  apt-get install -y git curl ca-certificates
  git clone --depth 1 "$REPO" "$APP_DIR"
  exec env APP_DIR="$APP_DIR" DOMAIN="$DOMAIN" BILLING_PORT="$BILLING_PORT" PORTAL_PORT="$PORTAL_PORT" DB_NAME="$DB_NAME" DB_USER="$DB_USER" bash "$APP_DIR/install.sh"
fi

log "Installing OS packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y software-properties-common ca-certificates curl git unzip nginx mariadb-server mariadb-client supervisor \
  build-essential pkg-config libzip-dev libpng-dev libjpeg-dev libfreetype6-dev libonig-dev libxml2-dev libcurl4-openssl-dev

PHP_BIN="$(command -v php${PHP_VERSION} || true)"
if [[ -z "$PHP_BIN" ]]; then
  log "Adding maintained PHP repository"
  add-apt-repository -y ppa:ondrej/php
  apt-get update
  apt-get install -y php${PHP_VERSION} php${PHP_VERSION}-cli php${PHP_VERSION}-fpm php${PHP_VERSION}-common php${PHP_VERSION}-mysql php${PHP_VERSION}-mbstring php${PHP_VERSION}-xml php${PHP_VERSION}-curl php${PHP_VERSION}-zip php${PHP_VERSION}-gd php${PHP_VERSION}-bcmath php${PHP_VERSION}-intl php${PHP_VERSION}-opcache
  PHP_BIN="$(command -v php${PHP_VERSION})"
else
  apt-get install -y php${PHP_VERSION}-cli php${PHP_VERSION}-fpm php${PHP_VERSION}-common php${PHP_VERSION}-mysql php${PHP_VERSION}-mbstring php${PHP_VERSION}-xml php${PHP_VERSION}-curl php${PHP_VERSION}-zip php${PHP_VERSION}-gd php${PHP_VERSION}-bcmath php${PHP_VERSION}-intl php${PHP_VERSION}-opcache
fi

PHP_VERSION_ACTUAL="$($PHP_BIN -r 'echo PHP_VERSION;')"
php -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);' || die "PHP 8.3+ is required; detected $PHP_VERSION_ACTUAL"

log "Installing Composer"
if ! command -v composer >/dev/null 2>&1; then
  curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
  php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
  rm -f /tmp/composer-setup.php
fi

log "Installing Node.js ${NODE_MAJOR}.x"
if ! command -v node >/dev/null 2>&1 || [[ "$(node -p 'process.versions.node.split(".")[0]')" -lt "$NODE_MAJOR" ]]; then
  curl -fsSL https://deb.nodesource.com/setup_${NODE_MAJOR}.x | bash -
  apt-get install -y nodejs
fi

log "Enabling services"
systemctl enable --now mariadb nginx "php${PHP_VERSION}-fpm" supervisor

rand_secret(){ tr -dc 'A-Za-z0-9' </dev/urandom | head -c 32; }
DB_PASSWORD="$(rand_secret)"

read -r -p "Company / application name [X-Link LTD]: " APP_NAME_INPUT || true
APP_NAME_INPUT="${APP_NAME_INPUT:-X-Link LTD}"
read -r -p "Primary domain [${DOMAIN}]: " DOMAIN_INPUT || true
DOMAIN_INPUT="${DOMAIN_INPUT:-$DOMAIN}"
DOMAIN="$DOMAIN_INPUT"
read -r -p "Super Admin name [X-Link Super Admin]: " ADMIN_NAME || true
ADMIN_NAME="${ADMIN_NAME:-X-Link Super Admin}"
read -r -p "Super Admin email [admin@example.com]: " ADMIN_EMAIL || true
ADMIN_EMAIL="${ADMIN_EMAIL:-admin@example.com}"
read -r -s -p "Super Admin password (min 12 chars): " ADMIN_PASSWORD; echo
[[ ${#ADMIN_PASSWORD} -ge 12 ]] || die "Admin password must be at least 12 characters."

log "Creating database and dedicated database user"
mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASSWORD}';
ALTER USER '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASSWORD}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL

log "Preparing application"
cd "$APP_DIR"
chown -R root:root "$APP_DIR"
chmod +x artisan install.sh

if [[ ! -f .env ]]; then cp .env.example .env; fi

set_env(){
  local key="$1" value="$2"
  value="${value//\/\\}"
  value="${value//&/\&}"
  value="${value//|/\|}"
  if grep -qE "^${key}=" .env; then
    sed -i "s|^${key}=.*|${key}=${value}|" .env
  else
    printf '\n%s=%s\n' "$key" "$value" >> .env
  fi
}

set_env APP_NAME "\"$APP_NAME_INPUT\""
set_env APP_ENV production
set_env APP_DEBUG false
set_env APP_URL "http://${DOMAIN}:${BILLING_PORT}"
set_env APP_TIMEZONE Asia/Dhaka
set_env DB_CONNECTION mysql
set_env DB_HOST 127.0.0.1
set_env DB_PORT 3306
set_env DB_DATABASE "$DB_NAME"
set_env DB_USERNAME "$DB_USER"
set_env DB_PASSWORD "$DB_PASSWORD"
set_env SESSION_DRIVER database
set_env SESSION_DOMAIN ".${DOMAIN}"
set_env SESSION_COOKIE billing_session
set_env QUEUE_CONNECTION database
set_env SUPER_ADMIN_NAME "\"$ADMIN_NAME\""
set_env SUPER_ADMIN_EMAIL "$ADMIN_EMAIL"
set_env SUPER_ADMIN_PASSWORD "\"$ADMIN_PASSWORD\""
set_env SANCTUM_STATEFUL_DOMAINS "${DOMAIN}:${BILLING_PORT},${DOMAIN}:${PORTAL_PORT},localhost,127.0.0.1"
set_env TRUSTED_PROXIES "127.0.0.1"

log "Installing PHP dependencies"
composer install --no-dev --optimize-autoloader --no-interaction

log "Installing frontend dependencies and building assets"
npm ci --no-audit --no-fund
npm run build

log "Generating application key"
php artisan key:generate --force --no-interaction

log "Preparing storage and database"
php artisan storage:link || true
php artisan migrate --force --seed
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Laravel 12 exposes /up only when health routing is registered. Fail closed if the app cannot boot.
php artisan about >/dev/null

log "Setting runtime permissions"
chown -R "$APP_USER":"$APP_USER" storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache

log "Configuring Nginx on ports ${BILLING_PORT}/${PORTAL_PORT}"
cat > /etc/nginx/sites-available/isp-billing <<NGINX
server {
    listen ${BILLING_PORT};
    server_name ${DOMAIN};
    root ${APP_DIR}/public;
    index index.php;
    client_max_body_size 50M;
    location / { try_files \$uri \$uri/ /index.php?\$query_string; }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_param HTTP_X_FORWARDED_PORT ${BILLING_PORT};
        fastcgi_pass unix:/run/php/php${PHP_VERSION}-fpm.sock;
    }
    location ~ /\. { deny all; }
}
server {
    listen ${PORTAL_PORT};
    server_name ${DOMAIN};
    root ${APP_DIR}/public;
    index index.php;
    client_max_body_size 50M;
    location / { try_files \$uri \$uri/ /index.php?\$query_string; }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_param HTTP_X_FORWARDED_PORT ${PORTAL_PORT};
        fastcgi_pass unix:/run/php/php${PHP_VERSION}-fpm.sock;
    }
    location ~ /\. { deny all; }
}
NGINX
ln -sfn /etc/nginx/sites-available/isp-billing /etc/nginx/sites-enabled/isp-billing
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl reload nginx

log "Configuring queue worker"
cat > /etc/systemd/system/isp-billing-worker.service <<SERVICE
[Unit]
Description=ISP Billing Laravel Queue Worker
After=network.target mariadb.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=${APP_DIR}
ExecStart=/usr/bin/php${PHP_VERSION} artisan queue:work --sleep=3 --tries=3 --timeout=120
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
SERVICE

log "Configuring Laravel scheduler"
cat > /etc/systemd/system/isp-billing-scheduler.service <<SERVICE
[Unit]
Description=ISP Billing Laravel Scheduler
After=network.target mariadb.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=${APP_DIR}
ExecStart=/usr/bin/php${PHP_VERSION} artisan schedule:work
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
SERVICE

systemctl daemon-reload
systemctl enable --now isp-billing-worker.service isp-billing-scheduler.service

log "Running health checks"
systemctl is-active --quiet mariadb || die "MariaDB is not active"
systemctl is-active --quiet nginx || die "Nginx is not active"
systemctl is-active --quiet "php${PHP_VERSION}-fpm" || die "PHP-FPM is not active"
systemctl is-active --quiet isp-billing-worker.service || die "Queue worker is not active"
systemctl is-active --quiet isp-billing-scheduler.service || die "Scheduler is not active"
curl -fsS "http://127.0.0.1:${BILLING_PORT}/up" >/dev/null || die "Billing health endpoint failed"
curl -fsS "http://127.0.0.1:${PORTAL_PORT}/up" >/dev/null || die "Portal health endpoint failed"

cat > /root/isp-billing-install-summary.txt <<SUMMARY
ISP Billing One-Click Installation
===================================
App directory: ${APP_DIR}
Domain: ${DOMAIN}
Billing URL: http://${DOMAIN}:${BILLING_PORT}
Portal URL: http://${DOMAIN}:${PORTAL_PORT}
Database: ${DB_NAME}
Database user: ${DB_USER}
Database password: ${DB_PASSWORD}
Super Admin email: ${ADMIN_EMAIL}

Production safety:
- This installer configures the application server only.
- It does not provision MikroTik/OLT devices, change customer network configuration, or reboot network equipment.
- Router/device credentials remain application configuration and are not generated by this installer.
SUMMARY
chmod 600 /root/isp-billing-install-summary.txt

log "INSTALLATION COMPLETE"
echo "Billing: http://${DOMAIN}:${BILLING_PORT}"
echo "Portal : http://${DOMAIN}:${PORTAL_PORT}"
echo "Credentials saved to /root/isp-billing-install-summary.txt"
