# SignaturePortal — Deployment & Operation Guide

This document is for **administrators** who want to host SignaturePortal on their own shared PHP hosting (IONOS, Strato, HostEurope, all-inkl, etc.) and manage signatures for one or more Microsoft 365 tenants.

It covers two things:

- **Part 1 — Deployment**: how to install the portal on your hoster, configure SSL, run the installer, and ship the Outlook add-in to your users.
- **Part 2 — Operation**: how the portal works once it's live — tenants, templates, rules, overrides, the asset library, the audit log — so you can drive it day-to-day.

If you only want to know what the project is, read the [README](README.md) first.

---

## Part 1 — Deployment

### What you'll need

| | Version | Notes |
|---|---|---|
| Shared PHP hosting | LAMP-style with `mod_rewrite` | Tested on IONOS Webhosting; equivalent plans at Strato, HostEurope, all-inkl, etc. all work. The project does **not** require shell access — `vendor/` is shipped in the repo. |
| PHP | 8.1+ (tested up to 8.4) | Extensions: `pdo`, `pdo_mysql`, `curl`, `mbstring`, `openssl`, `sodium`, `json`, `dom`. All ship by default on every mainstream PHP host. |
| MySQL / MariaDB | MySQL 5.7+ or MariaDB 10.3+ | Whatever your plan provides. |
| HTTPS | mandatory | Outlook refuses to load add-ins over HTTP. Use the hoster's free Let's Encrypt cert. |
| Microsoft Entra app registration | one per tenant | Application permission `User.Read.All` (admin-consented). The same registration powers Microsoft Graph signature delivery and Entra SSO. |
| FTP / SFTP client | any | FileZilla, Cyberduck, WinSCP — anything you're used to. |

### Where it runs (and where it doesn't)

| Outlook client | Auto-applied signature | Manual ribbon button |
|---|---|---|
| Outlook on the web (OWA) | ✅ | ✅ |
| New Outlook for Windows | ✅ | ✅ |
| Classic Outlook for Windows (recent builds) | ✅ | ✅ |
| Outlook for Mac | ✅ | ✅ |
| **Outlook iOS / Android** | ❌ | ❌ |

Mobile clients **do not** support event-based add-ins. If you need signature rewriting on mobile, look at an Exchange Online transport rule (very limited templating) or a server-side SMTP relay that intercepts outbound mail and calls this portal's `/api/sig`. Neither ships in this repo today.

### Step-by-step deployment

These steps describe a fresh install. Naming uses `signatures.example.com` as the public hostname and `example.com` as your main domain — substitute your own.

#### 1. Prepare a subdomain

In your hoster's control panel:

1. Create a subdomain — `signatures.example.com` is a good convention (keeps OAuth redirects and manifest URLs tidy).
2. Set the **document root** to a folder that does **not yet exist**, e.g. `/htdocs/signaturen/public`. The portal expects requests routed to its `public/` directory; pointing at the project root would expose `src/`, `config/` and `vendor/`.
3. Activate **HTTPS** with a free Let's Encrypt certificate (every mainstream hoster offers a one-click in the SSL section).

#### 2. Create a MySQL database

1. In the hoster's database section, create a new MySQL database. Note the host (looks like `db5xxxxxxx.hosting-data.io` on IONOS, `rdbms.strato.de` on Strato, similar on others), database name, user, and password.
2. The user needs CREATE / ALTER / INSERT / UPDATE / DELETE / SELECT privileges on its own database — that's the default for hoster-provisioned DBs.

#### 3. Upload the project

Only these folders need to live on the server at runtime:

```
/htdocs/signaturen/
├── bin/
├── config/
├── migrations/
├── public/      ← subdomain document root points here
├── src/
├── templates/
├── var/
└── vendor/
```

Documentation files (`README.md`, `SETUP.md`, `LICENSE`) and `.git/` aren't needed and don't need to be uploaded — they're for browsing the repo on GitHub, not for running the portal.

For most users: drag-and-drop the seven folders via FileZilla / Cyberduck. The slowest part is `vendor/` (~3000 files); allow 5–10 minutes the first time. Subsequent updates are quick because most files don't change.

If your plan has SSH/git, you can `git clone` directly on the host instead.

#### 4. Set folder permissions

The PHP user needs write access to:

- `config/` — the installer writes `config/config.php` here on first run.
- `var/cache/` — Twig and HTMLPurifier cache compiled output here.
- `var/log/` — runtime logs.
- `public/assets/tenants/` — uploaded tenant images go here.

`chmod 775` on those four directories is enough on every hoster I've tested. If you don't have shell access, every FTP client lets you set permissions via right-click → "File Attributes" / "Permissions".

#### 5. Run the web installer

Open `https://signatures.example.com/install.php` in a browser.

**Install token.** On first load the installer writes a one-time secret to
`config/.install_token` on the server (outside the public web root, so it is
never reachable over HTTP) and asks you to paste it into the form. Retrieve the
file's contents via SFTP or your hosting file manager and copy them into the
**Install token** field. This proves you control the server and stops anyone who
finds the not-yet-installed instance from claiming it before you. The token is
deleted automatically once installation succeeds.

Fill in:

| Field | Value |
|---|---|
| Install token | contents of `config/.install_token` (see above) |
| DB host | the hostname from step 2 (not `localhost` on shared hosting!) |
| DB port | `3306` |
| DB name / user / pass | from step 2 |
| Base URL | `https://signatures.example.com` (HTTPS, no trailing slash) |
| Admin email | your email |
| Admin password | min 10 chars |

The installer:

1. Verifies your PHP version and the required extensions.
2. Connects to MySQL and runs every numbered migration in `migrations/` to create the schema.
3. Generates a fresh 32-byte `APP_KEY` (32 random bytes, base64) used to encrypt secrets at rest.
4. Writes `config/config.php` with all settings, including `debug => false`.
5. Creates your first user with role `superadmin`.

#### 6. Delete the installer — immediately

`public/install.php` is reachable without authentication. After the success page, **delete it from the server via FTP**. The file refuses to do anything if `config/config.php` already exists, but leaving installer code on a public surface is poor hygiene.

```
public/install.php      ← DELETE
public/index.debug.php  ← never upload this; it's only a local-dev helper
```

#### 7. Sign in and create a tenant

Open `https://signatures.example.com/portal/login` and sign in.

For each Microsoft 365 organisation you want to manage:

1. Go to **Tenants → New tenant**, give it a slug (e.g. `acme-gmbh`) and a name.
2. Click into the tenant. You'll see an **API key** generated automatically — note it for the manifest later.
3. Edit the tenant and fill in the **Entra credentials**: tenant ID, client ID, client secret. See [Configuring an Entra app](#configuring-an-entra-app-per-tenant) below if you don't have those yet.
4. Add the tenant's **owned email domains** so the rule engine can classify recipients as internal vs external.

#### 8. Add templates and rules

In the tenant:

- **Images** — upload logos, headshots, etc. Each gets a public URL you copy into your templates.
- **Templates** — write or paste HTML, use `{display_name}`, `{job_title}`, `{full_address}` and other tokens. Live preview shows you the result; preview width toggles let you test desktop vs mobile rendering.
- **Rules** — for each template, declare when it applies (from-domain, language, mailbox type, recipient scope, validity window, priority, fallback flag). Test rule matching with the **Simulator** before going live.

#### 9. Generate and distribute the Outlook manifest

1. On the tenant overview, click **Download manifest.xml**. The file contains your tenant slug + API key, baked in.
2. Distribute the manifest:
   - **Centrally (recommended)**: M365 admin centre → *Integrated apps → Upload custom apps → My organization*. Assign to users / groups. The add-in installs silently and starts firing on `OnNewMessageCompose`.
   - **Sideload (for testing)**: in Outlook → *Get Add-ins → My add-ins → Add a custom add-in → Add from File*.

That's it for the deployment. Users open Outlook, compose a message, and the right signature is applied automatically.

### Configuring an Entra app per tenant

Each Microsoft 365 organisation needs its own app registration. The same app provides both **Microsoft Graph user lookups** (so we can read names, titles, phone numbers) and **Entra SSO** (so portal admins can sign in with their Microsoft account).

In the [Azure portal](https://portal.azure.com/) → *Microsoft Entra ID* → *App registrations* → *New registration*:

1. **Name**: anything memorable, e.g. *SignaturePortal — Acme*.
2. **Supported account types**: *Accounts in this organizational directory only*.
3. **Redirect URI**: leave empty for now (you'll set it from the SignaturePortal UI in [SSO setup](#configuring-entra-sso)).
4. Click **Register**.

After creation, on the new app page:

5. **API permissions** → *Add a permission* → *Microsoft Graph* → *Application permissions* → tick `User.Read.All` → *Add permissions* → click **Grant admin consent**. (The status column must turn green for that row.)
6. **Certificates & secrets** → *New client secret*. Pick an expiry (24 months is reasonable). Copy the **secret value** the moment it appears — Azure won't show it again.
7. Note **Application (client) ID** and **Directory (tenant) ID** from the Overview page.
8. In SignaturePortal, edit the tenant and paste tenant ID, client ID, and the client secret. The secret is encrypted with `APP_KEY` (libsodium) the moment you save.

### Configuring Entra SSO

After the steps above, the same app registration also lets your admins sign in via "Sign in with Microsoft" (so they don't need a separate password for the portal).

1. In SignaturePortal: open the tenant — the overview page shows an **SSO redirect URI** (e.g. `https://signatures.example.com/portal/sso/acme-gmbh/callback`). Copy it.
2. Back in Azure → app registration → **Authentication** → *Add platform* → *Web* → paste the redirect URI. Tick *ID tokens* under "Implicit grant and hybrid flows". Save.
3. (API permissions): make sure delegated `openid`, `profile`, `email` are present. They typically come pre-granted with the registration; add them if missing.
4. In SignaturePortal: edit the tenant, tick **Enable Entra SSO**. Decide whether to **auto-provision** unknown sign-ins; if so, pick a default role.
5. The login page now shows a **Sign in with Microsoft — *Tenant Name*** button beneath the email/password form.

If Microsoft rejects the redirect with `AADSTS50011: redirect URI mismatch`, the URI in Azure does not exactly match the one shown on the tenant page (port, scheme, trailing slash all matter — copy verbatim).

### Configuration reference

`config/config.php` is created by the installer. You can edit it later by hand if needed.

| Key | Purpose |
|---|---|
| `db.*` | MySQL host, port, name, user, password |
| `app_key` | 32-byte key for at-rest secret encryption (`base64:...`). **Never change after install** — every Entra client secret encrypted by the old key becomes unrecoverable. |
| `base_url` | Public HTTPS URL of the install (no trailing slash) |
| `debug` | When `true`, unhandled exceptions render with full stack traces in the browser. **Never set true in production.** Default `false`. |
| `auth.local.enabled` | Allow local password login |
| `auth.entra.enabled` | (Reserved — Entra SSO is configured per-tenant in the portal UI) |
| `session.name` / `session.lifetime` | Portal session cookie name and lifetime in minutes |

### Upgrading to a newer release

1. Back up the database (always).
2. Upload the new versions of `src/`, `templates/`, `public/`, `migrations/`, and `vendor/`. `config/config.php` is preserved across upgrades — never overwrite it. The same goes for everything under `var/cache/` (just leave the directory in place; Twig regenerates).
3. Apply any new SQL migrations:
   - **With shell access**: `php bin/migrate.php`.
   - **Without shell**: open phpMyAdmin (or your hoster's DB console), copy-paste the contents of each new `migrations/<n>_*.sql` file, run it. The runner records applied migrations in a `migrations` table, but for hand-applying you just need to track which numbers have already run.

---

## Part 2 — Operation

This section describes how SignaturePortal works once it's live, so you can use it day-to-day. It's reference material — read it linearly the first time, then dip in when you need a specific concept.

### The two halves

SignaturePortal is one PHP application that serves two audiences from the same codebase:

- **Portal** — the browser UI for admins (templates, rules, overrides, tenants, simulator, users, audit log). Routes are everything under `/portal/*`. Authenticated via local password (bcrypt) or Microsoft Entra OIDC.
- **Add-in API** — the endpoint the Outlook add-in calls at compose time, `GET /api/sig?tenant=…&email=…&primary=…&recipients=…&key=…`. Authenticated via the per-tenant API key (or `X-Sig-Key` header). CSRF and session middleware are explicitly skipped here.

Both run from the same Slim 4 app, share the same MySQL, share the same `APP_KEY` for crypto.

### Tenant

A tenant represents an organisation. It owns:

- **Slug** — short identifier, used in URLs and embedded in the manifest (`acme-gmbh`). Cannot be changed after creation.
- **Owned email domains** — one or more domains the tenant operates (`acme.com`, `acme.de`). Used to classify recipients as internal vs external.
- **Entra credentials** — `tenant_id`, `client_id`, `client_secret`. The secret is encrypted with libsodium (`crypto_secretbox`) keyed off `APP_KEY`.
- **API key** — random 32-byte URL-safe string. Stored encrypted (so the portal can show it on rotate, and `/api/sig` can constant-time-compare against it). Rotation is one click; the old key stops working immediately and a banner reminds admins to redistribute the manifest until acknowledged.
- **Manifest GUID** — stable v4 UUID generated at tenant creation; embedded as the `<Id>` of the OfficeApp manifest XML so Outlook treats it as the same add-in across re-installs.
- **Shared mailbox display mode** — `shared` (default; show the team mailbox in `{email}`) or `derived` (compute a personal address like `s.mueller@acme.com` from the original sender).
- **Disclaimer HTML** — optional legal footer, sanitized like a template, appended to every rendered signature. Token substitution applies.

Multi-tenant from day one. **Every query that touches tenant-owned data is scoped by `tenant_id`** — there is no global "templates table" view.

### Template

A template is HTML with `{placeholder}` tokens. Stored in MySQL, edited in the portal with a TinyMCE WYSIWYG editor and a live preview iframe.

#### Tokens

Every Microsoft Graph user property is exposed as a `{snake_case}` token automatically. The full list is built by `src/Tenant/TokenCatalog.php` and surfaced in the editor's grouped "Token" menu. Selected highlights:

| Group | Tokens |
|---|---|
| Identity | `{display_name}`, `{first_name}`, `{last_name}`, `{middle_name}`, `{employee_id}`, `{employee_type}` |
| Job | `{job_title}`, `{job_title_line}`, `{department}`, `{company_name}`, `{office_location}` |
| Contact | `{email}`, `{mail}`, `{user_principal_name}`, `{mobile_phone}`, `{business_phones}`, `{fax_number}`, `{phone_lines}` |
| Address | `{street_address}`, `{postal_code}`, `{city}`, `{state}`, `{country}`, `{full_address}` |
| Other | `{preferred_language}`, `{about_me}`, `{usage_location}` |

#### Conditional blocks

Wrap any content in `{if:NAME}…{/if}` to make it disappear automatically when the named token is empty. The editor's "Conditional" toolbar menu wraps the current selection for you.

```html
{if:mobile_phone}<div>Mob: {mobile_phone}</div>{/if}
{if:business_phones}<div>Tel: {business_phones}</div>{/if}
```

If a user has no mobile phone, the entire `<div>` (including the "Mob: " label) drops out — no dangling labels for missing Graph attributes.

#### Sanitisation on save

Every template runs through HTML Purifier. The allowlist is permissive (Outlook signatures need tables, inline styles, `font` tags, `<a target=_blank>`) but XSS-safe (`<script>`, `<iframe>`, JS event handlers, `javascript:` URLs are stripped). Plus two server-side normalisations: `<p>` → `<div>` (avoids the `<p>`-margin mismatch between editor and Outlook) and empty `<div></div>` → `<div><br></div>` (so a blank line is a blank line everywhere).

### Rule

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
| `is_enabled` | yes | Boolean toggle to deactivate without deleting. |
| `valid_from` / `valid_until` | no | Date window. Inclusive on both ends. Natural fit for seasonal banners (e.g. Christmas signature only `2026-12-15` to `2027-01-06`). |

#### Recipient scope (the recipient filter)

Often you only want a corporate signature on emails going **outside** the company. That's what `recipient_scope` does:

- `all` — always matches (default; signature goes on every send).
- `external` — matches if **at least one recipient is outside** the tenant's owned domains. The "any external recipient → external send" behaviour most signature managers use.
- `internal` — matches only if **every recipient is inside** the tenant's owned domains, and there is at least one recipient.

When the add-in fires `OnNewMessageCompose` with no recipients yet, only `all`-scope rules apply.

### Per-user override

Sits *above* the rule engine. Per tenant, you can pin a specific email address to a specific template — when `?primary=` matches, that template wins outright and the rule engine isn't consulted. Use case: "the boss wants their own signature regardless of what the rules say."

| Column | Purpose |
|---|---|
| `email` | match key (case-insensitive) |
| `template_id` | which template to render |
| `note` | free-text reminder for future you |

Manage via **Tenants → … → Overrides**.

### Shared mailbox detection (the licence heuristic)

When someone sends from a shared mailbox (`info@acme.com`), they have an Entra user object — but **no `assignedLicenses`**. Personal mailboxes always carry a licence. We use that as the detection signal:

1. Add-in sends `email=info@acme.com&primary=anna.beispiel@acme.com`.
2. We fetch `info@acme.com`'s Graph profile → it exists, but `assignedLicenses` is `[]` → shared mailbox.
3. We fetch `anna.beispiel@acme.com`'s profile instead and use its values for tokens (name, title, phone…).
4. The `{email}` token gets either `info@acme.com` (mode `shared`, default) or `a.beispiel@acme.com` (mode `derived`) depending on the tenant's preference.
5. Response sets `X-Sig-Shared: true` so the add-in can show consistent UI.

Configured per tenant in **Tenants → … → Settings → Shared mailbox display mode**.

### Image asset library

Per-tenant image storage at `public/assets/tenants/<slug>/<filename>`. PNG/JPG/GIF/WebP, up to 5 MB each. Images are served directly by the web server (no PHP roundtrip), referenced from templates by URL. Filename is sanitised (`a-z0-9_-` only, lowercase, max 80 chars) and MIME-sniffed via `finfo` to reject content/extension mismatches.

Manage via **Tenants → … → Images**, or drag-and-drop / paste an image directly into the template editor (uploads via a JSON endpoint, inserts the resulting URL).

### Audit log

Append-only `audit_log` table. Every mutation writes an entry: tenant.created, template.updated, rule.deleted, override.created, user.password_reset, tenant.api_key.rotated, and so on. Each entry includes actor email, IP, target type/id, and an optional payload. Visible per tenant under **Tenants → … → Activity** (latest 200 entries).

### Authentication

#### Local

Bcrypt password hash, PHP file-backed sessions. Failed-login lockout: **5 wrong attempts in 10 minutes → 15-minute lock** on that email. The error message is generic ("Invalid email or password") for both wrong-password and locked states, so an attacker can't enumerate accounts by triggering lockouts. Lock events are written to `error_log` for ops visibility.

Self-service password change at `/portal/account`. Superadmin user-management UI at `/portal/users` for CRUD across all users.

#### Entra SSO

Per-tenant Azure app registration; OIDC v2.0 code flow. See [Configuring Entra SSO](#configuring-entra-sso) above for the setup steps.

Match precedence: `entra_object_id` (the stable `oid` claim) → email → auto-provision if enabled. When a local user signs in via SSO for the first time their `oid` is linked to the existing record so future logins are oid-matched (immune to email rename in Entra).

Both auth paths converge on the same session model. RBAC roles: `superadmin` (cross-tenant), `tenant_admin`, `tenant_editor`.

### Critical flows

#### Signature delivery — Outlook → Portal

1. Office.js fires `OnNewMessageCompose` in Outlook.
2. The add-in reads `Office.context.mailbox.userProfile.emailAddress` (primary) and `item.from.emailAddress` (current sender).
3. The add-in collects current `item.to` / `item.cc` / `item.bcc` recipients.
4. The add-in calls `GET /api/sig?tenant=<slug>&email=<from>&primary=<primary>&recipients=<csv>` with `X-Sig-Key: <api_key>` header.
5. The portal:
   1. validates the tenant slug and constant-time-compares the API key (single 401 response for both unknown-slug and bad-key — no enumeration).
   2. resolves the user's Graph profile (token cached in `graph_token_cache` for ~50 min).
   3. detects shared mailbox via `assignedLicenses` heuristic — falls back to the primary user's profile if so.
   4. **checks per-user overrides first** — if `primary` matches an override, that template wins.
   5. otherwise matches rules in priority order (with date-window + recipient-scope evaluation), with fallbacks last.
   6. renders the matched template with profile tokens, applies conditional blocks, appends the tenant disclaimer.
   7. returns HTML with `Content-Type: text/html` and `X-Sig-Shared: true|false`.
6. The add-in injects via `Office.context.mailbox.item.body.setSignatureAsync`.

If anything Graph-related fails, the response is `502 graph lookup failed` — the actual Graph error is logged server-side but never returned to the caller (an API-key holder shouldn't be able to enumerate emails through Graph errors).

### Add-in deployment

The portal generates an OfficeApp manifest XML at:

```
GET /portal/tenants/{id}/manifest.xml
```

The manifest embeds the tenant slug, API key, manifest GUID, and base URL. Distribute it via:

- **Centrally**: M365 admin centre → *Integrated apps → Upload custom apps → My organization*. Assign to users / groups (recommended).
- **Sideload**: Outlook → *Get Add-ins → My add-ins → Add a custom add-in → Add from File* (testing only).

**The manifest contains the API key in plaintext.** Anyone with a copy of the manifest can call `/api/sig` for that tenant. If a manifest leaks, **rotate the API key immediately** in the portal — the old key stops working at once. The portal banners until you acknowledge that the new manifest has been redistributed.

The manifest structure follows Microsoft's recommended event-based add-in shape:

- **OfficeApp top-level**: AppDomains for `login.microsoftonline.com` and `graph.microsoft.com`; FormSettings pointing at the taskpane; Mailbox MinVersion 1.1.
- **VersionOverrides V1_0** (Mailbox 1.3+): a manual "Refresh" ribbon button that opens the taskpane.
- **VersionOverrides V1_1** (Mailbox 1.10+): the `<Runtimes>` block (WebView + JS override — needed for classic Outlook for Windows), `SupportsSharedFolders: true`, and a single `OnNewMessageCompose` LaunchEvent.

Permissions: `ReadWriteMailbox` (needed for `setSignatureAsync`).

### Simulator

In each tenant: **Tenants → … → Simulator**. Plug in a hypothetical send — FROM, optional primary user, recipients, language, mailbox type — and the portal shows:

- which rule (or override) the engine picks (or "no rule matched")
- a per-rule decision trace (why each rule did or did not match), including disabled / out-of-window reasons
- the matched template, rendered in a sandboxed iframe with sample tokens **or** with live Graph data if you tick "Use live Graph data"
- the resolved `{email}` value when the FROM is treated as a shared mailbox

Useful as a regression-check before changing live rules, and as a "preview as user X" tool when wiring up a new template.

### Editor (TinyMCE) feature highlights

- **Free-text font-size input** — type any value (`13px`, `10.5pt`, `1.2em`).
- **Line-height dropdown** — applied to the current block.
- **Word/MSO paste cleanup** — conditional comments, `<o:p>` namespaces, `mso-*` properties and `MsoNormal` classes stripped automatically; `<p>` → `<div>` on paste.
- **Token / Conditional / Snippet menus** — feed from server-side catalogues so adding a new Graph attribute or building block is one PHP edit.
- **Layout-table wizard** — produces Outlook-friendly tables with `vertical-align:top` + padding.
- **Image library picker** — browse the tenant's uploaded images and insert one with width + alt text in two clicks.
- **Drag-and-drop / paste image upload** — files go straight to the asset library via a CSRF-protected JSON endpoint.
- **Live preview iframe** with width toggle (Desktop / Tablet / Mobile / 600 / 375 px).
- **Auto-save to localStorage** with a restore banner — survives accidental tab close or browser crash.

---

## Troubleshooting

| Symptom | Look at |
|---|---|
| Apache 500 with no Slim error page | Most likely `.htaccess` issue — confirm `RewriteBase /` is present and `mod_rewrite` is enabled. Also check `var/cache/` and `var/log/` are writable (775). |
| Slim error page in production with full stack trace | Set `'debug' => false` in `config/config.php`. The error middleware reads this flag. |
| Generic 500 with no PHP error in the response | Check the hoster's error log. Common cause: OPcache holding a stale copy after upload. Toggling the PHP version in your hoster's panel forces a pool restart on most plans. |
| Add-in installs but never fires | Manifest is missing the `<Runtimes>` block or has too-strict `MinVersion`. The current generator handles both correctly — re-download the manifest after any portal upgrade. |
| `manifest.xml` says "no API key" | Rotate the API key on the tenant overview page. |
| Live preview iframe stays blank | Watch the browser console; CSRF rejection shows as HTTP 419. |
| Rule never matches in production but matches in the simulator | Confirm the tenant's `email_domains` are correct — recipient classification depends on them. Also check `is_enabled` and the validity window. |
| `/api/sig` returns `502 graph lookup failed` | Server log has the actual Graph message. Most common: missing admin consent for `User.Read.All`, or a stale client secret. |
| Installer says "config exists, can't re-run" | Drop the database and delete `config/config.php`, then re-run. (Do not do this in production unless you mean it — Entra secrets become unrecoverable.) |
