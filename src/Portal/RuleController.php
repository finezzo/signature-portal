<?php
declare(strict_types=1);

namespace App\Portal;

use App\Audit\AuditLogger;
use App\Auth\SessionManager;
use App\Tenant\Rule;
use App\Tenant\RuleRepository;
use App\Tenant\TemplateRepository;
use App\Tenant\TenantRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Views\Twig;

final class RuleController
{
    public function __construct(
        private readonly Twig $view,
        private readonly TenantRepository $tenants,
        private readonly TemplateRepository $templates,
        private readonly RuleRepository $rules,
        private readonly SessionManager $session,
        private readonly AuditLogger $audit,
    ) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [$tenant, $err] = $this->resolveTenant($request, $args);
        if ($err) return $err;

        $rules     = $this->rules->listForTenant($tenant->id);
        $templates = $this->templates->listForTenant($tenant->id);

        return $this->view->render($response, 'portal/rules/index.twig', [
            'tenant'        => $tenant,
            'rules'         => $rules,
            'templates_map' => self::byId($templates),
        ]);
    }

    public function newForm(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [$tenant, $err] = $this->resolveTenant($request, $args);
        if ($err) return $err;

        return $this->view->render($response, 'portal/rules/form.twig', [
            'tenant'    => $tenant,
            'rule'      => null,
            'templates' => $this->templates->listForTenant($tenant->id),
            'form'      => $this->defaultForm($tenant),
            'errors'    => [],
        ]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [$tenant, $err] = $this->resolveTenant($request, $args);
        if ($err) return $err;

        $form   = $this->normalizeForm((array) $request->getParsedBody());
        $errors = $this->validate($form, $tenant->id);

        if ($errors !== []) {
            return $this->view->render($response, 'portal/rules/form.twig', [
                'tenant' => $tenant, 'rule' => null,
                'templates' => $this->templates->listForTenant($tenant->id),
                'form' => $form, 'errors' => $errors,
            ]);
        }

        $newId = $this->rules->create(
            $tenant->id,
            (int) $form['template_id'],
            $form['from_domain'],
            $form['language']     ?: null,
            $form['mailbox_type'] ?: null,
            $form['recipient_scope'],
            (int) $form['priority'],
            (bool) ($form['is_fallback'] ?? false),
            (bool) ($form['is_enabled'] ?? true),
            $form['valid_from']  !== '' ? $form['valid_from']  : null,
            $form['valid_until'] !== '' ? $form['valid_until'] : null,
        );

        $this->audit->record($tenant->id, 'rule.created', 'rule', $newId, "Rule for {$form['from_domain']} created");
        $this->session->flash('success', 'Rule created.');
        return $response->withHeader('Location', "/portal/tenants/{$tenant->id}/rules")->withStatus(302);
    }

    public function editForm(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [$tenant, $err] = $this->resolveTenant($request, $args);
        if ($err) return $err;
        $rule = $this->rules->find((int) $args['rid'], $tenant->id);
        if ($rule === null) return $this->notFound($response);

        return $this->view->render($response, 'portal/rules/form.twig', [
            'tenant'    => $tenant,
            'rule'      => $rule,
            'templates' => $this->templates->listForTenant($tenant->id),
            'form'      => [
                'template_id'     => (string) $rule->templateId,
                'from_domain'     => $rule->fromDomain,
                'language'        => $rule->language ?? '',
                'mailbox_type'    => $rule->mailboxType ?? '',
                'recipient_scope' => $rule->recipientScope,
                'priority'        => (string) $rule->priority,
                'is_fallback'     => $rule->isFallback,
                'is_enabled'      => $rule->isEnabled,
                'valid_from'      => $rule->validFrom  ?? '',
                'valid_until'     => $rule->validUntil ?? '',
            ],
            'errors'    => [],
        ]);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [$tenant, $err] = $this->resolveTenant($request, $args);
        if ($err) return $err;
        $rule = $this->rules->find((int) $args['rid'], $tenant->id);
        if ($rule === null) return $this->notFound($response);

        $form   = $this->normalizeForm((array) $request->getParsedBody());
        $errors = $this->validate($form, $tenant->id);
        if ($errors !== []) {
            return $this->view->render($response, 'portal/rules/form.twig', [
                'tenant' => $tenant, 'rule' => $rule,
                'templates' => $this->templates->listForTenant($tenant->id),
                'form' => $form, 'errors' => $errors,
            ]);
        }

        $this->rules->update(
            $rule->id,
            $tenant->id,
            (int) $form['template_id'],
            $form['from_domain'],
            $form['language']     ?: null,
            $form['mailbox_type'] ?: null,
            $form['recipient_scope'],
            (int) $form['priority'],
            (bool) ($form['is_fallback'] ?? false),
            (bool) ($form['is_enabled'] ?? true),
            $form['valid_from']  !== '' ? $form['valid_from']  : null,
            $form['valid_until'] !== '' ? $form['valid_until'] : null,
        );

        $this->audit->record($tenant->id, 'rule.updated', 'rule', $rule->id, "Rule for {$form['from_domain']} saved");
        $this->session->flash('success', 'Rule saved.');
        return $response->withHeader('Location', "/portal/tenants/{$tenant->id}/rules")->withStatus(302);
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [$tenant, $err] = $this->resolveTenant($request, $args);
        if ($err) return $err;
        $this->rules->delete((int) $args['rid'], $tenant->id);
        $this->audit->record($tenant->id, 'rule.deleted', 'rule', (int) $args['rid'], 'Rule deleted');
        $this->session->flash('success', 'Rule deleted.');
        return $response->withHeader('Location', "/portal/tenants/{$tenant->id}/rules")->withStatus(302);
    }

    // ---------- helpers ----------

    /** @return array{0:\App\Tenant\Tenant|null, 1:?ResponseInterface} */
    private function resolveTenant(ServerRequestInterface $request, array $args): array
    {
        $id     = (int) ($args['id'] ?? 0);
        $tenant = $id > 0 ? $this->tenants->find($id) : null;
        if ($tenant === null) {
            return [null, $this->notFound((new ResponseFactory())->createResponse())];
        }
        $user = (array) $request->getAttribute('current_user');
        if (!AccessControl::canAccessTenant($user, $tenant->id)) {
            $r = (new ResponseFactory())->createResponse(403);
            $r->getBody()->write('Forbidden.');
            return [null, $r];
        }
        return [$tenant, null];
    }

    private function notFound(ResponseInterface $r): ResponseInterface
    {
        $r = $r->withStatus(404);
        $r->getBody()->write('Not found.');
        return $r;
    }

    /** @param array<string,mixed> $body */
    private function normalizeForm(array $body): array
    {
        return [
            'template_id'     => (string) ($body['template_id'] ?? ''),
            'from_domain'     => mb_strtolower(trim(ltrim((string) ($body['from_domain'] ?? ''), '@'))),
            'language'        => trim((string) ($body['language'] ?? '')),
            'mailbox_type'    => (string) ($body['mailbox_type'] ?? ''),
            'recipient_scope' => (string) ($body['recipient_scope'] ?? Rule::SCOPE_ALL),
            'priority'        => (string) ($body['priority'] ?? '100'),
            'is_fallback'     => isset($body['is_fallback']),
            'is_enabled'      => isset($body['is_enabled']),
            'valid_from'      => trim((string) ($body['valid_from']  ?? '')),
            'valid_until'     => trim((string) ($body['valid_until'] ?? '')),
        ];
    }

    public function toggle(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [$tenant, $err] = $this->resolveTenant($request, $args);
        if ($err) return $err;
        $rule = $this->rules->find((int) $args['rid'], $tenant->id);
        if ($rule === null) return $this->notFound($response);

        $this->rules->setEnabled($rule->id, $tenant->id, !$rule->isEnabled);
        $this->audit->record(
            $tenant->id,
            $rule->isEnabled ? 'rule.disabled' : 'rule.enabled',
            'rule',
            $rule->id,
            $rule->isEnabled ? 'Rule disabled' : 'Rule enabled',
        );
        $this->session->flash('success', $rule->isEnabled ? 'Rule disabled.' : 'Rule enabled.');
        return $response->withHeader('Location', "/portal/tenants/{$tenant->id}/rules")->withStatus(302);
    }

    /** @param array<string,mixed> $form */
    private function validate(array $form, int $tenantId): array
    {
        $errors = [];
        if (!ctype_digit((string) $form['template_id']) || (int) $form['template_id'] <= 0) {
            $errors[] = 'Template is required.';
        } elseif ($this->templates->find((int) $form['template_id'], $tenantId) === null) {
            $errors[] = 'Selected template does not exist for this tenant.';
        }
        if ($form['from_domain'] === '' || !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $form['from_domain'])) {
            $errors[] = 'From-domain must look like a domain (e.g. example.com).';
        }
        if (!in_array($form['recipient_scope'], [Rule::SCOPE_ALL, Rule::SCOPE_EXTERNAL, Rule::SCOPE_INTERNAL], true)) {
            $errors[] = 'Invalid recipient scope.';
        }
        if ($form['mailbox_type'] !== '' && !in_array($form['mailbox_type'], ['personal', 'shared'], true)) {
            $errors[] = 'Mailbox type must be personal, shared, or any.';
        }
        if (!ctype_digit((string) $form['priority'])) {
            $errors[] = 'Priority must be a non-negative integer.';
        }
        foreach (['valid_from', 'valid_until'] as $f) {
            if ($form[$f] !== '' && \DateTimeImmutable::createFromFormat('Y-m-d', $form[$f]) === false) {
                $errors[] = ucfirst(str_replace('_', ' ', $f)) . ' must be in YYYY-MM-DD format or empty.';
            }
        }
        if ($form['valid_from'] !== '' && $form['valid_until'] !== ''
            && $form['valid_from'] > $form['valid_until']) {
            $errors[] = 'Valid-from must be on or before valid-until.';
        }
        return $errors;
    }

    private function defaultForm(\App\Tenant\Tenant $tenant): array
    {
        return [
            'template_id'     => '',
            'from_domain'     => $tenant->emailDomains[0] ?? '',
            'language'        => '',
            'mailbox_type'    => '',
            'recipient_scope' => Rule::SCOPE_ALL,
            'priority'        => '100',
            'is_fallback'     => false,
            'is_enabled'      => true,
            'valid_from'      => '',
            'valid_until'     => '',
        ];
    }

    /** @param list<\App\Tenant\Template> $templates */
    private static function byId(array $templates): array
    {
        $map = [];
        foreach ($templates as $t) {
            $map[$t->id] = $t;
        }
        return $map;
    }
}
