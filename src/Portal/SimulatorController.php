<?php
declare(strict_types=1);

namespace App\Portal;

use App\Graph\GraphException;
use App\Graph\ProfileResolver;
use App\Graph\UserProfile;
use App\Tenant\DisplayEmailDeriver;
use App\Tenant\RuleContext;
use App\Tenant\RuleEngine;
use App\Tenant\TemplateRenderer;
use App\Tenant\TemplateRepository;
use App\Tenant\TenantRepository;
use App\Tenant\UserOverrideRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Views\Twig;

/**
 * "What-if" simulator. Admin enters a hypothetical send (from, recipients,
 * language, mailbox type) and sees:
 *  - which rule the engine picks (or none)
 *  - per-rule reasons (why each rule did/didn't match)
 *  - the rendered template HTML with sample tokens
 */
final class SimulatorController
{
    public function __construct(
        private readonly Twig $view,
        private readonly TenantRepository $tenants,
        private readonly TemplateRepository $templates,
        private readonly RuleEngine $engine,
        private readonly TemplateRenderer $renderer,
        private readonly ProfileResolver $resolver,
        private readonly DisplayEmailDeriver $displayEmail,
        private readonly UserOverrideRepository $overrides,
    ) {}

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [$tenant, $err] = $this->resolveTenant($request, $args);
        if ($err) return $err;

        return $this->view->render($response, 'portal/simulator.twig', [
            'tenant' => $tenant,
            'form'   => $this->defaultForm($tenant),
            'result' => null,
        ]);
    }

    public function run(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [$tenant, $err] = $this->resolveTenant($request, $args);
        if ($err) return $err;

        $body = (array) $request->getParsedBody();
        $form = [
            'from_email'    => trim((string) ($body['from_email'] ?? '')),
            'primary_email' => trim((string) ($body['primary_email'] ?? '')),
            'recipients'    => (string) ($body['recipients'] ?? ''),
            'language'      => trim((string) ($body['language'] ?? '')),
            'mailbox_type'  => (string) ($body['mailbox_type'] ?? ''),
            'use_live_data' => !empty($body['use_live_data']),
        ];

        $recipients = array_values(array_filter(array_map(
            'trim',
            preg_split('/[\s,;]+/', $form['recipients']) ?: []
        )));

        // If "Use live Graph data" is set, resolve the actual user profile and
        // derive language/mailbox-type/email-token from it just like
        // SignatureService would. Falls back to whatever the form overrode,
        // like the live pipeline does. The resolver reads the local profile
        // store when Graph is unreachable, so the simulator shows the same
        // stale-but-working data the add-in would receive.
        $tokens         = TemplateRenderer::sampleTokens();
        $tokenSource    = 'sample';
        $liveEmailToken = null;
        $isSharedFrom   = false;
        $graphError     = null;
        $cacheStoredAt  = null;
        $resolvedLang   = $form['language'] !== '' ? $form['language'] : null;
        $resolvedMtype  = $form['mailbox_type'] !== '' ? $form['mailbox_type'] : null;

        if ($form['use_live_data'] && $form['from_email'] !== '') {
            $fromLookupSucceeded = false;
            try {
                $fromResolved = $this->resolver->resolve($tenant, $form['from_email']);
                $fromLookupSucceeded = true;
                $fromProfile  = $fromResolved?->profile;
                $isSharedFrom = $fromProfile === null || $fromProfile->looksLikeSharedMailbox();

                if (!$isSharedFrom) {
                    $used = $fromResolved;
                } else {
                    $primary = $form['primary_email'] !== '' ? $form['primary_email'] : $form['from_email'];
                    $used = $primary !== $form['from_email']
                        ? $this->resolver->resolve($tenant, $primary)
                        : null;
                    if ($used === null) {
                        // Couldn't fully resolve — use whatever shape we have
                        // so the simulator still shows something useful.
                        $used = $fromResolved;
                    }
                }

                $profile = $used?->profile;
                if ($profile !== null && $used !== null) {
                    $derived        = $this->deriveFromProfile($tenant, $profile, $form['from_email'], $isSharedFrom);
                    $liveEmailToken = $derived['email'];
                    $tokens         = $derived['tokens'];
                    // Provenance follows whichever lookup supplied the profile
                    // that was actually rendered.
                    $tokenSource   = $used->fromCache ? 'cache' : 'graph';
                    $cacheStoredAt = $used->fromCache ? $used->storedAt : null;
                    if ($form['language'] === '') {
                        $resolvedLang = $derived['language'];
                    }
                    if ($form['mailbox_type'] === '') {
                        $resolvedMtype = $derived['mailbox_type'];
                    }
                }
            } catch (GraphException $e) {
                // Graph is down and the address it choked on had nothing stored.
                // The primary user's stored row is the last chance before
                // dropping back to sample tokens.
                $graphError = $e->getMessage();

                $primary = $form['primary_email'] !== '' && $form['primary_email'] !== $form['from_email']
                    ? $form['primary_email']
                    : $form['from_email'];
                $cached = $this->resolver->cached($tenant, $primary);

                if ($cached !== null) {
                    // If the FROM lookup is what failed, the rendered profile
                    // belongs to the primary user and not to the FROM mailbox —
                    // the same shape as a shared-mailbox send. A shared-mailbox
                    // verdict the FROM lookup already reached before the
                    // primary lookup threw is kept.
                    $isSharedFrom   = $isSharedFrom || !$fromLookupSucceeded;
                    $derived        = $this->deriveFromProfile($tenant, $cached->profile, $form['from_email'], $isSharedFrom);
                    $liveEmailToken = $derived['email'];
                    $tokens         = $derived['tokens'];
                    $tokenSource    = 'cache';
                    $cacheStoredAt  = $cached->storedAt;
                    if ($form['language'] === '') {
                        $resolvedLang = $derived['language'];
                    }
                    if ($form['mailbox_type'] === '') {
                        $resolvedMtype = $derived['mailbox_type'];
                    }
                }
            }
        }

        $ctx = new RuleContext(
            $form['from_email'],
            $resolvedLang,
            $resolvedMtype,
            $recipients,
            $tenant->emailDomains,
        );

        // Per-user override check first (mirrors SignatureService).
        $overrideKey = $form['primary_email'] !== '' ? $form['primary_email'] : $form['from_email'];
        $override    = $overrideKey !== ''
            ? $this->overrides->findByEmail($tenant->id, $overrideKey)
            : null;

        $explain  = $this->engine->explain($tenant->id, $ctx);
        $rule     = null;
        $template = null;

        if ($override !== null) {
            $template = $this->templates->find($override->templateId, $tenant->id);
        } else {
            $rule     = $explain['rule'];
            $template = $rule !== null ? $this->templates->find($rule->templateId, $tenant->id) : null;
        }

        $rendered = null;
        if ($template !== null) {
            $rendered = $this->renderer->render($template->html, $tokens);
            if ($tenant->disclaimerHtml !== null && trim($tenant->disclaimerHtml) !== '') {
                $rendered .= $this->renderer->render($tenant->disclaimerHtml, $tokens);
            }
        }

        return $this->view->render($response, 'portal/simulator.twig', [
            'tenant' => $tenant,
            'form'   => $form,
            'result' => [
                'context'         => $ctx,
                'recipients'      => $recipients,
                'rule'            => $rule,
                'override'        => $override,
                'template'        => $template,
                'rendered_html'   => $rendered,
                'reasons'         => $explain['reasons'],
                'token_source'    => $tokenSource,
                'is_shared'       => $isSharedFrom,
                'live_email'      => $liveEmailToken,
                'graph_error'     => $graphError,
                'cache_stored_at' => $cacheStoredAt,
            ],
        ]);
    }

    /**
     * Everything the result view needs from one resolved profile — shared by
     * the normal path and the cache-fallback path so both derive the display
     * email, language and mailbox type identically.
     *
     * @return array{tokens: array<string,string>, email: string, language: ?string, mailbox_type: string}
     */
    private function deriveFromProfile(
        \App\Tenant\Tenant $tenant,
        UserProfile $profile,
        string $fromEmail,
        bool $isShared,
    ): array {
        $fromDomain = mb_strtolower((string) substr(strrchr($fromEmail, '@') ?: '@', 1));
        $email      = $isShared
            ? $this->displayEmail->deriveForSharedMailbox(
                  $profile,
                  $fromEmail,
                  $tenant->displayModeForDomain($fromDomain),
              )
            : ($profile->mail ?? $profile->userPrincipalName);

        return [
            'tokens'       => TemplateRenderer::tokensFromProfile($profile, $email),
            'email'        => $email,
            'language'     => $profile->shortLanguage(),
            'mailbox_type' => $isShared ? 'shared' : 'personal',
        ];
    }

    /** @return array{0:\App\Tenant\Tenant|null, 1:?ResponseInterface} */
    private function resolveTenant(ServerRequestInterface $request, array $args): array
    {
        $id     = (int) ($args['id'] ?? 0);
        $tenant = $id > 0 ? $this->tenants->find($id) : null;
        if ($tenant === null) {
            $r = (new ResponseFactory())->createResponse(404);
            $r->getBody()->write('Tenant not found.');
            return [null, $r];
        }
        $user = (array) $request->getAttribute('current_user');
        if (!AccessControl::canAccessTenant($user, $tenant->id)) {
            $r = (new ResponseFactory())->createResponse(403);
            $r->getBody()->write('Forbidden.');
            return [null, $r];
        }
        return [$tenant, null];
    }

    private function defaultForm(\App\Tenant\Tenant $tenant): array
    {
        $primary = $tenant->emailDomains[0] ?? 'example.com';
        return [
            'from_email'    => "anna.beispiel@{$primary}",
            'primary_email' => '',
            'recipients'    => "kunde@kunden-firma.de",
            'language'      => 'de',
            'mailbox_type'  => 'personal',
            'use_live_data' => false,
        ];
    }
}
