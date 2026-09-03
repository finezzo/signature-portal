<?php
declare(strict_types=1);

namespace App\Addin;

use App\Graph\GraphException;
use App\Graph\ProfileResolver;
use App\Tenant\DisplayEmailDeriver;
use App\Tenant\RuleContext;
use App\Tenant\RuleEngine;
use App\Tenant\Tenant;
use App\Tenant\TemplateRenderer;
use App\Tenant\TemplateRepository;
use App\Tenant\TenantRepository;
use App\Tenant\TenantService;
use App\Tenant\UserOverrideRepository;

/**
 * Orchestrates the live signature pipeline:
 *   tenant + key validation
 *   → profile resolution via ProfileResolver — Graph first, local store when
 *     Graph is unreachable (with shared-mailbox primary-user fallback)
 *   → display email derivation
 *   → rule selection
 *   → template render with profile tokens
 *
 * Returns a Result object the controller turns into an HTTP response.
 */
final class SignatureService
{
    public function __construct(
        private readonly TenantRepository $tenants,
        private readonly TenantService $tenantService,
        private readonly TemplateRepository $templates,
        private readonly RuleEngine $engine,
        private readonly TemplateRenderer $renderer,
        private readonly ProfileResolver $resolver,
        private readonly DisplayEmailDeriver $displayEmail,
        private readonly UserOverrideRepository $overrides,
    ) {}

    public function resolve(SignatureRequest $req): SignatureResult
    {
        // Single error message + status for both "tenant doesn't exist" and
        // "bad key" — otherwise an attacker can probe slugs and identify
        // which exist by inspecting the response body.
        $tenant = $this->tenants->findBySlug($req->tenantSlug);
        if ($tenant === null || !$this->tenantService->verifyApiKey($tenant, $req->apiKey)) {
            return SignatureResult::error(401, 'unknown tenant or invalid api key');
        }

        // Resolve the user's Graph profile, then decide whether the FROM
        // address is a shared mailbox.
        //
        // Two paths qualify as shared:
        //   (a) Graph returns 404 for the FROM address (legacy/edge case).
        //   (b) Graph returns a user object, but it has no assignedLicenses
        //       — Entra ID models shared/resource mailboxes as licence-less
        //       user objects. Personal mailboxes always carry a licence.
        //
        // In both cases we re-fetch the primary user's profile and use that
        // for token rendering (name, title, phone) while keeping the FROM
        // address as the display email.
        //
        // Lookups go through ProfileResolver, which writes every successful
        // Graph response through to the local profile store and replays it
        // when Graph is unreachable. `$fromCache` records whether the profile
        // that ends up rendering the signature was live or stored.
        $fromCache = false;
        try {
            $fromRes      = $this->resolver->resolve($tenant, $req->fromEmail);
            $fromProfile  = $fromRes?->profile;
            $isSharedFrom = $fromProfile === null || $fromProfile->looksLikeSharedMailbox();

            if (!$isSharedFrom) {
                $profile   = $fromProfile;
                $fromCache = $fromRes->fromCache;
            } else {
                if ($req->primaryEmail === '' || $req->primaryEmail === $req->fromEmail) {
                    // Shared mailbox detected but no primary user to fall back to.
                    // For path (a) this is a hard 404; for path (b) we have a profile
                    // but it lacks real user info — better to noop than render a bad sig.
                    if ($fromProfile === null) {
                        return SignatureResult::error(404, 'unknown sender and no primary user supplied');
                    }
                    return SignatureResult::error(404, 'shared mailbox detected but no primary user supplied');
                }
                $primaryRes = $this->resolver->resolve($tenant, $req->primaryEmail);
                if ($primaryRes === null) {
                    return SignatureResult::error(404, 'primary user not found in Graph');
                }
                $profile   = $primaryRes->profile;
                $fromCache = $primaryRes->fromCache;
            }
        } catch (GraphException $e) {
            // Graph is unreachable AND nothing is stored for the FROM address.
            // One last chance before giving up: the overwhelmingly common shape
            // of this request is "person sends from a shared mailbox", where the
            // FROM address may well have never been cached but the primary user
            // has. Treat that as the shared-mailbox path — which is what a live
            // Graph run would have concluded anyway — and continue below.
            $primaryRes = ($req->primaryEmail !== '' && $req->primaryEmail !== $req->fromEmail)
                ? $this->resolver->cached($tenant, $req->primaryEmail)
                : null;

            if ($primaryRes === null) {
                // Don't reflect the Graph message back to the caller — it may
                // describe the address that was queried, which would let an
                // API-key holder enumerate users by triggering Graph errors.
                // Server-side log keeps the diagnostic for ops; no email
                // addresses in it, per CLAUDE.md.
                error_log('[/api/sig] Graph lookup failed for tenant '
                    . $tenant->slug . ' (no cached profile): ' . $e->getMessage());
                return SignatureResult::error(502, 'graph lookup failed');
            }

            $profile      = $primaryRes->profile;
            $isSharedFrom = true;
            $fromCache    = true;
        }

        // Per-domain override of the display mode beats the tenant default —
        // lets `firma.de` use 'primary' (mustermann@firma.de) while
        // `muster.de` uses 'derived' (m.mustermann@muster.de).
        $fromDomain = mb_strtolower((string) substr(strrchr($req->fromEmail, '@') ?: '@', 1));
        $emailToken = $isSharedFrom
            ? $this->displayEmail->deriveForSharedMailbox(
                  $profile,
                  $req->fromEmail,
                  $tenant->displayModeForDomain($fromDomain),
              )
            : ($profile->mail ?? $profile->userPrincipalName);

        // Build the rule-engine context. Fields the caller supplied take
        // precedence over what we derived from the Graph profile.
        $language = $req->language ?? $profile->shortLanguage();
        $mailboxType = $req->mailboxType
            ?? ($isSharedFrom ? 'shared' : 'personal');

        $ctx = new RuleContext(
            $req->fromEmail,
            $language,
            $mailboxType,
            $req->recipients,
            $tenant->emailDomains,
        );

        // Per-user override sits ABOVE the rule engine. Match key is the
        // primary user's address — the actual person sending — so overrides
        // follow the user even when they send from a shared mailbox.
        $overrideEmail = $req->primaryEmail !== '' ? $req->primaryEmail : $req->fromEmail;
        $override      = $req->templateId === null
            ? $this->overrides->findByEmail($tenant->id, $overrideEmail)
            : null;

        if ($req->templateId !== null) {
            // Manual pick from the taskpane. Only admin-flagged templates are
            // reachable; 404 (not 403) so key holders can't probe which ids exist.
            $template = $this->templates->find($req->templateId, $tenant->id);
            if ($template === null || !$template->isManuallySelectable) {
                return SignatureResult::error(404, 'template not available for manual selection');
            }
        } elseif ($override !== null) {
            $template = $this->templates->find($override->templateId, $tenant->id);
            if ($template === null) {
                return SignatureResult::error(500, 'override references missing template');
            }
        } else {
            $rule = $this->engine->selectRule($tenant->id, $ctx);
            if ($rule === null) {
                return SignatureResult::noMatch($isSharedFrom);
            }
            $template = $this->templates->find($rule->templateId, $tenant->id);
            if ($template === null) {
                return SignatureResult::error(500, 'rule references missing template');
            }
        }

        $tokens   = TemplateRenderer::tokensFromProfile($profile, $emailToken);
        $rendered = $this->renderer->render($template->html, $tokens);

        // Tenant-wide disclaimer (legal footer) — appended to every signature
        // so admins don't have to copy HRB / Geschäftsführer lines into each
        // template. Tokens inside the disclaimer are also substituted so
        // {email} etc. work there too.
        if ($tenant->disclaimerHtml !== null && trim($tenant->disclaimerHtml) !== '') {
            $rendered .= $this->renderer->render($tenant->disclaimerHtml, $tokens);
        }

        return SignatureResult::ok($rendered, $isSharedFrom, $fromCache);
    }

    /**
     * Templates the add-in's "Choose signature" pane may offer. Names only —
     * rendering (and the Graph round-trip it needs) happens per selection via
     * resolve() with templateId set.
     *
     * Returns null on auth failure so the controller can emit the same 401 as
     * /api/sig and feed the per-IP failure bucket.
     *
     * @return list<array{id:int,name:string}>|null
     */
    public function listSelectableTemplates(string $tenantSlug, string $apiKey): ?array
    {
        $tenant = $this->tenants->findBySlug($tenantSlug);
        if ($tenant === null || !$this->tenantService->verifyApiKey($tenant, $apiKey)) {
            return null;
        }
        return array_map(
            static fn($t) => ['id' => $t->id, 'name' => $t->name],
            $this->templates->listManuallySelectable($tenant->id),
        );
    }
}
