<?php
declare(strict_types=1);

namespace App\Portal;

use App\Addin\ManifestGenerator;
use App\Auth\SessionManager;
use App\Tenant\TenantRepository;
use App\Tenant\TenantService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Stream;
use Slim\Views\Twig;

final class TenantController
{
    public function __construct(
        private readonly Twig $view,
        private readonly TenantRepository $tenants,
        private readonly TenantService $tenantService,
        private readonly ManifestGenerator $manifest,
        private readonly SessionManager $session,
    ) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = (array) $request->getAttribute('current_user');

        if (AccessControl::isSuperadmin($user)) {
            $tenants = $this->tenants->all();
        } else {
            $tid = (int) ($user['tenant_id'] ?? 0);
            $t   = $tid > 0 ? $this->tenants->find($tid) : null;
            $tenants = $t ? [$t] : [];
        }

        return $this->view->render($response, 'portal/tenants/index.twig', [
            'tenants' => $tenants,
        ]);
    }

    public function newForm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = (array) $request->getAttribute('current_user');
        if (!AccessControl::isSuperadmin($user)) {
            return $this->forbidden($response);
        }
        return $this->view->render($response, 'portal/tenants/form.twig', [
            'tenant' => null,
            'form'   => ['slug' => '', 'name' => '', 'email_domains' => ''],
            'errors' => [],
        ]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = (array) $request->getAttribute('current_user');
        if (!AccessControl::isSuperadmin($user)) {
            return $this->forbidden($response);
        }

        $body = (array) $request->getParsedBody();
        $form = [
            'slug'          => mb_strtolower(trim((string) ($body['slug'] ?? ''))),
            'name'          => trim((string) ($body['name'] ?? '')),
            'email_domains' => (string) ($body['email_domains'] ?? ''),
        ];

        $errors = $this->validateTenantForm($form, isCreate: true);
        if ($errors !== []) {
            return $this->view->render($response, 'portal/tenants/form.twig', [
                'tenant' => null, 'form' => $form, 'errors' => $errors,
            ]);
        }

        $id = $this->tenants->create(
            $form['slug'],
            $form['name'],
            $this->parseDomains($form['email_domains']),
            TenantService::generateGuid(),
        );

        $apiKey = $this->tenantService->rotateApiKey($id);
        $this->session->flash('success', 'Tenant created. API key (shown once): ' . $apiKey);

        return $response->withHeader('Location', "/portal/tenants/{$id}")->withStatus(302);
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $tenant = $this->loadTenantOr404($args, $request);
        if ($tenant instanceof ResponseInterface) return $tenant;

        return $this->view->render($response, 'portal/tenants/show.twig', [
            'tenant' => $tenant,
        ]);
    }

    public function editForm(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $tenant = $this->loadTenantOr404($args, $request);
        if ($tenant instanceof ResponseInterface) return $tenant;

        return $this->view->render($response, 'portal/tenants/form.twig', [
            'tenant' => $tenant,
            'form'   => [
                'slug'             => $tenant->slug,
                'name'             => $tenant->name,
                'email_domains'    => implode("\n", $tenant->emailDomains),
                'entra_tenant_id'  => $tenant->entraTenantId ?? '',
                'entra_client_id'  => $tenant->entraClientId ?? '',
            ],
            'errors' => [],
        ]);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $tenant = $this->loadTenantOr404($args, $request);
        if ($tenant instanceof ResponseInterface) return $tenant;

        $body = (array) $request->getParsedBody();
        $form = [
            'slug'             => $tenant->slug, // immutable
            'name'             => trim((string) ($body['name'] ?? '')),
            'email_domains'    => (string) ($body['email_domains'] ?? ''),
            'entra_tenant_id'  => trim((string) ($body['entra_tenant_id'] ?? '')),
            'entra_client_id'  => trim((string) ($body['entra_client_id'] ?? '')),
        ];

        $errors = $this->validateTenantForm($form, isCreate: false);
        if ($errors !== []) {
            return $this->view->render($response, 'portal/tenants/form.twig', [
                'tenant' => $tenant, 'form' => $form, 'errors' => $errors,
            ]);
        }

        $newSecret = trim((string) ($body['entra_client_secret'] ?? ''));
        $encryptedSecret = $newSecret !== ''
            ? $this->tenantService->encryptEntraSecret($newSecret)
            : null;

        $this->tenants->update(
            $tenant->id,
            $form['name'],
            $this->parseDomains($form['email_domains']),
            $form['entra_tenant_id'] !== '' ? $form['entra_tenant_id'] : null,
            $form['entra_client_id'] !== '' ? $form['entra_client_id'] : null,
            $encryptedSecret,
        );

        $this->session->flash('success', 'Tenant updated.');
        return $response->withHeader('Location', "/portal/tenants/{$tenant->id}")->withStatus(302);
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = (array) $request->getAttribute('current_user');
        if (!AccessControl::isSuperadmin($user)) {
            return $this->forbidden($response);
        }
        $id = (int) $args['id'];
        $this->tenants->delete($id);
        $this->session->flash('success', 'Tenant deleted.');
        return $response->withHeader('Location', '/portal/tenants')->withStatus(302);
    }

    public function rotateKey(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $tenant = $this->loadTenantOr404($args, $request);
        if ($tenant instanceof ResponseInterface) return $tenant;

        $key = $this->tenantService->rotateApiKey($tenant->id);
        $this->session->flash('success', 'API key rotated. New key (shown once): ' . $key);
        return $response->withHeader('Location', "/portal/tenants/{$tenant->id}")->withStatus(302);
    }

    public function manifest(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $tenant = $this->loadTenantOr404($args, $request);
        if ($tenant instanceof ResponseInterface) return $tenant;

        $apiKey = $this->tenantService->getApiKeyPlaintext($tenant);
        if ($apiKey === null) {
            $this->session->flash('error', 'Tenant has no API key — rotate one first.');
            return $response->withHeader('Location', "/portal/tenants/{$tenant->id}")->withStatus(302);
        }

        $xml = $this->manifest->build($tenant, $apiKey);

        $body = new Stream(fopen('php://temp', 'r+'));
        $body->write($xml);
        $body->rewind();

        return $response
            ->withHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="manifest-' . $tenant->slug . '.xml"')
            ->withBody($body);
    }

    // ---------- helpers ----------

    /** @return \App\Tenant\Tenant|ResponseInterface */
    private function loadTenantOr404(array $args, ServerRequestInterface $request): \App\Tenant\Tenant|ResponseInterface
    {
        $id     = (int) ($args['id'] ?? 0);
        $tenant = $id > 0 ? $this->tenants->find($id) : null;
        if ($tenant === null) {
            $resp = (new \Slim\Psr7\Factory\ResponseFactory())->createResponse(404);
            $resp->getBody()->write('Tenant not found.');
            return $resp;
        }
        $user = (array) $request->getAttribute('current_user');
        if (!AccessControl::canAccessTenant($user, $tenant->id)) {
            return $this->forbidden(
                (new \Slim\Psr7\Factory\ResponseFactory())->createResponse()
            );
        }
        return $tenant;
    }

    private function forbidden(ResponseInterface $response): ResponseInterface
    {
        $r = $response->withStatus(403);
        $r->getBody()->write('Forbidden.');
        return $r;
    }

    /** @return list<string> */
    private function parseDomains(string $raw): array
    {
        $parts = preg_split('/[\s,;]+/', $raw) ?: [];
        $out   = [];
        foreach ($parts as $p) {
            $p = mb_strtolower(trim($p));
            if ($p === '') continue;
            // strip leading "@" if user pasted "@example.com"
            $p = ltrim($p, '@');
            if ($p !== '') $out[] = $p;
        }
        return array_values(array_unique($out));
    }

    /** @param array<string,string> $form @return list<string> */
    private function validateTenantForm(array $form, bool $isCreate): array
    {
        $errors = [];
        if (($form['name'] ?? '') === '') {
            $errors[] = 'Name is required.';
        }
        if ($isCreate) {
            $slug = $form['slug'] ?? '';
            if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/', $slug)) {
                $errors[] = 'Slug must be lowercase alphanumerics and dashes (max 64 chars, must start/end alphanumeric).';
            } elseif ($this->tenants->findBySlug($slug) !== null) {
                $errors[] = 'A tenant with this slug already exists.';
            }
        }
        return $errors;
    }
}
