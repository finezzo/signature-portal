<?php
declare(strict_types=1);

namespace App\Portal;

use App\Audit\AuditRepository;
use App\Tenant\TenantRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Views\Twig;

final class AuditController
{
    public function __construct(
        private readonly Twig $view,
        private readonly TenantRepository $tenants,
        private readonly AuditRepository $audit,
    ) {}

    public function tenantActivity(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id     = (int) ($args['id'] ?? 0);
        $tenant = $id > 0 ? $this->tenants->find($id) : null;
        if ($tenant === null) {
            $r = (new ResponseFactory())->createResponse(404);
            $r->getBody()->write('Tenant not found.');
            return $r;
        }
        $user = (array) $request->getAttribute('current_user');
        if (!AccessControl::canAccessTenant($user, $tenant->id)) {
            $r = (new ResponseFactory())->createResponse(403);
            $r->getBody()->write('Forbidden.');
            return $r;
        }

        return $this->view->render($response, 'portal/activity/index.twig', [
            'tenant'  => $tenant,
            'entries' => $this->audit->listForTenant($tenant->id, 200),
        ]);
    }
}
