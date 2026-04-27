<?php
declare(strict_types=1);

namespace App\Portal;

use App\Audit\AuditLogger;
use App\Auth\SessionManager;
use App\Tenant\TemplateRepository;
use App\Tenant\TenantRepository;
use App\Tenant\UserOverrideRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Views\Twig;

/**
 * Per-user signature overrides — sit above the rule engine. Listed,
 * created, edited and deleted under a tenant's "Overrides" sub-tab.
 */
final class UserOverrideController
{
    public function __construct(
        private readonly Twig $view,
        private readonly TenantRepository $tenants,
        private readonly TemplateRepository $templates,
        private readonly UserOverrideRepository $overrides,
        private readonly SessionManager $session,
        private readonly AuditLogger $audit,
    ) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [$tenant, $err] = $this->resolveTenant($request, $args);
        if ($err) return $err;

        $items     = $this->overrides->listForTenant($tenant->id);
        $templates = $this->templates->listForTenant($tenant->id);

        return $this->view->render($response, 'portal/overrides/index.twig', [
            'tenant'        => $tenant,
            'overrides'     => $items,
            'templates_map' => $this->byId($templates),
        ]);
    }

    public function newForm(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [$tenant, $err] = $this->resolveTenant($request, $args);
        if ($err) return $err;

        return $this->view->render($response, 'portal/overrides/form.twig', [
            'tenant'    => $tenant,
            'override'  => null,
            'templates' => $this->templates->listForTenant($tenant->id),
            'form'      => ['email' => '', 'template_id' => '', 'note' => ''],
            'errors'    => [],
        ]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [$tenant, $err] = $this->resolveTenant($request, $args);
        if ($err) return $err;

        $form   = $this->normalizeForm((array) $request->getParsedBody());
        $errors = $this->validate($form, $tenant->id, null);

        if ($errors !== []) {
            return $this->view->render($response, 'portal/overrides/form.twig', [
                'tenant'    => $tenant,
                'override'  => null,
                'templates' => $this->templates->listForTenant($tenant->id),
                'form'      => $form,
                'errors'    => $errors,
            ]);
        }

        $newId = $this->overrides->create(
            $tenant->id,
            $form['email'],
            (int) $form['template_id'],
            $form['note'] !== '' ? $form['note'] : null,
        );
        $this->audit->record($tenant->id, 'override.created', 'user_override', $newId, "Override added for {$form['email']}");
        $this->session->flash('success', 'Override added.');
        return $response->withHeader('Location', "/portal/tenants/{$tenant->id}/overrides")->withStatus(302);
    }

    public function editForm(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [$tenant, $err] = $this->resolveTenant($request, $args);
        if ($err) return $err;
        $override = $this->overrides->find((int) $args['oid'], $tenant->id);
        if ($override === null) return $this->notFound($response);

        return $this->view->render($response, 'portal/overrides/form.twig', [
            'tenant'    => $tenant,
            'override'  => $override,
            'templates' => $this->templates->listForTenant($tenant->id),
            'form'      => [
                'email'       => $override->email,
                'template_id' => (string) $override->templateId,
                'note'        => $override->note ?? '',
            ],
            'errors'    => [],
        ]);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [$tenant, $err] = $this->resolveTenant($request, $args);
        if ($err) return $err;
        $override = $this->overrides->find((int) $args['oid'], $tenant->id);
        if ($override === null) return $this->notFound($response);

        $form   = $this->normalizeForm((array) $request->getParsedBody());
        $errors = $this->validate($form, $tenant->id, $override->id);

        if ($errors !== []) {
            return $this->view->render($response, 'portal/overrides/form.twig', [
                'tenant'    => $tenant,
                'override'  => $override,
                'templates' => $this->templates->listForTenant($tenant->id),
                'form'      => $form,
                'errors'    => $errors,
            ]);
        }

        $this->overrides->update(
            $override->id,
            $tenant->id,
            $form['email'],
            (int) $form['template_id'],
            $form['note'] !== '' ? $form['note'] : null,
        );
        $this->audit->record($tenant->id, 'override.updated', 'user_override', $override->id, "Override saved for {$form['email']}");
        $this->session->flash('success', 'Override saved.');
        return $response->withHeader('Location', "/portal/tenants/{$tenant->id}/overrides")->withStatus(302);
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [$tenant, $err] = $this->resolveTenant($request, $args);
        if ($err) return $err;
        $this->overrides->delete((int) $args['oid'], $tenant->id);
        $this->audit->record($tenant->id, 'override.deleted', 'user_override', (int) $args['oid'], 'Override deleted');
        $this->session->flash('success', 'Override deleted.');
        return $response->withHeader('Location', "/portal/tenants/{$tenant->id}/overrides")->withStatus(302);
    }

    // ---------- helpers ----------

    /** @param array<string,mixed> $body */
    private function normalizeForm(array $body): array
    {
        return [
            'email'       => mb_strtolower(trim((string) ($body['email'] ?? ''))),
            'template_id' => (string) ($body['template_id'] ?? ''),
            'note'        => trim((string) ($body['note'] ?? '')),
        ];
    }

    /** @param array<string,string> $form */
    private function validate(array $form, int $tenantId, ?int $editingId): array
    {
        $errors = [];
        if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid email address is required.';
        } else {
            $existing = $this->overrides->findByEmail($tenantId, $form['email']);
            if ($existing !== null && $existing->id !== $editingId) {
                $errors[] = 'An override for this email already exists. Edit that one instead.';
            }
        }
        if (!ctype_digit((string) $form['template_id']) || (int) $form['template_id'] <= 0) {
            $errors[] = 'Template is required.';
        } elseif ($this->templates->find((int) $form['template_id'], $tenantId) === null) {
            $errors[] = 'Selected template does not exist for this tenant.';
        }
        if (mb_strlen($form['note']) > 255) {
            $errors[] = 'Note is too long (max 255 chars).';
        }
        return $errors;
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

    private function notFound(ResponseInterface $r): ResponseInterface
    {
        $r = $r->withStatus(404);
        $r->getBody()->write('Not found.');
        return $r;
    }

    /** @param list<\App\Tenant\Template> $templates */
    private function byId(array $templates): array
    {
        $map = [];
        foreach ($templates as $t) {
            $map[$t->id] = $t;
        }
        return $map;
    }
}
