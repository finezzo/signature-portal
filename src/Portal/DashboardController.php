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
        $user = (array) $request->getAttribute('current_user');

        if (($user['role'] ?? '') === 'superadmin') {
            $tenants = $this->pdo->query(
                'SELECT id, slug, name, created_at FROM tenants ORDER BY name'
            )->fetchAll();
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT id, slug, name, created_at FROM tenants WHERE id = :tid'
            );
            $stmt->execute([':tid' => $user['tenant_id']]);
            $tenants = $stmt->fetchAll();
        }

        $counts = [
            'tenants'   => (int) $this->pdo->query('SELECT COUNT(*) FROM tenants')->fetchColumn(),
            'templates' => (int) $this->pdo->query('SELECT COUNT(*) FROM templates')->fetchColumn(),
            'rules'     => (int) $this->pdo->query('SELECT COUNT(*) FROM rules')->fetchColumn(),
        ];

        return $this->view->render($response, 'portal/dashboard.twig', [
            'tenants' => $tenants,
            'counts'  => $counts,
        ]);
    }
}
