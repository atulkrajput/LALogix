# LALogix Enterprises Website

Marketing website + lead-management CRM for LALogix Enterprises.

- **Backend:** Laravel 12 (PHP 8.2+), MySQL/MariaDB
- **Frontend:** React 18 + TypeScript via [Inertia.js](https://inertiajs.com), Tailwind CSS 3, Framer Motion, Vite

The previous Next.js + Prisma version is kept, untouched, in [`old/`](old/) for reference.

## Setup (XAMPP / local)

```bash
composer install
npm install
cp .env.example .env        # then edit DB_* and the LALogix settings
php artisan key:generate
php artisan migrate
php artisan db:seed          # creates the default admin user (see database/seeders/DatabaseSeeder.php)
```

Run in development (two terminals):

```bash
php artisan serve
npm run dev
```

Open http://localhost:8000 — admin CRM at http://localhost:8000/admin. Change the seeded admin password after the first login.

> **Windows tip:** keep `npm run dev` running alongside `php artisan serve`. PHP's built-in server on Windows can stall or reset connections when the browser loads many built JS chunks at once (pages then stay blank). With Vite running, assets are served by Node and the problem goes away. Apache/XAMPP serving `public/` is not affected.

Production build: `npm run build` (outputs to `public/build`), then point the web server at `public/`.

## Deployment (CircleCI)

Every push runs [`.circleci/config.yml`](.circleci/config.yml): `composer install`, `npm ci`, type check, `npm run build` and the PHPUnit tests. Pushes to **`master`** then deploy with [`.circleci/deploy.sh`](.circleci/deploy.sh), either over **rsync (SSH)** or **FTP**.

The upload includes `vendor/` and `public/build/`, so the server needs no Composer or Node. Paths in [`.circleci/deploy-exclude.txt`](.circleci/deploy-exclude.txt) (`.env`, `storage/`, `old/`, sources, …) are never uploaded or deleted on the server.

### One-time server setup

1. Point the domain's document root at the app's `public/` folder. (If the host can't do that, the root `.htaccess` routes requests into `public/` instead.)
2. Create the MySQL database and upload a production `.env` (copy `.env.example`; set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, `SITE_URL`, `DB_*`, `RESEND_API_KEY`, and run `php artisan key:generate` or paste an `APP_KEY`).
3. After the first deploy, run `php artisan db:seed` once to create the admin user, then change its password.

### CircleCI environment variables

Set these in CircleCI → Project Settings → Environment Variables.

| Variable | Needed for | Purpose |
| --- | --- | --- |
| `DEPLOY_METHOD` | both | Optional. FTP is used when `FTP_HOST` is set, rsync when `SSH_HOST` is set; set `rsync` or `ftp` only to choose when both are set |
| `SSH_HOST`, `SSH_USER`, `DEPLOY_PATH` | rsync | Server, login user, absolute path of the app folder (the one containing `artisan`) |
| `SSH_PORT` | rsync | Optional, default `22` |
| `PHP_BIN` | rsync | Optional PHP CLI path on the server, default `php` (e.g. `/usr/local/bin/php82`) |
| `FTP_HOST`, `FTP_USER`, `FTP_PASSWORD` | ftp | FTP server and login. Files go into the folder the FTP account opens in |
| `FTP_PATH` | ftp | Optional subfolder to upload into instead (e.g. `laravel`) |
| `FTP_PROTOCOL` | ftp | Optional: `ftp` (default, uses TLS when offered), `ftps` or `sftp` |
| `FTP_PORT` | ftp | Optional, protocol default otherwise |
| `FTP_SSL` | ftp | Optional, `no` to turn off TLS for plain `ftp` |
| `FTP_CERT_FINGERPRINT` | ftp | Optional. When the host's certificate doesn't match its name, paste the fingerprint from the deploy error (`Certificate verification: … (4E:46:DA:…)`) to trust only that certificate. Update it when the host renews the certificate |
| `FTP_VERIFY_CERT` | ftp | Optional, `no` to skip certificate checks entirely (prefer `FTP_CERT_FINGERPRINT`) |

For **rsync**, add the private key of a deploy SSH key pair in CircleCI → Project Settings → SSH Keys (hostname = `SSH_HOST`), and put the public key in the server user's `~/.ssh/authorized_keys`. After uploading, the job runs `php artisan migrate --force`, `php artisan optimize` and `storage:link` on the server.

For **FTP**, commands can't run on the server. After a deploy that changes the database schema, run `php artisan migrate --force` (and `php artisan optimize` if you use it) from the host's SSH or terminal tool.

## GST billing (admin → Billing)

One GSTIN, many business lines. Each **product** (LALogix Services, BIVK.in, ISO, Amritix, Ayurved Dhara, …) has its own invoice and credit-note series, numbered `PREFIX/26-27/0001` per financial year (max 16 characters, as GST requires).

1. **Business profile**: legal name, GSTIN, GST state (decides CGST+SGST vs IGST), bank/UPI, LUT number for exports, logo and signature.
2. **Products**: set an invoice prefix (and credit-note prefix) before issuing documents for that product.
3. **Clients**: GSTIN is validated (check digit) and fills in the state and PAN; clients outside India are billed as exports.
4. **Work log**: record billable work, then tick entries and click *Create invoice*.
5. **Invoices**: drafts are editable and unnumbered; *Issue* assigns the next number and freezes the invoice. Then download/e-mail the PDF, record payments (with TDS), raise credit notes, or cancel (the number stays used and is reported as cancelled).

6. **GST Reports**: pick a month, quarter or FY to see the consolidated GSTR-1 tables (B2B, B2CL, B2CS, exports, CDNR/CDNUR, HSN B2B/B2C, documents issued), the GSTR-3B 3.1/3.2 figures and a per-product breakdown. *GSTR-1 for offline tool* downloads an `.xlsx` laid out like the GST offline tool's import template; *Full report* adds the 3B summary and checklist. The B2C-large limit is `GstReportService::B2CL_LIMIT` (₹1 lakh).

After deploying a version with new migrations, run `php artisan migrate --force` on the server. The FTP deploy can't run it for you.

## Where things are

| Area | Location |
| --- | --- |
| Website pages (React) | `resources/js/Pages/*.tsx`, `resources/js/Pages/Products`, `resources/js/Pages/Insights` |
| Admin CRM pages (React) | `resources/js/Pages/Admin/**` + `resources/js/components/layouts/AdminLayout.tsx` |
| Shared components / content data | `resources/js/components`, `resources/js/data` |
| Page routes | `routes/web.php` → `app/Http/Controllers/PageController.php`, `AdminController.php` |
| JSON API (`/api/*`) | `routes/api.php` → `app/Http/Controllers/Api/*` |
| Models | `app/Models` (JSON output keeps the old camelCase / `_count` shape) |
| E-mail, automation rules, blog | `app/Services` |
| GST billing (tax maths, numbering, PDF) | `app/Services/Billing`, `app/Support/{Gstin,GstStates,AmountInWords}.php`, `resources/views/billing/invoice-pdf.blade.php` |
| Billing API / admin pages | `app/Http/Controllers/Api/Billing/*`, `resources/js/Pages/Admin/Billing/**` |
| SEO meta tags + JSON-LD (server-rendered) | `app/Support/Seo.php`, `resources/views/app.blade.php` |
| Database schema | `database/migrations/2026_09_16_000000_create_lalogix_tables.php` |
| Insight (blog) posts | Markdown with YAML front matter in `content/blog/*.md` (needs `title` and `date`) |

## Configuration (`.env`)

| Key | Purpose |
| --- | --- |
| `SITE_URL` | Public URL used in canonical tags, sitemap and JSON-LD |
| `RESEND_API_KEY`, `EMAIL_FROM`, `SALES_EMAIL` | Lead notification e-mails (logged only when no API key) |
| `GTM_ID`, `GA_ID`, `META_PIXEL_ID` | Analytics |
| `CALENDLY_URL` | "Schedule a Demo" button on the thank-you page |
| `BLOG_PATH` | Folder with the Markdown posts (default `content/blog`) |

## Notes on the migration from Next.js

- Every old `/api/*` endpoint exists with the same path, payload and response shape.
- Admin API endpoints now require login (the old ones were open). Login uses a Laravel session instead of the JWT cookie.
- Passwords hashed by the old app (bcryptjs) still work and are rehashed on first login.
- The migration only creates tables that don't exist, so it is safe on the old Prisma database too.
- Redirects (`/demo`, `/services`, `/blog/*`) and security headers from `next.config.mjs` are kept; `sitemap.xml` and `robots.txt` are served by Laravel.
