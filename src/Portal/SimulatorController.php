<?php
declare(strict_types=1);

namespace App\Portal;

use App\Graph\GraphClient;
use App\Graph\GraphException;
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
        private readonly GraphClient $graph,
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

        // If "Use live Graph data" is set, fetch the actual user profile from
        // Graph and derive language/mailbox-type/email-token from it just
        // like SignatureService would. Falls back to whatever the form
        // overrode, like the live pipeline does.
        $tokens         = TemplateRenderer::sampleTokens();
        $tokenSource    = 'sample';
        $liveEmailToken = null;
        $isSharedFrom   = false;
        $graphError     = null;
        $resolvedLang   = $form['language'] !== '' ? $form['language'] : null;
        $resolvedMtype  = $form['mailbox_type'] !== '' ? $form['mailbox_type'] : null;

        if ($form['use_live_data'] && $form['from_email'] !== '') {
            try {
                $fromProfile  = $this->graph->getUserProfile($tenant, $form['from_email']);
                $isSharedFrom = $fromProfile === null || $fromProfile->looksLikeSharedMailbox();

                if (!$isSharedFrom) {
                    $profile = $fromProfile;
                } else {
                    $primary = $form['primary_email'] !== '' ? $form['primary_email'] : $form['from_email'];
                    $profile = $primary !== $form['from_email']
                        ? $this->graph->getUserProfile($tenant, $primary)
                        : null;
                    if ($profile === null) {
                        // Couldn't fully resolve — use whatever shape we have
                        // so the simulator still shows something useful.
                        $profile = $fromProfile;
                    }
                }

                if ($profile !== null) {
                    $fromDomain = mb_strtolower((string) substr(strrchr($form['from_email'], '@') ?: '@', 1));
                    $liveEmailToken = $isSharedFrom
                        ? $this->displayEmail->deriveForSharedMailbox(
                              $profile,
                              $form['from_email'],
                              $tenant->displayModeForDomain($fromDomain),
                          )
                        : ($profile->mail ?? $profile->userPrincipalName);
                    $tokens      = TemplateRenderer::tokensFromProfile($profile, $liveEmailToken);
                    $tokenSource = 'graph';
                    if ($form['language'] === '') {
                        $resolvedLang = $profile->shortLanguage();
                    }
                    if ($form['mailbox_type'] === '') {
                        $resolvedMtype = $isSharedFrom ? 'shared' : 'personal';
                    }
                }
            } catch (GraphException $e) {
                $graphError  = $e->getMessage();
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
                'context'       => $ctx,
                'recipients'    => $recipients,
                'rule'          => $rule,
                'override'      => $override,
                'template'      => $template,
                'rendered_html' => $rendered,
                'reasons'       => $explain['reasons'],
                'token_source'  => $tokenSource,
                'is_shared'     => $isSharedFrom,
                'live_email'    => $liveEmailToken,
                'graph_error'   => $graphError,
            ],
        ]);
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
