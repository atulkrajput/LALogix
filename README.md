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
