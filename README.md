# SignaturePortal

> Self-hosted email signature management for Microsoft 365 — runs on shared PHP hosting.

SignaturePortal is an open-source alternative to commercial signature managers. It centrally manages Outlook signature templates and delivers the right one to each user at the moment they compose an email, using a lightweight Outlook Add-in.

It is designed to run on **inexpensive shared PHP hosting** (tested on IONOS) — no Node.js, no Docker, no dedicated server required.

## Features

- **Browser-based template editor** — write signature HTML, preview live, use `{placeholder}` tokens
- **Rule engine** — pick the right template based on FROM domain, user language, and mailbox type (personal / shared)
- **Multi-tenant** — manage multiple organisations from one installation, each with its own Entra app, branding, templates, and rules
- **Two portal auth modes** — local accounts (bcrypt) and Microsoft Entra ID SSO
- **Per-tenant API key** — rotatable from the portal; protects the signature endpoint from public access
- **Shared mailbox support** — falls back to the sender's primary profile when sending from a shared mailbox, derives the right display address per tenant convention
- **Single-package deployment** — drop the directory into your web root, run the installer, done
- **No build step** — pure PHP + Twig + vanilla JS; Office.js loaded from CDN

## Architecture

```
                        ┌───────────────────────┐
                        │   IONOS Shared Host   │
                        │   ┌───────────────┐   │
   Outlook Add-in ──────┼──▶│  Portal (PHP) │   │
   (Office.js, browser) │   │   - Web UI    │   │
                        │   │   - Sig API   │──┼──▶ MS Graph API
                        │   └───────┬───────┘   │
                        │           │           │
                        │   ┌───────▼───────┐   │
                        │   │   MySQL DB    │   │
                        │   └───────────────┘   │
                        └───────────────────────┘
```

The same PHP app serves both the management portal and the signature delivery API. Tenant configuration, templates, and rules live in MySQL. User profile data (name, title, phone) is fetched live from Microsoft Graph using stored per-tenant client credentials.

## Requirements

- PHP **8.1+**
- MySQL **5.7+** or MariaDB **10.3+**
- HTTPS (Outlook refuses to load add-ins over HTTP)
- A Microsoft Entra (Azure AD) app registration **per tenant** — see [docs/entra-app-setup.md](docs/entra-app-setup.md)

PHP extensions used: `pdo`, `pdo_mysql`, `curl`, `mbstring`, `openssl`, `sodium`, `json`, `dom`. All are default on IONOS.

## Quick start

### 1. Get the code on your host

Via SSH:

```bash
git clone https://github.com/<you>/signature-portal.git
cd signature-portal
```

Or download the latest release ZIP and upload via FTP. The repository ships with `vendor/` committed — no `composer install` required on the server.

### 2. Point your domain at `public/`

In your IONOS panel, set the document root of your domain (or subdomain — recommended: `signatures.example.com`) to the `public/` directory of the upload.

### 3. Create the database

In the IONOS panel, create a MySQL database. Note the host, database name, user, and password.

### 4. Run the installer

Visit `https://your-domain.example.com/install.php` in a browser. The installer will:

- check PHP version and extensions,
- ask for DB credentials,
- ask for an admin email and password,
- generate `config/config.php` and an `APP_KEY` (32 bytes; encrypts secrets at rest),
- run database migrations.

**Delete `public/install.php` after the installer finishes** — the installer reminds you.

### 5. Add your first tenant

Log in at `https://your-domain.example.com/portal`.

Create a tenant. You will need:

- A name and slug (e.g. `acme-gmbh`)
- The Entra **Tenant ID**, **Client ID**, and **Client Secret** for an app registration with `User.Read.All` (application permission, admin-consented). See [docs/entra-app-setup.md](docs/entra-app-setup.md).

The tenant editor will generate an API key — copy it; you will embed it in the add-in manifest.

### 6. Add templates and rules

In the portal:

- **Templates** — paste or write HTML, use `{first_name}`, `{last_name}`, `{job_title_line}`, `{phone_lines}`, `{email}` tokens. Preview with sample data before saving.
- **Rules** — for each template, declare when it applies (from-domain, optional language, optional mailbox type, priority, fallback flag). Test rule matching with the built-in simulator.

### 7. Deploy the Add-in

Download the manifest from the portal — it is pre-filled with your domain, tenant slug, and API key. Then either:

- **Centralised (recommended)**: upload via the Microsoft 365 admin centre → *Integrated apps → Upload custom apps → My organization*. Assign to users / groups.
- **Sideload (testing)**: in Outlook, *Get Add-ins → My add-ins → Add a custom add-in → Add from File*.

Full instructions for both paths: [docs/deploy-addin.md](docs/deploy-addin.md).

## Configuration reference

All runtime config lives in `config/config.php`. The installer creates it; you can edit it later.

| Key | Purpose |
|---|---|
| `db.*` | MySQL host, name, user, password |
| `app_key` | 32-byte key for at-rest secret encryption (`base64:...`). **Do not change after install.** |
| `base_url` | Public HTTPS URL of the install (no trailing slash) |
| `auth.local.enabled` | Allow local password login |
| `auth.entra.enabled` | Allow Entra SSO login (per-tenant config in DB) |
| `session.lifetime` | Portal session lifetime in minutes |

## Security notes

- The signature API endpoint (`/api/sig`) requires the per-tenant API key. **Anyone with the key can fetch any user's rendered signature for that tenant.** Treat it like a secret; rotate immediately if it leaks. Rotation is one click in the portal — it invalidates the old key and you re-deploy the manifest.
- Microsoft Graph credentials are stored encrypted with `APP_KEY` (libsodium). If you lose `APP_KEY`, you must re-enter Entra credentials for every tenant.
- HTTPS is **required**. Outlook will refuse to load the add-in over HTTP.
- HTML templates are sanitised with HTML Purifier before storage to mitigate stored XSS in the portal.
- Standard hardening headers are set: `X-Frame-Options`, `Content-Security-Policy`, `Strict-Transport-Security`, `X-Content-Type-Options`.
- The portal uses CSRF tokens on every state-changing form.

## Documentation

- [Entra app registration walkthrough](docs/entra-app-setup.md)
- [Deploying the add-in (centralised + sideload)](docs/deploy-addin.md)
- [Template token reference](docs/templates.md)
- [Rule engine reference](docs/rules.md)
- [Backup and recovery](docs/backup.md)
- [Upgrading](docs/upgrade.md)

## Roadmap

- Per-tenant branding (logo, accent colour) in portal UI
- Template version history with rollback
- Webhook on rule/template change for cache invalidation
- Outlook Web (OWA) deployment guide
- Localisation of portal UI

Issues and PRs welcome.

## Disclaimer

> **THIS SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE, AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES, OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF, OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.**

You are solely responsible for evaluating whether this software meets your operational, security, and compliance requirements. The authors make no representation that the software is suitable for processing personal data under GDPR or any other regulation; you are responsible for performing your own data-protection impact assessment, configuring access controls appropriately, and securing the credentials you store in it. The Microsoft Graph integration accesses user directory data — review your tenant's privacy and consent policies before deployment.

This project is not affiliated with, endorsed by, or sponsored by Microsoft Corporation. "Outlook", "Microsoft 365", and "Entra" are trademarks of Microsoft Corporation. "IONOS" is a trademark of IONOS SE.

## License

Apache License 2.0 — see [LICENSE](LICENSE).

Drop the official Apache 2.0 license text into the `LICENSE` file:
<https://www.apache.org/licenses/LICENSE-2.0.txt>

## Contributing

PRs welcome. Please:

1. Open an issue first for non-trivial changes.
2. Match existing code style (`declare(strict_types=1)`, PSR-12).
3. Add tests for behaviour changes.
4. Do not introduce dependencies that need a build step or shell access on the server.
