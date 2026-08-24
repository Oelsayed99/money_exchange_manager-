# Deploying to a VPS

Written for a plain Ubuntu 24.04 server that you administer yourself. If you later move
to a managed platform (Forge, Ploi), most of this is done for you and the parts worth
keeping are [The first user](#the-first-user-registered-becomes-the-administrator),
[Before you let anyone in](#before-you-let-anyone-in) and the whole of `BACKUP.md`.

Paths below assume the application lives at `/srv/finance` and is served at
`finance.example.com`. Change both to taste.

---

## What the server needs

| | Why |
|---|---|
| PHP 8.3+ | `bcmath` is not optional — every amount in this application is computed with it |
| MySQL 9 | The schema uses `utf8mb4_0900_ai_ci` and enforces append-only rules with triggers |
| Node 20+ | To build the frontend once. Not needed at runtime |
| nginx | Or Apache; nginx is assumed below |

`composer.json` declares `ext-bcmath`, `ext-mbstring`, `ext-pdo_mysql` and `ext-zlib`,
so `composer install` refuses on a server that cannot run this application rather than
succeeding and failing later at the first sum.

```bash
sudo apt update
sudo apt install -y nginx mysql-server php8.3-fpm php8.3-mysql php8.3-bcmath \
    php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-gd unzip git
```

`php8.3-gd` is for mPDF, which renders the Arabic statements.

Node, for the one-off build:

```bash
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs
```

## The database

The collation is not a detail. The schema was created `utf8mb4` from the first
migration because retrofitting it once Arabic data exists is a lossy migration
(ADR 0002).

```sql
CREATE DATABASE finance CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE USER 'finance'@'localhost' IDENTIFIED BY 'a-long-random-password';
GRANT ALL PRIVILEGES ON finance.* TO 'finance'@'localhost';
FLUSH PRIVILEGES;
```

**`ALL PRIVILEGES` is needed, not merely CRUD.** The migrations create triggers, and
`db:backup --verify` creates and drops a scratch database. If you would rather not grant
that, add `--skip-verify` habits instead — but then nothing ever proves the backups
restore, which was the point of them.

MySQL must not listen on the public internet. Confirm `bind-address = 127.0.0.1` in
`/etc/mysql/mysql.conf.d/mysqld.cnf`.

## The application

```bash
sudo mkdir -p /srv/finance && sudo chown $USER:$USER /srv/finance
git clone git@github.com:Oelsayed99/money_exchange_manager-.git /srv/finance
cd /srv/finance
composer install --no-dev --optimize-autoloader
npm ci && npm run build
cp .env.example .env
php artisan key:generate
```

### `.env`

The values that matter for this application specifically:

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://finance.example.com

DB_CONNECTION=mysql
DB_DATABASE=finance
DB_USERNAME=finance
DB_PASSWORD=a-long-random-password

SESSION_DRIVER=database

# Registration sends a verification email, synchronously. Point this at a real
# mailer, or leave it on `log` — see the note below.
MAIL_MAILER=log
```

**Mail is not optional in the way it looks.** `User` implements `MustVerifyEmail`, so
creating an account dispatches a verification notification during the request. With
`MAIL_MAILER=smtp` pointed at a server that does not answer, **registration itself
fails**. Two safe options:

- `MAIL_MAILER=log` — nothing is sent, the link is written to `storage/logs/laravel.log`,
  and registration always succeeds. Fine here: no route requires a verified address, so
  nobody is blocked by ignoring it.
- Configure a real mailer properly and test it by registering a throwaway account.

What you must not do is set `smtp` and hope.

**`APP_DEBUG=false` is not optional.** With it on, any error page prints the stack
trace, the query that failed and the values bound to it — which on this application
means customer names and balances, to whoever triggered the error.

**Back up `.env` somewhere off the server.** It holds `APP_KEY`, which signs every
session and password-reset link. Losing it does not lose the ledger, but it invalidates
everyone's session and every outstanding reset link. See `BACKUP.md`.

### Migrate and seed

```bash
php artisan migrate --force
php artisan db:seed --class=CurrencySeeder --force
php artisan db:seed --class=RolePermissionSeeder --force
```

`--force` is required in production and means "yes, run migrations against the real
database". **Never `migrate:fresh`.** It drops every table, and it has already destroyed
this application's database twice during development.

There is also a `DemoSeeder`. **Do not run it in production** — it invents
counterparties and transactions, and telling those apart from real ones afterwards is
tedious at best.

`RolePermissionSeeder` is idempotent and safe to re-run — do so after any deployment
that adds a permission, or new permissions exist and nobody holds them.

### Permissions

```bash
sudo chown -R www-data:www-data /srv/finance/storage /srv/finance/bootstrap/cache
sudo chmod -R 775 /srv/finance/storage /srv/finance/bootstrap/cache
```

mPDF writes font subsets to `storage/framework/cache/mpdf`, created on demand — it needs
this to be writable or every PDF fails.

### Caching for production

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Re-run all three after every deployment. A stale route cache is a confusing way to lose
an afternoon.

## nginx

```nginx
server {
    listen 80;
    server_name finance.example.com;
    root /srv/finance/public;

    index index.php;
    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Nothing outside public/ is ever served. .env lives one directory up.
    location ~ /\.(?!well-known).* {
        deny all;
    }

    # Statements and CSV exports are generated, not large; this is for uploads of
    # nothing in particular, and keeps a slow client from holding a worker open.
    client_max_body_size 8M;
}
```

```bash
sudo ln -s /etc/nginx/sites-available/finance /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

**The document root is `public/`, not the project directory.** Pointing it at the
project root serves `.env` to anyone who asks for it.

## HTTPS

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d finance.example.com
```

Certbot installs its own renewal timer. Without HTTPS, sessions and passwords cross the
network in the clear.

## Firewall

```bash
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'
sudo ufw enable
```

MySQL (3306) is deliberately not on that list.

## The first user registered becomes the administrator

Read this twice.

`RegisteredUserController` gives the **first** account created full administrator
rights, and every account after it the read-only viewer role. This is deliberate — it is
how you get in without a seeded password — and on a public server it means **whoever
reaches `/register` first owns the system**.

So, immediately after the site is reachable and before you tell anyone about it:

1. Go to `https://finance.example.com/register` and create your account.
2. Confirm you are the administrator: the sidebar shows **Audit trail**, which only an
   administrator sees.

Then create everyone else's account and set their role:

```bash
php artisan user:role someone@example.com operator
```

Registration remains open afterwards, and new accounts get the viewer role. If you would
rather it were closed entirely, that is a small change and worth asking for.

## Backups

Do this on the day you deploy, not the day you remember. See `BACKUP.md`, which covers
the cron entry and — the part people skip — getting copies off the server.

```bash
php artisan db:backup --verify
```

## Before you let anyone in

```bash
php artisan about                     # APP_ENV=production, APP_DEBUG=false
php artisan ledger:verify --transactions
curl -sI https://finance.example.com | head -1
curl -s https://finance.example.com/.env | head -1   # must NOT be your config
```

The last one is the check people forget, and the one that matters most.

## Deploying a change

```bash
cd /srv/finance
php artisan down
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan db:seed --class=RolePermissionSeeder --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan up
```

Take a backup before any deployment that includes a migration:

```bash
php artisan db:backup --verify
```

A migration that goes wrong halfway is the one case where "restore from last night" is
not good enough.

## What this application does not need

Worth knowing, because deployment guides usually assume otherwise:

- **No queue worker.** Nothing is queued. Everything happens in the request.
- **No Redis.** Sessions and cache go to the database, which is one fewer service to
  keep alive and back up.
- **No websockets, no broadcasting.**
- **No `storage:link`.** The application stores no user files, and the local disk's
  file-serving routes are deliberately switched off (ADR 0018).

The only scheduled job is the nightly backup.
