<?php
declare(strict_types=1);

namespace App\Portal;

use App\Audit\AuditLogger;
use App\Auth\SessionManager;
use App\Config\Config;
use App\Tenant\AssetRepository;
use App\Tenant\SnippetLibrary;
use App\Tenant\StarterTemplates;
use App\Tenant\Template;
use App\Tenant\TemplateRenderer;
use App\Tenant\TemplateRepository;
use App\Tenant\TenantRepository;
use App\Tenant\TokenCatalog;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Views\Twig;

final class TemplateController
{
    public function __construct(
        private readonly Twig $view,
        private readonly TenantRepository $tenants,
        private readonly TemplateRepository $templates,
        private readonly TemplateRenderer $renderer,
        private readonly SessionManager $session,
        private readonly AuditLogger $audit,
        private readonly AssetRepository $assets,
        private readonly Config $config,
    ) {}

    /** @return list<array{filename:string,size:int,mtime:int,url:string,content_type:string}> */
    private function tenantAssets(\App\Tenant\Tenant $tenant): array
    {
        return $this->assets->list($tenant->slug, (string) $this->config->get('base_url', ''));
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [$tenant, $err] = $this->resolveTenant($request, $args);
        if ($err) return $err;

        return $this->view->render($response, 'portal/templates/index.twig', [
            'tenant'    => $tenant,
            'templates' => $this->templates->listForTenant($tenant->id),
        ]);
    }

    public function newForm(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [$tenant, $err] = $this->resolveTenant($request, $args);
        if ($err) return $err;

        // ?starter=<id> pre-fills the editor with one of the bundled templates.
        $starterId = (string) ($request->getQueryParams()['starter'] ?? '');
        $picked    = $starterId !== '' ? StarterTemplates::find($starterId) : null;

        $defaultHtml = $picked['html'] ?? StarterTemplates::all()[1]['html']; // two-column-divider as default
        $defaultName = $picked['name'] ?? '';

        return $this->view->render($response, 'portal/templates/form.twig', [
            'tenant'           => $tenant,
            'template'         => null,
            'form'             => ['name' => $defaultName, 'html' => $defaultHtml],
            'errors'           => [],
            'starters'         => StarterTemplates::all(),
            'selected_starter' => $picked['id'] ?? null,
            'token_groups'     => TokenCatalog::all(),
            'tenant_assets'    => $this->tenantAssets($tenant),
            'snippets'         => SnippetLibrary::all(),
            'upload_url'       => "/portal/tenants/{$tenant->id}/assets/upload-json",
        ]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [$tenant, $err] = $this->resolveTenant($request, $args);
        if ($err) return $err;

        $body = (array) $request->getParsedBody();
        $form = [
            'name' => trim((string) ($body['name'] ?? '')),
            'html' => (string) ($body['html'] ?? ''),
        ];
        $errors = $this->validate($form);
        if ($errors !== []) {
            return $this->view->render($response, 'portal/templates/form.twig', [
                'tenant' => $tenant, 'template' => null, 'form' => $form, 'errors' => $errors,
                'starters' => StarterTemplates::all(), 'selected_starter' => null,
                'token_groups' => TokenCatalog::all(),
                'tenant_assets' => $this->tenantAssets($tenant),
                'snippets'      => SnippetLibrary::all(),
                'upload_url'    => "/portal/tenants/{$tenant->id}/assets/upload-json",
            ]);
        }

        $sanitized = $this->renderer->sanitize($form['html']);
        $id = $this->templates->create($tenant->id, $form['name'], $sanitized);

        $this->audit->record($tenant->id, 'template.created', 'template', $id, "Template '{$form['name']}' created");
        $this->session->flash('success', 'Template created.');
        return $response->withHeader('Location', "/portal/tenants/{$tenant->id}/templates/{$id}/edit")->withStatus(302);
    }

    public function editForm(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [$tenant, $err] = $this->resolveTenant($request, $args);
        if ($err) return $err;
        $template = $this->templates->find((int) $args['tid'], $tenant->id);
        if ($template === null) return $this->notFound($response);

        return $this->view->render($response, 'portal/templates/form.twig', [
            'tenant'   => $tenant,
            'template' => $template,
            'form'     => ['name' => $template->name, 'html' => $template->html],
            'errors'   => [],
            'token_groups'  => TokenCatalog::all(),
            'tenant_assets' => $this->tenantAssets($tenant),
                'snippets'      => SnippetLibrary::all(),
                'upload_url'    => "/portal/tenants/{$tenant->id}/assets/upload-json",
        ]);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [$tenant, $err] = $this->resolveTenant($request, $args);
        if ($err) return $err;
        $template = $this->templates->find((int) $args['tid'], $tenant->id);
        if ($template === null) return $this->notFound($response);

        $body = (array) $request->getParsedBody();
        $form = [
            'name' => trim((string) ($body['name'] ?? '')),
            'html' => (string) ($body['html'] ?? ''),
        ];
        $errors = $this->validate($form);
        if ($errors !== []) {
            return $this->view->render($response, 'portal/templates/form.twig', [
                'tenant' => $tenant, 'template' => $template, 'form' => $form, 'errors' => $errors,
                'token_groups' => TokenCatalog::all(),
                'tenant_assets' => $this->tenantAssets($tenant),
                'snippets'      => SnippetLibrary::all(),
                'upload_url'    => "/portal/tenants/{$tenant->id}/assets/upload-json",
            ]);
        }

        $sanitized = $this->renderer->sanitize($form['html']);
        $this->templates->update($template->id, $tenant->id, $form['name'], $sanitized);

        $this->audit->record($tenant->id, 'template.updated', 'template', $template->id, "Template '{$form['name']}' saved");
        $this->session->flash('success', 'Template saved.');
        return $response->withHeader('Location', "/portal/tenants/{$tenant->id}/templates/{$template->id}/edit")->withStatus(302);
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [$tenant, $err] = $this->resolveTenant($request, $args);
        if ($err) return $err;
        $this->templates->delete((int) $args['tid'], $tenant->id);
        $this->audit->record($tenant->id, 'template.deleted', 'template', (int) $args['tid'], 'Template deleted');
        $this->session->flash('success', 'Template deleted.');
        return $response->withHeader('Location', "/portal/tenants/{$tenant->id}/templates")->withStatus(302);
    }

    public function duplicate(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [$tenant, $err] = $this->resolveTenant($request, $args);
        if ($err) return $err;
        $template = $this->templates->find((int) $args['tid'], $tenant->id);
        if ($template === null) return $this->notFound($response);

        $newName = $this->uniqueDuplicateName($tenant->id, $template->name);
        $newId   = $this->templates->create($tenant->id, $newName, $template->html);

        $this->audit->record($tenant->id, 'template.duplicated', 'template', $newId, "Template '{$template->name}' cloned to '{$newName}'", ['source_id' => $template->id]);
        $this->session->flash('success', "Template duplicated as \"{$newName}\".");
        return $response->withHeader('Location', "/portal/tenants/{$tenant->id}/templates/{$newId}/edit")->withStatus(302);
    }

    /**
     * Pick a name like "Foo (copy)" — or "Foo (copy 2)", "Foo (copy 3)", … —
     * that doesn't collide with anything already in the tenant.
     */
    private function uniqueDuplicateName(int $tenantId, string $original): string
    {
        $existing = array_map(fn($t) => $t->name, $this->templates->listForTenant($tenantId));
        $base = preg_replace('/\s*\(copy(?:\s+\d+)?\)\s*$/u', '', $original) ?? $original;
        $candidate = $base . ' (copy)';
        $i = 2;
        while (in_array($candidate, $existing, true)) {
            $candidate = $base . ' (copy ' . $i . ')';
            $i++;
            if ($i > 999) break; // sanity
        }
        return $candidate;
    }

    /** Live preview endpoint — sanitizes the posted HTML and renders with sample data. */
    public function preview(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [$tenant, $err] = $this->resolveTenant($request, $args);
        if ($err) return $err;

        $body = (array) $request->getParsedBody();
        $html = (string) ($body['html'] ?? '');
        $rendered = $this->renderer->render(
            $this->renderer->sanitize($html),
            TemplateRenderer::sampleTokens(),
        );

        $response->getBody()->write($rendered);
        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
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
            return [null, $this->forbidden((new ResponseFactory())->createResponse())];
        }
        return [$tenant, null];
    }

    private function notFound(ResponseInterface $r): ResponseInterface { $r = $r->withStatus(404); $r->getBody()->write('Not found.'); return $r; }
    private function forbidden(ResponseInterface $r): ResponseInterface { $r = $r->withStatus(403); $r->getBody()->write('Forbidden.'); return $r; }

    /** @param array<string,string> $form */
    private function validate(array $form): array
    {
        $errors = [];
        if ($form['name'] === '') {
            $errors[] = 'Name is required.';
        }
        if (trim($form['html']) === '') {
            $errors[] = 'Template body cannot be empty.';
        }
        return $errors;
    }

}
