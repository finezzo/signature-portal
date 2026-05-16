<?php
declare(strict_types=1);

namespace App\Addin;

use App\Graph\GraphClient;
use App\Graph\GraphException;
use App\Graph\UserProfile;
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
 *   → Graph profile resolution (with shared-mailbox primary-user fallback)
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
        private readonly GraphClient $graph,
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
        try {
            $fromProfile  = $this->graph->getUserProfile($tenant, $req->fromEmail);
            $isSharedFrom = $fromProfile === null || $fromProfile->looksLikeSharedMailbox();

            if (!$isSharedFrom) {
                $profile = $fromProfile;
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
                $profile = $this->graph->getUserProfile($tenant, $req->primaryEmail);
                if ($profile === null) {
                    return SignatureResult::error(404, 'primary user not found in Graph');
                }
            }
        } catch (GraphException $e) {
            // Don't reflect the Graph message back to the caller — it
            // contains the email address that was queried, which would let
            // an API-key holder enumerate users by triggering Graph errors.
            // Server-side log keeps the diagnostic for ops.
            error_log('[/api/sig] Graph lookup failed for tenant '
                . $tenant->slug . ': ' . $e->getMessage());
            return SignatureResult::error(502, 'graph lookup failed');
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
        $override      = $this->overrides->findByEmail($tenant->id, $overrideEmail);

        if ($override !== null) {
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

        return SignatureResult::ok($rendered, $isSharedFrom);
    }
}
