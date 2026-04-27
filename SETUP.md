# SignaturePortal — Setup & How it works

This document covers two things:

- **Setup**: how to install SignaturePortal locally for development and on IONOS shared hosting for production.
- **How it works**: the concepts (tenant, template, rule, override, asset, audit log, manifest), the request flows, and where things live in the codebase.

If you only want to know what the project is, read the [README](README.md) first. If you need conventions and constraints for editing the code, read [CLAUDE.md](CLAUDE.md).

---

## Part 1 — Setup

### What you'll need

| | Version | Notes |
|---|---|---|
| PHP | 8.1+ (tested up to 8.4) | extensions: `pdo`, `pdo_mysql`, `curl`, `mbstring`, `openssl`, `sodium`, `json`, `dom`. All ship by default on IONOS. |
| MySQL / MariaDB | MySQL 5.7+ or MariaDB 10.3+ | Tested locally on MySQL 8. |
| HTTPS | yes (production) | Outlook refuses to load add-ins over HTTP. Local dev over `http://localhost:8090` is fine for the portal but not for sideloading the add-in. |
| Microsoft Entra app registration | one per tenant | Application permission `User.Read.All` (admin-consented). Required for both Microsoft Graph signature delivery and Entra SSO — one app does both jobs. |

### Where it runs (and where it doesn't)

| Outlook client | Auto-applied signature | Manual ribbon button |
|---|---|---|
| Outlook on the web (OWA) | ✅ | ✅ |
| New Outlook for Windows | ✅ | ✅ |
| Classic Outlook for Windows (recent builds) | ✅ | ✅ |
| Outlook for Mac | ✅ | ✅ |
| **Outlook iOS / Android** | ❌ | ❌ |

Mobile clients **do not** support event-based add-ins. If you need signature rewriting on mobile you'll want either an Exchange Online transport rule (very limited templating) or a server-side SMTP relay that intercepts outbound mail and calls this portal's `/api/sig`. Neither ships in this repo today.

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
4. Writes [config/config.php](config/config.php) with the values above, including `debug => false`.
5. Creates your first user (role `superadmin`).

**Delete `public/install.php` after the installer succeeds**. It is reachable without authentication and can rewrite your config.

### Production install on IONOS shared hosting

The deployment story is intentionally boring: SCP/FTP the project into your web root, point a subdomain at `public/`, run the web installer.

1. **Create a subdomain** (e.g. `signatures.example.com`) in the IONOS panel and assign a Let's Encrypt SSL certificate. Outlook will reject the manifest over HTTP.
2. **Point the subdomain at `public/`**. Set the document root to `<project>/public` — *not* the project root, otherwise `src/`, `config/`, and `vendor/` end up publicly browsable.
3. **Upload the project**. Either `git clone` (if SSH is available on your plan) or upload via FTP. Only the source folders are needed at runtime — `bin/`, `config/`, `migrations/`, `public/`, `src/`, `templates/`, `var/`, `vendor/`. The README/SETUP/CLAUDE markdown files are documentation and don't need to live on the server.
4. **Create the database** in the IONOS panel. Note the host (looks like `db5xxxxxxx.hosting-data.io`), database name, user, password.
5. **Run the installer** at `https://signatures.example.com/install.php` (HTTPS, not HTTP — Outlook will not load the add-in otherwise).
6. **Delete `public/install.php`** as soon as the success page appears.

**Things that must already be true on the host before the installer runs**

- `vendor/` is committed and uploaded (no `composer install` needed on the server).
- `config/` is writable by PHP (the installer writes `config/config.php` here).
- `var/cache/` and `var/log/` are writable (775 is enough). Their contents are gitignored; the directories themselves are committed via `.gitkeep`.
- `public/assets/tenants/` is writable (image uploads land here).
- `mod_rewrite` is enabled. IONOS enables it by default. Routing depends on [public/.htaccess](public/.htaccess), which sets `RewriteBase /` for IONOS subdomain mounts.

If the installer fails at the migration step, it tells you exactly which file failed. Migrations are idempotent — fix the cause, refresh the installer, and it picks up where it left off.

### Upgrading

```bash
# 1. backup the database (always)
# 2. upload the new version of the project (overwrite src/, templates/, public/, etc.)
# 3. run any new migrations
docker compose exec app php bin/migrate.php   # locally
php bin/migrate.php                            # on the server, if you have shell
```

Without shell, run new migrations manually via phpMyAdmin — open the corresponding `.sql` file in the `migrations/` directory and paste it in the SQL tab. The migrations table tracks which have been applied.

`config/config.php` is preserved across upgrades. **Never change `app_key` after install** — every Entra client secret encrypted by the old key becomes unrecoverable.

### Configuration reference

`config/config.php` (created by the installer):

| Key | Purpose |
|---|---|
| `db.*` | MySQL host, port, name, user, password |
| `app_key` | 32-byte key for at-rest secret encryption (`base64:...`). **Do not change after install.** |
| `base_url` | Public HTTPS URL of the install (no trailing slash) |
| `debug` | When `true`, unhandled exceptions render full stack traces in the browser. **Never set true in production.** Default `false`. |
| `auth.local.enabled` | Allow local password login |
| `auth.entra.enabled` | (Reserved — Entra SSO is configured per-tenant in the portal UI) |
| `session.name` / `session.lifetime` | Portal session cookie name and lifetime in minutes |

---

## Part 2 — How it works

### The two halves

SignaturePortal is one PHP application that serves two audiences:

- **Portal**: the browser UI that admins use (templates, rules, overrides, tenants, simulator, users, audit log). Routes are everything under `/portal/*`. Authenticated via local password (bcrypt) or Microsoft Entra OIDC.
- **Add-in API**: the endpoint the Outlook Add-in calls at compose time, `GET /api/sig?tenant=…&email=…&primary=…&recipients=…&key=…`. Authenticated via the per-tenant API key (or `X-Sig-Key` header). CSRF and session middleware are explicitly skipped here.

Both run from the same Slim 4 app, share the same MySQL, share the same `APP_KEY` for crypto.

### Concepts

#### Tenant

A tenant represents an organisation. It owns:

- **Slug** — short identifier, used in URLs and embedded in the manifest (`acme-gmbh`). Cannot be changed after creation.
- **Owned email domains** — one or more domains the tenant operates (`acme.com`, `acme.de`). Used to classify recipients as internal vs external.
- **Entra credentials** — `tenant_id`, `client_id`, `client_secret`. The secret is encrypted with libsodium (`crypto_secretbox`) keyed off `APP_KEY`.
- **API key** — random 32-byte URL-safe string. Stored encrypted (so the portal can show it on rotate, and `/api/sig` can constant-time-compare against it). Rotation is one click; the old key stops working immediately and a banner reminds admins to redistribute the manifest until acknowledged.
- **Manifest GUID** — stable v4 UUID generated at tenant creation; embedded as the `<Id>` of the OfficeApp manifest XML so Outlook treats it as the same add-in across re-installs.
- **Shared mailbox display mode** — `shared` (default; show the team mailbox in `{email}`) or `derived` (compute a personal address like `s.mueller@acme.com` from the original sender).
- **Disclaimer HTML** — optional legal footer, sanitized like a template, appended to every rendered signature. Token substitution applies.
- **Branding GUID and SSO settings** — see "Authentication" below.

Multi-tenant from day one. **Every query that touches tenant-owned data is scoped by `tenant_id`** — there is no global "templates table" view.

#### Template

A template is HTML with `{placeholder}` tokens. Stored in MySQL, edited in the portal with a TinyMCE WYSIWYG editor and a live preview iframe.

##### Tokens

Every Microsoft Graph user property is exposed as a `{snake_case}` token automatically. The full list is built by [src/Tenant/TokenCatalog.php](src/Tenant/TokenCatalog.php) and surfaced in the editor's grouped "Token" menu. Selected highlights:

| Group | Tokens |
|---|---|
| Identity | `{display_name}`, `{first_name}`, `{last_name}`, `{middle_name}`, `{employee_id}`, `{employee_type}` |
| Job | `{job_title}`, `{job_title_line}`, `{department}`, `{company_name}`, `{office_location}` |
| Contact | `{email}`, `{mail}`, `{user_principal_name}`, `{mobile_phone}`, `{business_phones}`, `{fax_number}`, `{phone_lines}` |
| Address | `{street_address}`, `{postal_code}`, `{city}`, `{state}`, `{country}`, `{full_address}` |
| Other | `{preferred_language}`, `{about_me}`, `{usage_location}` |

**Adding a token**: extend the `$select` in [src/Graph/GraphClient.php](src/Graph/GraphClient.php) and add the entry in `TokenCatalog`. The editor menu picks it up on the next page load.

##### Conditional blocks

Wrap any content in `{if:NAME}…{/if}` to make it disappear automatically when the named token is empty. The editor's "Conditional" toolbar menu wraps the current selection for you.

```html
{if:mobile_phone}<div>Mob: {mobile_phone}</div>{/if}
{if:business_phones}<div>Tel: {business_phones}</div>{/if}
```

If a user has no mobile phone, the entire `<div>` (including the "Mob: " label) drops out — no dangling labels for missing Graph attributes.

##### Sanitisation on save

Every template runs through HTML Purifier. The allowlist is permissive (Outlook signatures need tables, inline styles, `font` tags, `<a target=_blank>`) but XSS-safe (`<script>`, `<iframe>`, JS event handlers, `javascript:` URLs are stripped). Plus two server-side normalisations: `<p>` → `<div>` (avoids the `<p>`-margin mismatch between editor and Outlook), and empty `<div></div>` → `<div><br></div>` (so a blank line is a blank line everywhere). See [src/Tenant/TemplateRenderer.php](src/Tenant/TemplateRenderer.php).

#### Rule

A rule says "if this set of conditions matches, render this template". A tenant has many rules, and they are evaluated in order:

1. non-fallback rules first, ordered by `priority` ascending (lower number = matched first), then by `id`
2. fallback rules last

The first rule whose conditions all match wins.

| Field | Required | Meaning |
|---|---|---|
| `from_domain` | yes | Must equal the domain part of the sender's address. |
| `language` | no | If set, must equal the user's `preferredLanguage` from Graph (e.g. `de`, `en`). Leave blank to match any. |
| `mailbox_type` | no | `personal` or `shared`. Leave blank to match any. |
| `recipient_scope` | yes | `all` / `external` / `internal`. See below. |
| `priority` | yes | Integer, default 100. |
| `is_fallback` | yes | Boolean. Fallback rules are evaluated last and only one should be flagged. |
| `is_enabled` | yes | Boolean toggle to deactivate without deleting. Disabled rules surface in the simulator with explicit "rule is disabled" reason. |
| `valid_from` / `valid_until` | no | Date window. Inclusive on both ends, compared against today. Natural fit for seasonal banners (e.g. Christmas signature only `2026-12-15` to `2027-01-06`). |

#### Recipient scope (the recipient filter)

Often you only want a corporate signature on emails going **outside** the company. That's what `recipient_scope` does:

- `all` — always matches (default; signature goes on every send).
- `external` — matches if **at least one recipient is outside** the tenant's owned domains. This is the "any external recipient → external send" behaviour most signature managers use.
- `internal` — matches only if **every recipient is inside** the tenant's owned domains, and there is at least one recipient.

When the add-in fires `OnNewMessageCompose` with no recipients yet, only `all`-scope rules apply.

The classification is implemented in [src/Tenant/RecipientClassifier.php](src/Tenant/RecipientClassifier.php). The matching logic across all conditions is in [src/Tenant/RuleEngine.php](src/Tenant/RuleEngine.php).

#### Per-user overrides

Sit *above* the rule engine. Per tenant, you can pin a specific email address to a specific template — when `?primary=` matches, that template wins outright and the rule engine isn't consulted. Use case: "the boss wants their own signature regardless of what the rules say."

| Column | Purpose |
|---|---|
| `email` | match key (case-insensitive) |
| `template_id` | which template to render |
| `note` | free-text reminder for future you |

Manage via **Tenants → … → Overrides**.

#### Shared mailbox detection (the licence heuristic)

When someone sends from a shared mailbox (`info@acme.com`), they have an Entra user object — but **no `assignedLicenses`**. Personal mailboxes always carry a licence. We use that as the detection signal:

1. Add-in sends `email=info@acme.com&primary=anna.beispiel@acme.com`
2. We fetch `info@acme.com`'s Graph profile → it exists, but `assignedLicenses` is `[]` → shared mailbox.
3. We fetch `anna.beispiel@acme.com`'s profile instead and use its values for tokens (name, title, phone…).
4. The `{email}` token gets either `info@acme.com` (mode `shared`, default) or `a.beispiel@acme.com` (mode `derived`) depending on the tenant's preference.
5. Response sets `X-Sig-Shared: true`.

Configured per tenant in **Tenants → … → Settings → Shared mailbox display mode**.

#### Image asset library

Per-tenant image storage at `public/assets/tenants/<slug>/<filename>`. PNG/JPG/GIF/WebP, up to 5 MB each. Images are served directly by Apache (no PHP roundtrip), referenced from templates by URL. Filename is sanitised (`a-z0-9_-` only, lowercase, max 80 chars) and MIME-sniffed via `finfo` to reject content/extension mismatches. Delete a tenant → the directory is removed recursively.

Manage via **Tenants → … → Images**, or drag-and-drop / paste an image directly into the template editor (uploads via a JSON endpoint, inserts the resulting URL).

#### Audit log

Append-only `audit_log` table. Every mutation writes an entry: tenant.created, template.updated, rule.deleted, override.created, user.password_reset, tenant.api_key.rotated, and so on. Each entry includes actor email, IP, target type/id, and an optional payload. Visible per tenant under **Tenants → … → Activity** (latest 200 entries).

### Authentication

#### Local

`users` table, bcrypt password hash, PHP file-backed sessions. Failed-login lockout: **5 wrong attempts in 10 minutes → 15-minute lock** on that email. The error message is generic ("Invalid email or password") for both wrong-password and locked states, so an attacker can't enumerate accounts by triggering lockouts. Lock events are logged via `error_log` for ops visibility.

Self-service password change at `/portal/account`. Superadmin user-management UI at `/portal/users` for CRUD across all users.

#### Entra SSO

Per-tenant Azure app registration; OIDC v2.0 code flow via `thenetworg/oauth2-azure`. Implemented in [src/Auth/EntraProvider.php](src/Auth/EntraProvider.php) (provider factory) and [src/Auth/SsoAuthenticator.php](src/Auth/SsoAuthenticator.php) (user matching + provisioning). Reuses the same Entra credentials configured for Microsoft Graph — admins register one app per tenant.

Match precedence: `entra_object_id` (the stable `oid` claim) → email → auto-provision if enabled. When a local user signs in via SSO for the first time their `oid` is linked to the existing record so future logins are oid-matched (immune to email rename in Entra).

Both paths converge on the same session model. RBAC roles: `superadmin` (cross-tenant), `tenant_admin`, `tenant_editor`. Gated in [src/Portal/AccessControl.php](src/Portal/AccessControl.php).

##### Configuring Entra SSO for a tenant

1. In Azure portal, open the existing app registration the tenant already uses for Graph (or create one).
2. Open the tenant in the portal: **Tenants → … → Overview**. Copy the **SSO redirect URI** shown on the page.
3. In Azure → app registration → **Authentication** → **Add platform → Web** → paste the redirect URI. Tick **ID tokens** under "Implicit grant and hybrid flows" — the v2.0 endpoint needs it for OIDC. Save.
4. In Azure → app registration → **API permissions**, ensure `openid`, `profile`, `email` are granted (delegated). These are typically auto-included; add them explicitly if missing.
5. Back in the portal, edit the tenant: tick **Enable Entra SSO**. Decide whether to **auto-provision** unknown sign-ins; if so, pick a default role.
6. The login page will now show a **Sign in with Microsoft — *Tenant Name*** button beneath the email/password form.

If Microsoft rejects the redirect with `AADSTS50011: redirect URI mismatch`, the URI in Azure does not exactly match the one shown on the tenant page (port, scheme, trailing slash all matter).

#### CSRF and the API endpoint

The portal uses CSRF tokens on every state-changing form. The token lives in the session and is verified by [src/Http/Middleware/CsrfMiddleware.php](src/Http/Middleware/CsrfMiddleware.php). **Routes under `/api/*` are explicitly skipped** — those use the per-tenant API key, not session cookies, so CSRF is not applicable.

### Critical flows

#### Signature delivery — Outlook → Portal

1. Office.js fires `OnNewMessageCompose` in Outlook.
2. The add-in reads `Office.context.mailbox.userProfile.emailAddress` (primary) and `item.from.emailAddress` (current sender).
3. The add-in collects current `item.to` / `item.cc` / `item.bcc` recipients.
4. The add-in calls `GET /api/sig?tenant=<slug>&email=<from>&primary=<primary>&recipients=<csv>` with `X-Sig-Key: <api_key>` header (or `&key=<api_key>` query as fallback).
5. The portal:
   1. validates the tenant slug and constant-time-compares the API key (single 401 response for both unknown-slug and bad-key — no enumeration)
   2. resolves the user's Graph profile (token cached in `graph_token_cache` for ~50 min)
   3. detects shared mailbox via `assignedLicenses` heuristic — falls back to the primary user's profile if so
   4. **checks per-user overrides first** — if `primary` matches an override, that template wins
   5. otherwise matches rules in priority order (with date-window + recipient-scope evaluation), with fallbacks last
   6. renders the matched template with profile tokens, applies conditional blocks, appends the tenant disclaimer
   7. returns HTML with `Content-Type: text/html` and `X-Sig-Shared: true|false`
6. The add-in injects via `Office.context.mailbox.item.body.setSignatureAsync`.

If anything Graph-related fails, the response is `502 graph lookup failed` — the actual Graph error is logged server-side but never returned to the caller (an API-key holder shouldn't be able to enumerate emails through Graph errors).

### Add-in deployment

#### Generate a manifest

Once a tenant has an API key, the portal generates an OfficeApp manifest XML at:

```
GET /portal/tenants/{id}/manifest.xml
```

The manifest embeds the tenant slug, API key, manifest GUID, and base URL. See [src/Addin/ManifestGenerator.php](src/Addin/ManifestGenerator.php).

**The manifest contains the API key in plaintext.** Anyone with a copy of the manifest can call `/api/sig` for that tenant. Distribute it only via:

- M365 admin centre → Integrated apps → Upload custom apps → My organization (recommended)
- Outlook → Get Add-ins → My add-ins → Add a custom add-in → Add from File (sideload, for testing only)

If a manifest leaks, **rotate the API key immediately** in the portal — the old key stops working at once. The portal banners until you acknowledge that the new manifest has been redistributed.

#### What the manifest does

The structure follows Microsoft's recommended event-based add-in shape:

- **OfficeApp top-level**: AppDomains for `login.microsoftonline.com` and `graph.microsoft.com`; FormSettings pointing at the taskpane; Mailbox MinVersion 1.1.
- **VersionOverrides V1_0** (Mailbox 1.3+): a manual "Refresh" ribbon button via `MessageComposeCommandSurface` that opens the taskpane.
- **VersionOverrides V1_1** (Mailbox 1.10+): the `<Runtimes>` block (WebView + JS override — needed for classic Outlook for Windows), `SupportsSharedFolders: true`, and a single `OnNewMessageCompose` LaunchEvent wired to `onNewMessageComposeHandler`.

Permissions: `ReadWriteMailbox` (needed for `setSignatureAsync`).

### Editor (TinyMCE)

Loaded from CDN, GPL build. Key configuration:

- **`forced_root_block: 'div'`** with margin: 0 — Enter creates a new `<div>` that visually looks like a line break. No surprise gaps.
- **`fontsizeinput`** instead of the dropdown — type any value (`13px`, `10.5pt`).
- **`lineheight`** dropdown.
- **`clear_child_styles: true`** on `fontsize` / `fontname` / colour formats — applying a value flattens nested spans, so two visually identical font-sizes don't render different sizes.
- **Word/MSO paste cleanup**: conditional comments, `<o:p>` namespaces, `mso-*` properties and `MsoNormal` classes stripped automatically; `<p>` → `<div>` on paste.
- **Token / Conditional / Snippet menus** in the toolbar — feed from server-side catalogues (`TokenCatalog`, `SnippetLibrary`, `StarterTemplates`).
- **Layout-table wizard** ("Layout" button) — produces Outlook-friendly tables with `vertical-align:top` + padding instead of margin.
- **Image library picker** — browse the tenant's uploaded images and insert one with width + alt text.
- **Drag-and-drop / paste image upload** — files go straight to the asset library via a CSRF-protected JSON endpoint.
- **Live preview iframe** with width toggle (Desktop / Tablet / Mobile / 600 / 375 px).
- **Auto-save to localStorage** (per-tenant + per-template id) with a restore banner; cleared on submit.

### Simulator

In each tenant: **Tenants → … → Simulator**. Plug in a hypothetical send — FROM, optional primary user, recipients, language, mailbox type — and the portal shows:

- which rule (or override) the engine picks (or "no rule matched")
- a per-rule decision trace (why each rule did or did not match), including disabled / out-of-window reasons
- the matched template, rendered in a sandboxed iframe with sample tokens **or** with live Graph data if you tick "Use live Graph data" (requires the tenant's Entra credentials to be configured)
- the resolved `{email}` value when the FROM is treated as a shared mailbox

Useful as a regression-check before changing live rules, and as a "preview as user X" dev tool.

### Data model summary

```
tenants
  ├── templates           (1:N)
  ├── rules               (1:N)  — references tenant, references template
  ├── user_overrides      (1:N)  — references tenant, references template
  ├── graph_token_cache   (1:1)  — per-tenant cached Graph access token

users
  └── tenant_id NULL → superadmin / cross-tenant; auth_provider = local|entra

audit_log
  └── tenant_id (nullable on delete), user_id (nullable on delete)

migrations
  └── append-only log of applied SQL files
```

Notable encrypted-at-rest fields (libsodium):
- `tenants.api_key_encrypted`
- `tenants.entra_client_secret_encrypted`

Notable JSON columns:
- `tenants.email_domains`
- `tenants.branding` (currently unused; reserved for the per-tenant branding feature)
- `audit_log.payload_json`

All schema lives in [migrations/](migrations/). Migration `004` is intentionally absent — off-by-one when SSO was added; runner reads alphabetically so the gap is harmless.

### Where to look when something breaks

| Symptom | Look at |
|---|---|
| Apache 500 on any portal page (local) | `docker logs sigportal-app` |
| MySQL connection refused (local) | `docker logs sigportal-mysql`, then check `config/config.php` `db.host` matches the compose service name |
| Apache 500 on IONOS (no Slim error page) | Most likely `.htaccess` issue — confirm `RewriteBase /` is present, mod_rewrite enabled. Also confirm `var/cache/` and `var/log/` are 775 (Twig + HTMLPurifier need to write there) |
| Slim error page in production with stack trace | Set `'debug' => false` in `config/config.php` immediately — `addErrorMiddleware` is bound to that flag |
| Generic 500 with no PHP error | Check the IONOS error log; common cause is OPcache holding a stale copy after upload — toggle the PHP version in the IONOS panel to force a pool restart |
| Add-in installs but never fires | Manifest is missing the `<Runtimes>` block or has too-strict `MinVersion`. The current generator handles both correctly. |
| Installer says "config exists, can't re-run" | Drop the database and delete `config/config.php`, then re-run |
| `manifest.xml` says "no API key" | Rotate the API key on the tenant overview page |
| Live preview iframe stays blank | Open the template-form page, watch the browser console; CSRF rejection shows as HTTP 419 |
| Rule never matches in production but matches in the simulator | Confirm the tenant's `email_domains` are correct — recipient classification depends on them. Also check `is_enabled` and the validity window. |
| `/api/sig` returns `502 graph lookup failed` | Server log has the actual Graph message. Most common: missing admin consent for `User.Read.All`, or a stale client secret. |

### Extending — what you'll touch

| Want to | Touch |
|---|---|
| Add a new template token | Extend `$select` in `GraphClient` and the `TokenCatalog` list — the editor menu picks it up automatically |
| Add a new rule condition | Add a column in a new migration, extend `Rule` + `RuleRepository`, add the matcher in `RuleEngine::matches()`, add the form field |
| Add a starter template or snippet | `StarterTemplates::all()` or `SnippetLibrary::all()` — both return PHP arrays |
| Allow more HTML in templates | `TemplateRenderer::purifier()` — extend `HTML.Allowed` |
| Change the manifest target | `ManifestGenerator::build()` |
| Add an API endpoint for the add-in | `src/routes.php` (`/api/...`), bypasses CSRF middleware automatically |
| Add an audit-log action | Inject `AuditLogger` into the controller and call `record(tenantId, action, targetType, targetId, summary, payload)` after the mutation |

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
# Production — run migrations after upload (if shell available)
php bin/migrate.php
# Otherwise paste the SQL from migrations/<n>_*.sql into phpMyAdmin
```

```sql
-- Reset everything from scratch (destructive — for dev only)
DROP DATABASE signatureportal;
CREATE DATABASE signatureportal CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL ON signatureportal.* TO 'sigportal'@'%';
-- Then delete config/config.php and re-run /install.php
```
