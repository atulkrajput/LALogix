# Deploying lalogix.com

CircleCI builds every push and deploys `master` only (see `.circleci/`). The build
runs `npm run build`, which now produces two bundles:

| Output | What it is |
|---|---|
| `public/build/` | the browser bundle (always needed) |
| `bootstrap/ssr/ssr.js` | the Inertia **server-side rendering** bundle (optional, see below) |

Both are uploaded with the rest of the app. `storage/` and `.env` are never uploaded.

## After each deploy that changes the database or config

FTP deploys cannot run commands, so on the server (SSH or the hosting panel's terminal):

```bash
cd /path/to/lalogix            # the folder that holds artisan
php artisan migrate --force    # new tables / columns (e.g. lead fields, lead_events)
php artisan optimize:clear     # clear old config / route / view caches
php artisan optimize           # cache them again
```

Run `php artisan config:clear` (or `optimize`) whenever `config/lalogix.php` or `.env` changes,
otherwise a cached config keeps the old values.

## New `.env` settings

```dotenv
# Show "TODO:" placeholders on the site (leave false/unset in production).
SHOW_TODOS=false
# Where new-lead alerts are e-mailed (default info@lalogix.com).
LEAD_ALERT_EMAIL=info@lalogix.com
# WhatsApp username, without @. Buttons link to https://wa.me/{username}; no phone number is shown.
WHATSAPP_USERNAME=lalogixenterprises

# AI chat widget (proxies to the VIVK API; the key stays on the server).
CHAT_WIDGET_ENABLED=false
VIVK_API_URL=
VIVK_API_KEY=
VIVK_API_MODEL=

# Inertia SSR (see below). Leave enabled; it is skipped automatically when the SSR process is not running.
INERTIA_SSR_ENABLED=true
INERTIA_SSR_URL=http://127.0.0.1:13714

# Lead alerts are queued. With QUEUE_CONNECTION=sync they are sent right after the
# response; with "database" a queue worker (cron below) must run.
QUEUE_CONNECTION=sync
```

## Server-side rendering (SSR)

Without SSR the server sends an empty `<div id="app">` and the page is drawn by
JavaScript. With SSR, a small Node process renders each public page to HTML first,
so crawlers, link previews and slow phones get the full content (H1, text, links)
in the first response.

**SSR is optional.** If the Node process is not running, Laravel cannot reach it and
falls back to normal client-side rendering automatically: nothing breaks, the page
is just not pre-rendered. Admin pages (`/admin`) are never server-rendered.

Requirements: Node.js 18+ on the server, and a way to keep a process running
(Supervisor, PM2, or the hosting panel's "Node.js app" feature). Many shared hosting
plans do not allow long-running processes; on those plans just skip this section.

The SSR bundle has all its dependencies bundled in, so the server does **not** need
`node_modules` or `npm install`.

### Option A: Supervisor (VPS)

`/etc/supervisor/conf.d/lalogix-ssr.conf`:

```ini
[program:lalogix-ssr]
command=php /path/to/lalogix/artisan inertia:start-ssr
directory=/path/to/lalogix
user=www-data
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
redirect_stderr=true
stdout_logfile=/path/to/lalogix/storage/logs/ssr.log
```

```bash
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl start lalogix-ssr
```

### Option B: PM2

```bash
cd /path/to/lalogix
pm2 start "php artisan inertia:start-ssr" --name lalogix-ssr
pm2 save && pm2 startup     # restart it after a server reboot
```

### After every deploy: restart SSR

The SSR process keeps the old bundle in memory, so restart it after each deploy:

```bash
php artisan inertia:stop-ssr    # Supervisor / PM2 starts it again automatically
```

(For the rsync deploy this can be added to the post-deploy commands in `.circleci/deploy.sh`.)

### Check that SSR works

```bash
php artisan inertia:check-ssr                # "Inertia SSR server is running."
curl -s https://lalogix.com/ | grep -o '<h1[^>]*>[^<]*'
```

The `curl` output must contain the homepage H1
(`Ready-to-use software for schools, dairies and shops…`). If it is empty, the SSR
process is not running and the site is rendering client-side only.

## Queue worker for lead alerts (only if QUEUE_CONNECTION=database)

Add a cron job (every minute):

```cron
* * * * * cd /path/to/lalogix && php artisan queue:work --stop-when-empty --max-time=50 >/dev/null 2>&1
```

## Redirects

- `www.lalogix.com/*` → `https://lalogix.com/*` (301) is done in `public/.htaccess` and
  again in Laravel (`App\Http\Middleware\CanonicalHost`) in case `.htaccess` rules are off.
- Old URLs (`/blog`, `/custom-automation`, `/services`, `/solutions`,
  `/products/dairy-management`) are 301-redirected in `routes/web.php`.

## Images

`node scripts/generate-images.mjs` regenerates the Open Graph images and the product
screenshot placeholders. When real screenshots are ready, save them as WebP with the same
names (`public/images/products/{product}-{1..4}.webp`, 1200×750) and run the script with
`--og-only` from then on so they are not overwritten.

## Local development (Windows / XAMPP)

```bash
composer install && npm ci
npm run build                 # rebuild the Vite manifest before testing new pages
php artisan serve             # reload the page if it resets the connection
node bootstrap/ssr/ssr.js     # optional: SSR locally (or php artisan inertia:start-ssr)
php artisan test
```
