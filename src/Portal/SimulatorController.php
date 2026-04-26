<?php
declare(strict_types=1);

namespace App\Portal;

use App\Tenant\RuleContext;
use App\Tenant\RuleEngine;
use App\Tenant\TemplateRenderer;
use App\Tenant\TemplateRepository;
use App\Tenant\TenantRepository;
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
            'from_email'   => trim((string) ($body['from_email'] ?? '')),
            'recipients'   => (string) ($body['recipients'] ?? ''),
            'language'     => trim((string) ($body['language'] ?? '')),
            'mailbox_type' => (string) ($body['mailbox_type'] ?? ''),
        ];

        $recipients = array_values(array_filter(array_map(
            'trim',
            preg_split('/[\s,;]+/', $form['recipients']) ?: []
        )));

        $ctx = new RuleContext(
            $form['from_email'],
            $form['language'] !== '' ? $form['language'] : null,
            $form['mailbox_type'] !== '' ? $form['mailbox_type'] : null,
            $recipients,
            $tenant->emailDomains,
        );

        $explain  = $this->engine->explain($tenant->id, $ctx);
        $rule     = $explain['rule'];
        $template = $rule !== null ? $this->templates->find($rule->templateId, $tenant->id) : null;

        $rendered = null;
        if ($template !== null) {
            $rendered = $this->renderer->render($template->html, TemplateRenderer::sampleTokens());
        }

        return $this->view->render($response, 'portal/simulator.twig', [
            'tenant' => $tenant,
            'form'   => $form,
            'result' => [
                'context'       => $ctx,
                'recipients'    => $recipients,
                'rule'          => $rule,
                'template'      => $template,
                'rendered_html' => $rendered,
                'reasons'       => $explain['reasons'],
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
            'from_email'   => "anna.beispiel@{$primary}",
            'recipients'   => "kunde@kunden-firma.de",
            'language'     => 'de',
            'mailbox_type' => 'personal',
        ];
    }
}
