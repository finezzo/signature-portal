# SignaturePortal — Setup & How it works

This document covers two things:

- **Setup**: how to install SignaturePortal locally for development and on IONOS shared hosting for production.
- **How it works**: the concepts (tenant, template, rule, recipient scope, manifest), the request flows, and where things live in the codebase.

If you only want to know what the project is, read the [README](README.md) first. If you need conventions and constraints for editing the code, read [CLAUDE.md](CLAUDE.md).

---

## Part 1 — Setup

### What you'll need

| | Version | Notes |
|---|---|---|
| PHP | 8.1+ | extensions: `pdo`, `pdo_mysql`, `curl`, `mbstring`, `openssl`, `sodium`, `json`, `dom`. All ship by default on IONOS. |
| MySQL / MariaDB | MySQL 5.7+ or MariaDB 10.3+ | Tested locally on MySQL 8. |
| HTTPS | yes (production) | Outlook refuses to load add-ins over HTTP. Local dev over `http://localhost:8090` is fine for the portal but not for sideloading the add-in. |
| Microsoft Entra app registration | one per tenant | Application permission `User.Read.All` (admin-consented). Required for live signature delivery (Phase 3). |

### Local development with Docker

The repo ships a Docker stack for development: PHP 8.1 + Apache, MySQL 8, phpMyAdmin.

```bash
# from the project root
docker compose up -d
```

The first time, the Apache image builds (~30s). Then MySQL boots, runs its healthcheck, and the app starts.

**URLs**

| | URL |
|---|---|
| Portal | http://localhost:8090 |
| phpMyAdmin | http://localhost:8091 (root / root) |
| MySQL on host | `127.0.0.1:3317` (user `sigportal`, db `signatureportal`) |

Ports are 8090/8091/3317 (not the more common 8080/8081/3307) to avoid clashing with other Docker dev stacks.

**First time only — install Composer dependencies**

```bash
docker compose exec app composer install --no-interaction
```

The `vendor/` directory is committed in production (IONOS shared hosting has no shell), but for development you populate it in the container.

**Run the web installer**

Open <http://localhost:8090/install.php>. Fill in:

- DB host: `mysql` (the compose service name; the app container resolves it via Docker's internal DNS)
- DB port: `3306`
- DB name / user / pass: `signatureportal` / `sigportal` / `sigportal`
- Base URL: `http://localhost:8090`
- Admin email + password (min 10 chars)

The installer:

1. Verifies your PHP version and the eight required extensions.
2. Connects to MySQL and runs every numbered migration in [migrations/](migrations/).
3. Generates a fresh 32-byte `APP_KEY` for at-rest secret encryption.
4. Writes [config/config.php](config/config.php) with the values above.
5. Creates your first user (role `superadmin`).

**Delete `public/install.php` after the installer succeeds**. It is reachable without authentication and can rewrite your config.

### Production install on IONOS shared hosting

The deployment story is intentionally boring: SCP/FTP the project into your web root, point the domain at `public/`, run the web installer.

1. **Get the code on the host.** Either `git clone` (if SSH is available on your plan) or upload a release ZIP.
2. **Point your domain at `public/`.** In the IONOS panel, set the domain's document root to `<project>/public`. Use a subdomain like `signatures.example.com` rather than a path under your main domain — it keeps the manifest URLs and OAuth redirects clean.
3. **Create the database.** Note host, name, user, password. IONOS gives you these at db creation time.
4. **Run the installer** at `https://signatures.example.com/install.php` (HTTPS, not HTTP — Outlook will not load the add-in otherwise).
5. **Delete `public/install.php`** as soon as the success page appears.

**Things that must already be true on the host before the installer runs**

- `vendor/` is committed and uploaded (no `composer install` needed on the server).
- `config/` is writable by PHP (the installer writes `config/config.php` here).
- `var/cache/` and `var/log/` are writable. Their contents are gitignored; the directories themselves are committed via `.gitkeep`.
- `mod_rewrite` is enabled. IONOS enables it by default. Routing depends on [public/.htaccess](public/.htaccess).

If the installer fails at the migration step, it tells you exactly which file failed. Migrations are idempotent — fix the cause, refresh the installer, and it picks up where it left off.

### Upgrading

```bash
# 1. backup the database (always)
# 2. upload the new version of the project
# 3. run migrations
docker compose exec app php bin/migrate.php   # locally
php bin/migrate.php                            # on the server, if you have shell

# Or just reload the portal — `bin/migrate.php` does NOT auto-run on
# request. The migration runner is only invoked by the installer or by
# bin/migrate.php. Add a CLI cron or run manually after each upgrade.
```

`config/config.php` is preserved across upgrades. **Never change `app_key` after install** — every Entra client secret encrypted by the old key becomes unrecoverable.

---

## Part 2 — How it works

### The two halves

SignaturePortal is one PHP application that serves two audiences:

- **Portal**: the browser UI that admins use (templates, rules, tenants, simulator). Routes are everything under `/portal/*`. Authenticated via local password (bcrypt) or Microsoft Entra OIDC.
- **Add-in API**: the endpoint the Outlook Add-in calls at compose time, `/api/sig?tenant=…&email=…&primary=…&key=…`. Authenticated via the per-tenant API key. CSRF and session middleware are explicitly skipped here.

Both run from the same Slim 4 app, share the same MySQL, share the same `APP_KEY` for crypto.

### Concepts

#### Tenant

A tenant represents an organisation. It owns:

- **Slug** — short identifier, used in URLs and embedded in the manifest (`acme-gmbh`). Cannot be changed after creation.
- **Owned email domains** — one or more domains the tenant operates (e.g. `acme.com`, `acme.de`). Used to classify recipients as internal vs external.
- **Entra credentials** — `tenant_id`, `client_id`, `client_secret`. The secret is stored encrypted with libsodium (`crypto_secretbox`) keyed off `APP_KEY`.
- **API key** — random 32-byte URL-safe string. Stored encrypted (so the portal can show it on rotate, and the API endpoint can constant-time-compare against it). Rotation is one click in the portal; the old key stops working immediately and you must redistribute the manifest.
- **Manifest GUID** — stable v4 UUID generated at tenant creation; embedded as the `<Id>` of the OfficeApp manifest XML so Outlook treats it as the same add-in across re-installs.

Multi-tenant from day one. **Every query that touches tenant-owned data is scoped by `tenant_id`** — there is no global "templates table" view.

#### Template

A template is HTML with `{placeholder}` tokens. Stored in MySQL, edited in the portal with a WYSIWYG editor and a live preview pane.

**Tokens (built-in)**

| Token | Filled with |
|---|---|
| `{first_name}` | Graph `givenName` |
| `{last_name}` | Graph `surname` |
| `{display_name}` | Graph `displayName` |
| `{job_title_line}` | Graph `jobTitle` (or empty) |
| `{phone_lines}` | concatenation of `mobilePhone` and `businessPhones[0]` (HTML-formatted) |
| `{email}` | the *display* email (see "Display email derivation") |

**Sanitization on save**: every template is run through HTML Purifier before storage. The allow-list is permissive (Outlook signatures need tables, inline styles, `font` tags, `<a target=_blank>`) but XSS-safe (`<script>`, `<iframe>`, JS event handlers, `javascript:` URLs are stripped). See [src/Tenant/TemplateRenderer.php](src/Tenant/TemplateRenderer.php).

#### Rule

A rule says "if this set of conditions matches, render this template". A tenant has many rules, and they are evaluated in order:

1. non-fallback rules first, ordered by `priority` ascending (lower number = matched first), then by `id`
2. fallback rules last

The first rule whose conditions all match wins.

**Conditions**

| Field | Required | Meaning |
|---|---|---|
| `from_domain` | yes | Must equal the domain part of the sender's address. |
| `language` | no | If set, must equal the user's `preferredLanguage` from Graph (e.g. `de`, `en`). Leave blank to match any. |
| `mailbox_type` | no | `personal` or `shared`. Leave blank to match any. |
| `recipient_scope` | yes | `all` / `external` / `internal`. See below. |
| `priority` | yes | Integer, default 100. |
| `is_fallback` | yes | Boolean. Fallback rules are evaluated last and only one should be flagged. |

#### Recipient scope (the recipient filter)

Often you only want a corporate signature on emails going **outside** the company. That's what `recipient_scope` does:

- `all` — always matches (default; signature goes on every send).
- `external` — matches if **at least one recipient is outside** the tenant's owned domains. This is the "any external recipient → external send" behaviour most signature managers use.
- `internal` — matches only if **every recipient is inside** the tenant's owned domains, and there is at least one recipient.

When the add-in fires `OnNewMessageCompose` with no recipients yet, only `all`-scope rules apply. As the user types recipients, the rule the add-in resolves can change — Phase 3 will decide the polling cadence.

The classification is implemented in [src/Tenant/RecipientClassifier.php](src/Tenant/RecipientClassifier.php). The matching logic across all conditions is in [src/Tenant/RuleEngine.php](src/Tenant/RuleEngine.php).

#### Display email derivation (shared mailboxes)

When someone sends from a shared mailbox (`info@acme.com`), they have no Entra profile under `info@acme.com` — only under their own primary mailbox. The add-in passes both:

- `email` — the FROM address (`info@acme.com`)
- `primary` — the user's own mailbox (`anna.beispiel@acme.com`)

The portal looks up the **primary** user's profile from Graph for the token values (name, title, phone). The display email shown in the signature is then derived per the tenant's display-rule convention — by default `<initial>.<surname>@<from-domain>` (so Anna Beispiel sending from `info@acme.com` shows as `a.beispiel@acme.com`). Tenants can override the convention.

This is Phase 3 work (Graph integration). Phase 2 stops at the rule engine; the simulator uses sample data.

### Critical flows

#### Signature delivery — Outlook → Portal

1. Office.js fires `OnNewMessageCompose` in Outlook.
2. The add-in reads `Office.context.mailbox.userProfile.emailAddress` (primary) and `item.from.emailAddress` (current sender).
3. The add-in collects current `item.to` / `item.cc` / `item.bcc` recipients.
4. The add-in calls `GET /api/sig?tenant=<slug>&email=<from>&primary=<primary>&recipients=<csv>&key=<api_key>`.
5. The portal:
    1. validates the tenant slug and constant-time-compares the API key
    2. resolves the user's Graph profile (token cached in `graph_token_cache` for ~50 min)
    3. if the FROM address has no profile and is being treated as a shared mailbox, falls back to the primary user's profile
    4. matches rules in priority order (with recipient-scope evaluation), with fallbacks last
    5. renders the matched template with profile tokens
    6. returns HTML with `Content-Type: text/html` and `X-Sig-Shared: true|false`
6. The add-in injects via `Office.context.mailbox.item.body.setSignatureAsync`.

**Phase 2 status**: steps 1–4 and 6 are add-in-side and not yet built. The portal-side resolution (rule matching, template rendering with sample tokens) is wired and exposed via the simulator. Step 5.2 (Graph profile lookup) is Phase 3.

#### Portal authentication

- **Local**: `users` table, bcrypt password hash, PHP file-backed sessions. Users can be tenant-scoped or global (`role = superadmin`).
- **Entra SSO**: per-tenant Azure app registration; OIDC v2.0 code flow via `thenetworg/oauth2-azure`. Implemented in [src/Auth/EntraProvider.php](src/Auth/EntraProvider.php) (provider factory) and [src/Auth/SsoAuthenticator.php](src/Auth/SsoAuthenticator.php) (user matching + provisioning). Reuses the same Entra credentials configured for Microsoft Graph — admins register one app per tenant. Match precedence: `entra_object_id` (the stable `oid` claim) → email → auto-provision (if enabled). When a local user signs in via SSO for the first time their `oid` is linked to the existing record.
- Both paths converge on the same session model. RBAC roles: `superadmin` (cross-tenant), `tenant_admin`, `tenant_editor`. Implemented in [src/Portal/AccessControl.php](src/Portal/AccessControl.php).

#### Configuring Entra SSO for a tenant

1. In Azure portal, open the existing app registration the tenant already uses for Graph (or create one — see "Microsoft Graph" in this doc).
2. Open the tenant in the portal (**Tenants → … → Settings → overview**). Copy the **SSO redirect URI** shown on the page.
3. In Azure → app registration → **Authentication** → **Add platform → Web** → paste the redirect URI. Tick **ID tokens** under "Implicit grant and hybrid flows" — the v2.0 endpoint needs it for OIDC. Save.
4. In Azure → app registration → **API permissions**, ensure `openid`, `profile`, `email` are granted (delegated). These are typically auto-included; add them explicitly if missing.
5. Back in the portal, edit the tenant: tick **Enable Entra SSO**. Decide whether to **auto-provision** unknown sign-ins; if so, pick a default role.
6. The login page will now show a **Sign in with Microsoft — *Tenant Name*** button beneath the email/password form.

If Microsoft rejects the redirect with `AADSTS50011: redirect URI mismatch`, the URI in Azure does not exactly match the one shown on the tenant page (port, scheme, trailing slash all matter).

#### CSRF and the API endpoint

The portal uses CSRF tokens on every state-changing form. The token lives in the session and is verified by [src/Http/Middleware/CsrfMiddleware.php](src/Http/Middleware/CsrfMiddleware.php). **Routes under `/api/*` are explicitly skipped** — those use the per-tenant API key, not session cookies, so CSRF is not applicable.

### Add-in deployment

#### Generate a manifest

Once a tenant has an API key, the portal generates an OfficeApp manifest XML at:

```
GET /portal/tenants/{id}/manifest.xml
```

The manifest embeds the tenant slug, API key, manifest GUID, and base URL, and points Outlook at `OnNewMessageCompose` for `LaunchEvent`. See [src/Addin/ManifestGenerator.php](src/Addin/ManifestGenerator.php).

**The manifest contains the API key in plaintext.** Anyone with a copy of the manifest can call `/api/sig` for that tenant. Distribute it only via:

- M365 admin centre → Integrated apps → Upload custom apps → My organization (recommended)
- Outlook → Get Add-ins → My add-ins → Add a custom add-in → Add from File (sideload, for testing only)

If a manifest leaks, **rotate the API key immediately** in the portal — the old key stops working at once. Then re-distribute the new manifest.

#### What the manifest does

- Loads `commands.html` from your portal at `OnNewMessageCompose`.
- The runtime add-in code (commands.html + commands.js) reads the tenant slug + key from the URL query string baked into the manifest, then calls `/api/sig` with the current send context. (Phase 3.)
- Permissions: `ReadWriteMailbox` (needed for `setSignatureAsync`).
- Requires Mailbox 1.10+ — needed for `LaunchEvent` `OnNewMessageCompose`. This rules out Outlook 2016/2019; works on new Outlook for Windows/Mac, Outlook Web App, and Outlook for Microsoft 365.

### Simulator (the "what-if" tool)

In each tenant: **Tenants → … → Simulator**. Plug in a hypothetical send — FROM address, recipients, language, mailbox type — and the portal shows:

- which rule the engine picks (or "no rule matched")
- a per-rule decision trace (why each rule did or did not match)
- the matched template, rendered with sample tokens, in a sandboxed iframe

The simulator does not call Graph — it uses fixed sample tokens so you can iterate on rule logic and template HTML without external dependencies. Useful as a regression-check before changing live rules.

### Data model summary

```
tenants
  └── templates       (1:N)
  └── rules           (1:N)  — references tenant, references template
  └── graph_token_cache (1:1)  — per-tenant cached Graph access token
users
  └── tenant_id NULL → superadmin / cross-tenant
migrations           — append-only log of applied SQL files
```

`tenants.email_domains` is a JSON array. `tenants.api_key_encrypted` and `tenants.entra_client_secret_encrypted` are libsodium-encrypted. All schema lives in [migrations/](migrations/).

### Where to look when something breaks

| Symptom | Look at |
|---|---|
| Apache 500 on any portal page | `docker logs sigportal-app` |
| MySQL connection refused | `docker logs sigportal-mysql`, then check `config/config.php` `db.host` matches the compose service name |
| Installer says "config exists, can't re-run" | Drop the database and delete `config/config.php`, then re-run |
| `manifest.xml` says "no API key" | Rotate the API key on the tenant overview page |
| Live preview iframe stays blank | Open the template-form page, watch the browser console; CSRF rejection shows as HTTP 419 |
| Rule never matches in production but matches in the simulator | Confirm the tenant's `email_domains` are correct — recipient classification depends on them |

### Extending — what you'll touch

| Want to | Touch |
|---|---|
| Add a new template token | `TemplateRenderer::sampleTokens()` for previews; the Graph profile mapper (Phase 3) for live values |
| Add a new rule condition | Add a column in a new migration, extend `Rule` + `RuleRepository`, add the matcher in `RuleEngine::matches()`, add the form field |
| Allow more HTML in templates | `TemplateRenderer::purifier()` — extend `HTML.Allowed` |
| Change the manifest target | `ManifestGenerator::build()` |
| Add an API endpoint for the add-in | `src/routes.php` (`/api/...`), bypasses CSRF middleware automatically |

### Phase 1 / Phase 2 status

This document reflects the state after Phase 2.

- **Phase 1** (done): Docker dev stack, web installer, schema, local auth, base portal layout, dashboard.
- **Phase 2** (done): Tenant / Template / Rule CRUD, HTML Purifier, recipient-scope filter, simulator with decision trace, manifest XML generator.
- **Phase 3** (done, untested in Outlook): the live `/api/sig` endpoint, Microsoft Graph profile lookup with token caching, the add-in JS (`commands.html` / `taskpane.html`) wired to `OnNewMessageCompose` / `OnMessageFromChange` / `OnMessageRecipientsChange`, shared-mailbox primary-user fallback.
- **Phase 4** (in progress): Entra SSO for portal admins (done — see "Configuring Entra SSO" above). Still open: branding (logo / accent colour) per tenant, template version history with rollback, OWA-specific deployment notes.

### Local-development quirks worth knowing

If your project lives inside a OneDrive-synced folder on macOS, Docker bind mounts hit `EDEADLK` ("Resource deadlock avoided") at random — both Composer and Apache trip on it. Workaround: keep the source under `~/<something>/` (outside OneDrive) and bind-mount that. The `docker-compose.yml` in the repo assumes the project lives at the path you run `docker compose` from, so a clone outside OneDrive just works.

---

## Quick reference

```bash
# Dev — bring up the stack
docker compose up -d

# Run the migration runner (after pulling new migrations)
docker compose exec app php bin/migrate.php

# Run tests
docker compose exec app vendor/bin/phpunit

# Tear down (keep the volume so DB state persists)
docker compose down

# Tear down + drop the DB volume (destructive)
docker compose down -v
```

```bash
# Production — run migrations after upload
php bin/migrate.php
```

```sql
-- Reset everything from scratch (destructive — for dev only)
DROP DATABASE signatureportal;
CREATE DATABASE signatureportal CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL ON signatureportal.* TO 'sigportal'@'%';
-- Then delete config/config.php and re-run /install.php
```
