# SignaturePortal — Project Brief for Claude Code

This document is the primary context for AI coding assistants working in this repository. Read it before making any changes. It describes what the project is, what constraints shape it, and what *not* to do.

## What this project is

SignaturePortal is a self-hosted email signature management system for Microsoft 365 / Outlook. It has two parts that ship together:

1. **Portal** — a PHP web app where admins manage tenants, templates, and rules.
2. **Add-in** — an Outlook Add-in (Office.js) that fetches the correct rendered signature from the Portal at compose time and injects it into the message.

Both parts live in the same repository and deploy as a single PHP application.

## The hosting target shapes everything

The deployment target is **IONOS Shared Hosting** (or any equivalent LAMP-style shared host). This constraint drives every architectural decision:

- **PHP 8.1+ runtime only** — no Node.js, no Python, no Go, no compiled binaries.
- **MySQL/MariaDB** as the only datastore.
- **No CLI access on cheap plans** — Composer dependencies must be committed in `vendor/`. Do not add anything that needs `composer install` or `npm install` on the server.
- **No long-running processes** — no queues, no workers, no cron-required features. Everything is request-scoped.
- **No build step at deploy time** — frontend is server-rendered (Twig) plus vanilla JS. The Add-in uses Office.js from a CDN.
- **`.htaccess`** for routing (front controller pattern).

When in doubt, choose the option that works on the cheapest IONOS plan. Adding a runtime dependency that requires shell access is a regression.

## Tech stack

- **Backend**: PHP 8.1+, Slim Framework 4 (vendored)
- **Templating**: Twig (vendored)
- **Database**: MySQL 5.7+ via PDO
- **Portal auth**: bcrypt local accounts + Entra ID OIDC (`league/oauth2-client` with Azure provider)
- **Add-in → Portal API auth**: per-tenant static API key, rotatable in the portal UI
- **Add-in runtime**: Office.js loaded from `https://appsforoffice.microsoft.com/lib/1/hosted/office.js`
- **Frontend assets**: hand-written CSS, vanilla JS, no bundler
- **HTML sanitization**: HTML Purifier (vendored) — applied to every template on save
- **Secret encryption at rest**: libsodium `crypto_secretbox` with `APP_KEY`

Current state
This is a greenfield project. Only README.md and CLAUDE.md exist. The directory layout below is the target structure — create directories/files as needed when implementing.

## Repository layout

```
/public/                  ← Apache document root
  index.php               ← Front controller (Slim app)
  install.php             ← First-run web installer (delete after install)
  .htaccess               ← Rewrite rules → index.php
  addin/                  ← Static add-in assets (commands.html, taskpane.html, JS)
  assets/                 ← CSS, images
/src/
  Portal/                 ← Web UI controllers, services
  Addin/                  ← Add-in API endpoints (sig delivery, manifest generation)
  Auth/                   ← Local + Entra OIDC providers, session, RBAC
  Tenant/                 ← Tenant model, RuleEngine, TemplateRenderer
  Graph/                  ← Microsoft Graph user-profile lookup + token cache
  Db/                     ← PDO wrapper, migration runner
/templates/               ← Twig views (portal HTML)
/migrations/              ← SQL migrations (numbered, idempotent)
/config/
  config.example.php      ← Template, copied to config.php by installer
/vendor/                  ← Committed Composer dependencies
/docs/                    ← End-user documentation referenced from README
/tests/                   ← PHPUnit tests
CLAUDE.md
README.md
LICENSE                   ← Apache 2.0
```

## Domain concepts

- **Tenant** — an organisation deploying signatures. Holds Entra app credentials (encrypted), an API key, branding settings, templates, and rules. Multi-tenant from day one; every query is scoped by `tenant_id`.
- **Template** — HTML body with `{placeholder}` tokens. Stored in DB, edited in portal. Tokens: `{first_name}`, `{last_name}`, `{job_title_line}`, `{phone_lines}`, `{email}`, `{display_name}`, plus any custom tokens defined per tenant.
- **Rule** — declares which template to apply, by conditions:
  - `from_domain` (required) — domain of the FROM address
  - `language` (optional) — user's `preferredLanguage` from Graph (`de`, `en`, ...)
  - `mailbox_type` (optional) — `personal` or `shared`
  - `priority` (integer, lowest matches first)
  - `is_fallback` (boolean) — used only when no other rule in the tenant matches
- **Display email derivation** — when the FROM is a shared mailbox, the user has no Entra profile under that address. Look up the **primary user** (passed by the add-in as `?primary=`), fetch their profile, and derive the display email per the tenant's display rules. The default rule is convention-based (e.g. `initial.surname@<from-domain>`); rules can override.

## Critical flows

### Signature delivery (Add-in → Portal)

1. Office.js event `OnNewMessageCompose` fires in Outlook.
2. Add-in reads `Office.context.mailbox.userProfile.emailAddress` (primary) and `item.from.emailAddress` (current sender).
3. Add-in calls `GET /api/sig?tenant=<slug>&email=<from>&primary=<primary>&key=<api_key>`.
4. Portal:
   - validates tenant slug + API key (constant-time compare),
   - resolves user profile from Graph using stored per-tenant credentials (token cached in DB for ~50 min),
   - if FROM has no profile and is treated as shared, falls back to the primary user's profile,
   - matches rules in priority order, with fallback last,
   - renders the matched template with profile tokens,
   - returns HTML with `Content-Type: text/html` and `X-Sig-Shared: true|false`.
5. Add-in injects via `Office.context.mailbox.item.body.setSignatureAsync`.

### Portal authentication

- **Local**: `users` table, bcrypt password hash, PHP sessions (file-backed). Users are tenant-scoped or global-admin.
- **Entra SSO**: per-tenant Azure app registration; OIDC code flow via `league/oauth2-client`. Match returned email to a portal user; auto-provision optional per tenant.
- Both paths converge on the same session model. RBAC roles: `superadmin` (cross-tenant), `tenant_admin`, `tenant_editor`.

## What NOT to do

- Do not introduce frameworks or tools requiring a build step on the server.
- Do not call shell commands at runtime (`exec`, `shell_exec`, `system`, ...) — IONOS may block them and they make the install brittle.
- Do not store the Entra client secret in plain text — encrypt with `APP_KEY` via libsodium.
- Do not log full email addresses or rendered signature HTML at INFO level — log tenant + user IDs.
- Do not write to disk outside `var/` — IONOS may have read-only paths.
- Do not require new PHP extensions beyond: `pdo`, `pdo_mysql`, `curl`, `mbstring`, `openssl`, `sodium`, `json`, `dom`. All are default on IONOS.
- Do not hard-code branding strings ("Acme Corp", colours, logos) anywhere — branding is per-tenant and lives in the DB.
- Do not skip CSRF tokens on portal forms.
- Do not skip `tenant_id` scoping on any query that touches tenant-owned data.

## Conventions

- PSR-4 autoload: `App\` → `src/`.
- `declare(strict_types=1);` at the top of every PHP file.
- PSR-12 formatting.
- Controllers thin; services do work; models are PDO-backed value objects.
- All Twig output is auto-escaped; opt out with `|raw` only for trusted, sanitised HTML.
- Database migrations are numbered (`001_init.sql`, `002_add_rules.sql`, ...) and idempotent — apply once, recorded in a `migrations` table.

## Local development

- Requires PHP 8.1+, MySQL 5.7+, Composer (only for updating `vendor/`).
- `cp config/config.example.php config/config.php` and fill values.
- `php bin/migrate.php` to apply migrations (also exposed as web installer).
- `php -S localhost:8080 -t public/` to run.
- Tests: `vendor/bin/phpunit`.

## When the user asks for a new feature

- Default to keeping the IONOS shared-hosting constraint front-of-mind.
- Default to multi-tenant correctness — every query scoped by `tenant_id`.
- Default to no new runtime dependencies.
- If a request would require violating one of these, surface the trade-off explicitly before implementing.
