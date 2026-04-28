# SignaturePortal

> Self-hosted email signature management for Microsoft 365 — runs on shared PHP hosting.

SignaturePortal is an open-source alternative to commercial signature managers. It centrally manages Outlook signature templates and delivers the right one to each user at the moment they compose an email, using a lightweight Outlook Add-in.

It is designed to run on **inexpensive shared PHP hosting** — IONOS, Strato, all-inkl, HostEurope, or any LAMP-style host with mod_rewrite. No Node.js, no Docker, no dedicated server required, no shell access required at runtime.

## Features

- **Browser-based template editor** (TinyMCE) — write signature HTML, preview live, use `{placeholder}` tokens, drag-and-drop images straight into a per-tenant asset library.
- **Rule engine** — pick the right template based on FROM domain, user language, mailbox type (personal / shared), recipient scope (internal / external), priority, and an optional date validity window.
- **Conditional template syntax** — `{if:mobile_phone}…{/if}` blocks disappear automatically when a Graph attribute is empty.
- **Per-user overrides** — bypass the rule engine for specific addresses ("the boss wants their own signature").
- **Multi-tenant** — manage multiple organisations from one installation, each with its own Entra app, image library, templates, and rules.
- **Two portal auth modes** — local accounts (bcrypt + lockout after 5 failed attempts) and Microsoft Entra ID SSO with optional auto-provisioning.
- **Per-tenant API key** — rotatable from the portal, banner reminds admins to redistribute the manifest.
- **Shared mailbox support** — detects shared mailboxes via the `assignedLicenses` heuristic, falls back to the original sender's profile, and either keeps the shared address or derives a personal-style address per tenant preference.
- **Audit log** — every template / rule / override / key-rotation / user change is recorded with actor + IP.
- **Live simulator** — pick a real Graph user, see exactly which rule fires and what the rendered signature looks like before publishing.
- **Single-package deployment** — drop the directory into your web root, run the installer, done.
- **No build step** — pure PHP + Twig + vanilla JS; Office.js loaded from CDN.

## Architecture

```
                       ┌───────────────────────┐
                       │   Shared PHP host     │
                       │   ┌───────────────┐   │
   Outlook Add-in ─────┼──▶│  Portal (PHP) │   │
   (Office.js, browser)│   │   - Web UI    │   │
                       │   │   - Sig API   │──┼──▶ MS Graph API
                       │   └───────┬───────┘   │
                       │           │           │
                       │   ┌───────▼───────┐   │
                       │   │   MySQL DB    │   │
                       │   └───────────────┘   │
                       └───────────────────────┘
```

The same PHP app serves both the management portal and the signature delivery API. Tenant configuration, templates, rules, overrides, and audit log live in MySQL. User profile data (name, title, phone, full address, etc.) is fetched live from Microsoft Graph using stored per-tenant client credentials.

## Where it runs (and where it doesn't)

| Outlook client | Auto-applied signature | Manual ribbon button |
|---|---|---|
| Outlook on the web (OWA) | ✅ | ✅ |
| New Outlook for Windows | ✅ | ✅ |
| Classic Outlook for Windows (recent builds) | ✅ | ✅ |
| Outlook for Mac | ✅ | ✅ |
| **Outlook iOS / Android** | ❌ | ❌ |

Mobile clients **do not** support event-based add-ins. If you need signature rewriting on mobile, the typical solutions are an Exchange Online transport rule (limited templating) or a server-side SMTP relay that intercepts outbound mail and calls this portal's `/api/sig` endpoint. Neither ships in this repo today; the API is a clean fit for either if you want to build it.

## Requirements

- PHP **8.1+** (tested up to 8.4)
- MySQL **5.7+** or MariaDB **10.3+**
- HTTPS (Outlook refuses to load add-ins over HTTP)
- A Microsoft Entra (Azure AD) app registration **per tenant**, with the `User.Read.All` application permission (admin-consented). The same registration powers Graph signature delivery and Entra SSO.

PHP extensions used: `pdo`, `pdo_mysql`, `curl`, `mbstring`, `openssl`, `sodium`, `json`, `dom`. All ship by default on every mainstream PHP host.

## Quick start

A full step-by-step walkthrough — shared-host deployment, Entra app setup, manifest generation, sideloading, troubleshooting — lives in [SETUP.md](SETUP.md).

The five-second version:

```bash
# 1. Get the code on the host
git clone https://github.com/<you>/signature-portal.git
# 2. Point your subdomain at <project>/public/
# 3. Visit https://your-domain/install.php in a browser, fill the form
# 4. DELETE public/install.php right after the success page
# 5. Log in, create a tenant, upload images, write templates, define rules
# 6. Download the OfficeApp manifest from the tenant page, sideload into Outlook
```

## Configuration reference

All runtime config lives in `config/config.php`. The installer creates it; you can edit it later.

| Key | Purpose |
|---|---|
| `db.*` | MySQL host, port, name, user, password |
| `app_key` | 32-byte key for at-rest secret encryption (`base64:...`). **Do not change after install** — existing encrypted Entra secrets and API keys become unrecoverable. |
| `base_url` | Public HTTPS URL of the install (no trailing slash) |
| `debug` | When `true`, unhandled exceptions render full stack traces in the browser. **Never set true in production.** |
| `auth.local.enabled` | Allow local password login |
| `auth.entra.enabled` | (Reserved — Entra SSO is configured per-tenant in the portal UI) |
| `session.name` / `session.lifetime` | Portal session cookie name and lifetime in minutes |

## Security notes

- **`/api/sig` is key-protected** — anyone with the API key can fetch signatures for that tenant. Treat it like a secret. Rotation is one click in the portal; the old key stops working immediately and the portal banners you to redistribute the manifest.
- **Microsoft Graph credentials are encrypted at rest** with `APP_KEY` (libsodium `crypto_secretbox`). If you lose `APP_KEY`, you must re-enter Entra credentials for every tenant.
- **HTTPS is required.** Outlook will refuse to load the add-in over HTTP, and session cookies are marked `Secure` only when `base_url` starts with `https://`.
- **HTML templates are sanitised on save** with HTML Purifier — stored XSS in the portal is mitigated even if a tenant editor pastes hostile HTML.
- **CSRF tokens** on every state-changing form. The `/api/*` namespace is exempt because it is API-key-authenticated, not session-authenticated.
- **Failed-login lockout** — 5 wrong attempts in 10 minutes locks the local account for 15 minutes. SSO sign-ins are unaffected.
- **Hardening headers** set in `public/.htaccess`: `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: strict-origin-when-cross-origin`. (No CSP — Outlook's signature renderer is far too tolerant for a meaningful CSP to make sense, and the portal UI itself ships no inline scripts beyond the editor.)
- **Append-only audit log** — every mutation (template, rule, override, user, API key) is recorded with actor email and IP, viewable per tenant under *Activity*.

## Roadmap

- Per-tenant branding (logo, accent colour) in the portal UI
- Template version history with rollback
- Localisation of the portal UI
- Optional SMTP-relay companion for mobile signature rewriting
- 2FA for local portal accounts

Issues and PRs welcome.

## Disclaimer

> **THIS SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE, AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES, OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF, OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.**

You are solely responsible for evaluating whether this software meets your operational, security, and compliance requirements. The authors make no representation that the software is suitable for processing personal data under GDPR or any other regulation; you are responsible for performing your own data-protection impact assessment, configuring access controls appropriately, and securing the credentials you store in it. The Microsoft Graph integration accesses user directory data — review your tenant's privacy and consent policies before deployment.

This project is not affiliated with, endorsed by, or sponsored by Microsoft Corporation. "Outlook", "Microsoft 365", "Entra", and "Azure" are trademarks of Microsoft Corporation. Other product or company names mentioned in this documentation may be trademarks of their respective owners.

## License

Apache License 2.0 — see [LICENSE](LICENSE).

This project bundles vendored dependencies under their own licences (so it can be deployed without `composer install` on the server). Notable: `ezyang/htmlpurifier` is **LGPL-2.1-or-later**, which is compatible with Apache-2.0 distribution. All other vendored packages are MIT or BSD-style. The full dependency list lives under `vendor/<vendor-name>/<package>/LICENSE`.

## Contributing

PRs welcome. Please:

1. Open an issue first for non-trivial changes.
2. Match existing code style (`declare(strict_types=1)`, PSR-12).
3. Add tests for behaviour changes.
4. Do not introduce dependencies that need a build step or shell access on the server — the lowest common denominator is shared PHP hosting without SSH.
