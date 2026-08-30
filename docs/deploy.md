# Putting MonyMonk on your VPS

Written for a plain Ubuntu server you control. Everything here is something you run;
none of it is automated, because the pieces that would need automating are the pieces
that hold your customers' books.

Two things are yours to create and hold, and cannot be prepared for you: the server
itself, and the Google OAuth credentials. Both are covered below, but you type them.

## What the server needs

- PHP 8.3 or newer with `bcmath`, `mbstring`, `pdo_mysql`, `zlib`, `intl`, `gd`
- MySQL 8.0.16 or newer — **the version matters**: the ledger's append-only guarantee is
  a set of `CHECK` constraints and triggers, and older MySQL parses `CHECK` and ignores it
- nginx, Node 20+ (to build the assets), Composer, and certbot for HTTPS

```bash
sudo apt update && sudo apt install -y nginx mysql-server certbot python3-certbot-nginx \
  php8.3-fpm php8.3-mysql php8.3-bcmath php8.3-mbstring php8.3-xml php8.3-curl php8.3-gd php8.3-zip
```

## The database

Create it with the collation the application expects. `utf8mb4_0900_ai_ci` is not
cosmetic — counterparty names are Arabic, and a different collation sorts and compares
them differently.

```sql
CREATE DATABASE monymonk CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE USER 'monymonk'@'localhost' IDENTIFIED BY 'a-long-random-password';
GRANT ALL PRIVILEGES ON monymonk.* TO 'monymonk'@'localhost';
FLUSH PRIVILEGES;
```

Type that at the `mysql` prompt, not as `mysql -p<password>` — a password on a command
line ends up in your shell history and in `ps`.

`GRANT ALL` rather than a narrower set because migrations create and drop triggers.

## The application

```bash
sudo mkdir -p /var/www/monymonk && sudo chown "$USER" /var/www/monymonk
git clone <your-repo> /var/www/monymonk && cd /var/www/monymonk
composer install --no-dev --optimize-autoloader
npm ci && npm run build
cp .env.example .env && php artisan key:generate
```

Then edit `.env`. The ones that matter:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain

DB_DATABASE=monymonk
DB_USERNAME=monymonk
DB_PASSWORD=the-password-you-just-set

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
```

`APP_DEBUG=false` above all. Debug on, a stack trace on any error shows the query that
failed, and these queries carry counterparty names and balances.

`SESSION_SECURE_COOKIE=true` because sessions here last a year: a cookie that long must
never travel unencrypted.

```bash
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
sudo chown -R www-data:www-data storage bootstrap/cache
```

There is no seeder to run. The first person to sign up creates the first business.

## nginx

```nginx
server {
    server_name your-domain;
    root /var/www/monymonk/public;

    index index.php;
    charset utf-8;

    # Statements and CSV exports of a long history. The default 1M is not enough.
    client_max_body_size 20M;

    location / { try_files $uri $uri/ /index.php?$query_string; }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Nothing under these is ever served directly.
    location ~ /\.(?!well-known).* { deny all; }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/monymonk /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d your-domain
```

Certbot rewrites the server block to redirect to HTTPS. Check that it did: the session
cookie is marked secure, so over plain HTTP nobody can sign in at all, and the symptom
is a login form that silently does nothing.

## Google sign-in

Yours to create — it needs your Google account, and I should not be typing credentials
into it.

1. https://console.cloud.google.com/apis/credentials → **Create credentials** → **OAuth
   client ID** → **Web application**
2. Authorised redirect URI: `https://your-domain/auth/google/callback` — exactly, including
   the scheme. Google matches it character for character.
3. Put the id and secret in `.env` as `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET`
4. `php artisan config:cache`

The button appears once both are set and not before, so there is never a button that
leads to an error page.

Apple works the same way and is not built yet: it needs the paid Developer Program, a
Services ID and a signing key. When you have those, an `AppleProvider` implementing
`App\Domain\Auth\SocialProvider` and one line in `config/services.php` is the whole job —
nothing else changes.

## Backups

The one thing on this list that is not optional. `storage/backups` is gitignored because
those files contain every customer's name, balance and margin — for the same reason,
they belong off this server.

```bash
# 03:00 daily, then copy off the machine.
0 3 * * * cd /var/www/monymonk && php artisan db:backup >> storage/logs/backup.log 2>&1
```

Test a restore before you need one. A backup nobody has restored is a hypothesis.

## The scheduler

```bash
* * * * * cd /var/www/monymonk && php artisan schedule:run >> /dev/null 2>&1
```

## Checking it is right

```bash
php artisan ledger:verify
```

Works through every business in turn and confirms each one's cached balances agree with
its entries. Worth running from cron and worth having it reach you when it fails — it
exits non-zero, which is what makes that possible.

## Deploying a change

```bash
cd /var/www/monymonk && git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
sudo systemctl reload php8.3-fpm
```

`php artisan down` first if a migration is long. Ordinary ones here are not.
