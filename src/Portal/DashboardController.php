<?php
declare(strict_types=1);

namespace App\Portal;

use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

final class DashboardController
{
    public function __construct(
        private readonly Twig $view,
        private readonly PDO $pdo,
    ) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user           = (array) $request->getAttribute('current_user');
        $isSuperadmin   = ($user['role'] ?? '') === 'superadmin';
        $userTenantId   = isset($user['tenant_id']) ? (int) $user['tenant_id'] : 0;

        if ($isSuperadmin) {
            $tenants = $this->pdo->query(
                'SELECT id, slug, name, created_at FROM tenants ORDER BY name'
            )->fetchAll();
            $counts = [
                'tenants'   => (int) $this->pdo->query('SELECT COUNT(*) FROM tenants')->fetchColumn(),
                'templates' => (int) $this->pdo->query('SELECT COUNT(*) FROM templates')->fetchColumn(),
                'rules'     => (int) $this->pdo->query('SELECT COUNT(*) FROM rules')->fetchColumn(),
            ];
        } else {
            // Non-superadmins only see their own tenant and only counts
            // scoped to it — exposing global counts would leak how many
            // other tenants/templates/rules exist in the system.
            $stmt = $this->pdo->prepare(
                'SELECT id, slug, name, created_at FROM tenants WHERE id = :tid'
            );
            $stmt->execute([':tid' => $userTenantId]);
            $tenants = $stmt->fetchAll();

            $tplStmt = $this->pdo->prepare('SELECT COUNT(*) FROM templates WHERE tenant_id = :tid');
            $tplStmt->execute([':tid' => $userTenantId]);
            $rlStmt  = $this->pdo->prepare('SELECT COUNT(*) FROM rules WHERE tenant_id = :tid');
            $rlStmt->execute([':tid' => $userTenantId]);

            $counts = [
                'tenants'   => count($tenants),
                'templates' => (int) $tplStmt->fetchColumn(),
                'rules'     => (int) $rlStmt->fetchColumn(),
            ];
        }

        return $this->view->render($response, 'portal/dashboard.twig', [
            'tenants' => $tenants,
            'counts'  => $counts,
        ]);
    }
}
