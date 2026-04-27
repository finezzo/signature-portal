<?php
declare(strict_types=1);

namespace App\Portal;

use App\Auth\SessionManager;
use App\Config\Config;
use App\Tenant\AssetRepository;
use App\Tenant\TenantRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Slim\Views\Twig;

/**
 * Per-tenant image asset library: list, upload, delete.
 *
 * Files are stored under public/assets/tenants/<slug>/ and served directly
 * by Apache. Templates reference them by URL — there is no token
 * substitution layer, so a "copy URL" UI is enough.
 */
final class AssetController
{
    public function __construct(
        private readonly Twig $view,
        private readonly TenantRepository $tenants,
        private readonly AssetRepository $assets,
        private readonly SessionManager $session,
        private readonly Config $config,
    ) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $tenant = $this->loadTenantOr404($args, $request);
        if ($tenant instanceof ResponseInterface) return $tenant;

        $items = $this->assets->list($tenant->slug, (string) $this->config->get('base_url', ''));

        return $this->view->render($response, 'portal/assets/index.twig', [
            'tenant'       => $tenant,
            'assets'       => $items,
            'max_size_mb'  => AssetRepository::MAX_SIZE_BYTES / 1024 / 1024,
        ]);
    }

    public function upload(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $tenant = $this->loadTenantOr404($args, $request);
        if ($tenant instanceof ResponseInterface) return $tenant;

        $files  = $request->getUploadedFiles();
        $upload = $files['file'] ?? null;
        if ($upload === null) {
            $this->session->flash('error', 'No file submitted.');
            return $this->redirectToIndex($response, $tenant->id);
        }

        // Convert PSR-7 UploadedFile to the array shape AssetRepository expects.
        $tmp = sys_get_temp_dir() . '/sigportal_upload_' . bin2hex(random_bytes(8));
        try {
            $upload->moveTo($tmp);
        } catch (\Throwable $e) {
            $this->session->flash('error', 'Could not receive uploaded file: ' . $e->getMessage());
            return $this->redirectToIndex($response, $tenant->id);
        }

        $shape = [
            'tmp_name' => $tmp,
            'name'     => (string) $upload->getClientFilename(),
            'size'     => (int) $upload->getSize(),
            'type'     => (string) $upload->getClientMediaType(),
            'error'    => $upload->getError(),
        ];

        try {
            $stored = $this->assets->store($tenant->slug, $shape);
            $this->session->flash('success', 'Uploaded as ' . $stored . '.');
        } catch (RuntimeException $e) {
            @unlink($tmp);
            $this->session->flash('error', $e->getMessage());
        }

        return $this->redirectToIndex($response, $tenant->id);
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $tenant = $this->loadTenantOr404($args, $request);
        if ($tenant instanceof ResponseInterface) return $tenant;

        $body     = (array) $request->getParsedBody();
        $filename = (string) ($body['filename'] ?? '');
        try {
            $this->assets->delete($tenant->slug, $filename);
            $this->session->flash('success', 'File deleted.');
        } catch (RuntimeException $e) {
            $this->session->flash('error', $e->getMessage());
        }
        return $this->redirectToIndex($response, $tenant->id);
    }

    /**
     * JSON-returning variant of {@see upload()}, used by the in-editor
     * drag-and-drop / paste-image flow. CSRF-protected by the same global
     * middleware (token expected in body or `X-CSRF-Token` header).
     *
     * Response shape (success):
     *   { "location": "https://…/assets/tenants/<slug>/<filename>", "filename": "…" }
     *
     * Response shape (error):
     *   { "error": "human-readable message" }
     */
    public function jsonUpload(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $tenant = $this->loadTenantOr404($args, $request);
        if ($tenant instanceof ResponseInterface) return $tenant;

        $files  = $request->getUploadedFiles();
        $upload = $files['file'] ?? null;
        if ($upload === null) {
            return $this->json($response, 400, ['error' => 'No file submitted.']);
        }

        $tmp = sys_get_temp_dir() . '/sigportal_upload_' . bin2hex(random_bytes(8));
        try {
            $upload->moveTo($tmp);
        } catch (\Throwable $e) {
            return $this->json($response, 500, ['error' => 'Could not receive uploaded file.']);
        }

        $shape = [
            'tmp_name' => $tmp,
            'name'     => (string) $upload->getClientFilename(),
            'size'     => (int) $upload->getSize(),
            'type'     => (string) $upload->getClientMediaType(),
            'error'    => $upload->getError(),
        ];

        try {
            $stored  = $this->assets->store($tenant->slug, $shape);
            $baseUrl = (string) $this->config->get('base_url', '');
            $url     = rtrim($baseUrl, '/') . '/assets/tenants/' . rawurlencode($tenant->slug) . '/' . rawurlencode($stored);
            return $this->json($response, 200, ['location' => $url, 'filename' => $stored]);
        } catch (RuntimeException $e) {
            @unlink($tmp);
            return $this->json($response, 422, ['error' => $e->getMessage()]);
        }
    }

    private function json(ResponseInterface $response, int $status, array $payload): ResponseInterface
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $response->getBody()->write($body !== false ? $body : '{"error":"json encode failed"}');
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json; charset=UTF-8');
    }

    // ---------- helpers ----------

    private function redirectToIndex(ResponseInterface $response, int $tenantId): ResponseInterface
    {
        return $response->withHeader('Location', "/portal/tenants/{$tenantId}/assets")->withStatus(302);
    }

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
            $resp = (new \Slim\Psr7\Factory\ResponseFactory())->createResponse(403);
            $resp->getBody()->write('Forbidden.');
            return $resp;
        }
        return $tenant;
    }
}
