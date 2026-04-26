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
    ) {}

    public function resolve(SignatureRequest $req): SignatureResult
    {
        $tenant = $this->tenants->findBySlug($req->tenantSlug);
        if ($tenant === null) {
            return SignatureResult::error(401, 'unknown tenant');
        }
        if (!$this->tenantService->verifyApiKey($tenant, $req->apiKey)) {
            return SignatureResult::error(401, 'invalid api key');
        }

        // Resolve the user's Graph profile. If the FROM address has no
        // profile (typical for shared mailboxes), fall back to the
        // primary user.
        try {
            $profile      = $this->graph->getUserProfile($tenant, $req->fromEmail);
            $isSharedFrom = false;

            if ($profile === null) {
                if ($req->primaryEmail === '' || $req->primaryEmail === $req->fromEmail) {
                    return SignatureResult::error(404, 'unknown sender and no primary user supplied');
                }
                $profile = $this->graph->getUserProfile($tenant, $req->primaryEmail);
                if ($profile === null) {
                    return SignatureResult::error(404, 'primary user not found in Graph');
                }
                $isSharedFrom = true;
            }
        } catch (GraphException $e) {
            return SignatureResult::error(502, 'Graph error: ' . $e->getMessage());
        }

        $emailToken = $isSharedFrom
            ? $this->displayEmail->deriveForSharedMailbox($profile, $this->domainOf($req->fromEmail))
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

        $rule = $this->engine->selectRule($tenant->id, $ctx);
        if ($rule === null) {
            return SignatureResult::noMatch($isSharedFrom);
        }

        $template = $this->templates->find($rule->templateId, $tenant->id);
        if ($template === null) {
            return SignatureResult::error(500, 'rule references missing template');
        }

        $tokens   = TemplateRenderer::tokensFromProfile($profile, $emailToken);
        $rendered = $this->renderer->render($template->html, $tokens);

        return SignatureResult::ok($rendered, $isSharedFrom);
    }

    private function domainOf(string $email): string
    {
        $at = strrchr($email, '@');
        return $at === false ? '' : mb_strtolower(substr($at, 1));
    }
}
