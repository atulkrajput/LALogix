# LALogix Website

Modern marketing and lead-generation website for LALogix built with Next.js, TypeScript, Tailwind CSS, Prisma, and Vitest.

## Overview

This project is a redesigned company website for LALogix, showcasing:

- school management software
- inventory POS software
- dairy management software
- custom automation services
- blog content and SEO metadata
- contact and demo lead capture flows

## Tech Stack

- Next.js 16 (App Router)
- React 18
- TypeScript
- Tailwind CSS
- Prisma ORM
- PostgreSQL / SQLite-compatible Prisma setup
- Vitest + Testing Library
- Playwright for E2E testing

## Getting Started

### Install dependencies

```bash
npm install
```

### Run the development server

```bash
npm run dev
```

Open http://localhost:3000

### Build for production

```bash
npm run build
```

### Run tests

```bash
npm test
```

### Run Playwright E2E tests

```bash
npm run test:e2e
```

## Available Scripts

```bash
npm run dev          # start local development server
npm run build        # generate Prisma client and build Next app
npm run start        # start production server
npm run lint         # run Next.js lint checks
npm test            # run Vitest suite
npm run test:e2e     # run Playwright tests
npm run db:push      # push Prisma schema to database
npm run db:seed      # seed demo/admin data
```

## Project Structure

```bash
src/
  app/               # routes, pages, API endpoints
  components/        # UI, layouts, sections, forms
  data/              # content and SEO metadata
  lib/               # utilities, validation, automation, email
  styles/            # global styles
prisma/
  schema.prisma       # database schema
  seed.ts            # seed data
public/
  images/            # static assets
content/
  blog/              # blog markdown files
```

## Branding

The site is branded for LALogix Enterprises and uses the new company identity instead of the previous DoonPortal branding.

## Notes

- Environment variables may be required for forms, email delivery, and database configuration.
- Email sending is stubbed by default unless Resend or SMTP credentials are configured.
- Prisma and database setup should be configured before running production features.
