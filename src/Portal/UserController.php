<?php
declare(strict_types=1);

namespace App\Portal;

use App\Audit\AuditLogger;
use App\Auth\PasswordHasher;
use App\Auth\SessionManager;
use App\Auth\UserRepository;
use App\Tenant\TenantRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Views\Twig;

/**
 * User management — superadmin-only top-level page.
 *
 * Scope rules:
 *   - Only superadmins reach this controller (enforced in every action).
 *   - When creating/editing a user, the tenant_id can be:
 *       * empty   → another superadmin (tenant-NULL row)
 *       * set     → tenant_admin or tenant_editor on that tenant
 */
final class UserController
{
    private const ROLES = ['superadmin', 'tenant_admin', 'tenant_editor'];

    public function __construct(
        private readonly Twig $view,
        private readonly UserRepository $users,
        private readonly TenantRepository $tenants,
        private readonly PasswordHasher $hasher,
        private readonly SessionManager $session,
        private readonly AuditLogger $audit,
    ) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (($r = $this->requireSuperadmin($request)) !== null) return $r;

        $allTenants  = $this->tenants->all();
        $tenantsById = [];
        foreach ($allTenants as $t) {
            $tenantsById[$t->id] = $t;
        }

        return $this->view->render($response, 'portal/users/index.twig', [
            'users'         => $this->users->listAll(),
            'tenants_by_id' => $tenantsById,
        ]);
    }

    public function newForm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (($r = $this->requireSuperadmin($request)) !== null) return $r;

        return $this->view->render($response, 'portal/users/form.twig', [
            'user'    => null,
            'tenants' => $this->tenants->all(),
            'form'    => ['email' => '', 'name' => '', 'role' => 'tenant_editor', 'tenant_id' => '', 'password' => ''],
            'errors'  => [],
        ]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (($r = $this->requireSuperadmin($request)) !== null) return $r;

        $form   = $this->normalizeForm((array) $request->getParsedBody());
        $errors = $this->validate($form, isCreate: true, exceptId: null);

        if ($errors !== []) {
            return $this->view->render($response, 'portal/users/form.twig', [
                'user' => null, 'tenants' => $this->tenants->all(), 'form' => $form, 'errors' => $errors,
            ]);
        }

        $tid   = $form['tenant_id'] !== '' ? (int) $form['tenant_id'] : null;
        $newId = $this->users->create(
            $tid,
            $form['email'],
            $this->hasher->hash($form['password']),
            $form['role'],
            $form['name'] !== '' ? $form['name'] : null,
        );
        $this->audit->record($tid, 'user.created', 'user', $newId, "User {$form['email']} created with role {$form['role']}");
        $this->session->flash('success', 'User created.');
        return $response->withHeader('Location', '/portal/users')->withStatus(302);
    }

    public function editForm(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (($r = $this->requireSuperadmin($request)) !== null) return $r;

        $user = $this->users->find((int) $args['uid']);
        if ($user === null) return $this->notFound($response);

        return $this->view->render($response, 'portal/users/form.twig', [
            'user'    => $user,
            'tenants' => $this->tenants->all(),
            'form'    => [
                'email'     => (string) $user['email'],
                'name'      => (string) ($user['name'] ?? ''),
                'role'      => (string) $user['role'],
                'tenant_id' => $user['tenant_id'] !== null ? (string) $user['tenant_id'] : '',
                'password'  => '',
            ],
            'errors'  => [],
        ]);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (($r = $this->requireSuperadmin($request)) !== null) return $r;

        $user = $this->users->find((int) $args['uid']);
        if ($user === null) return $this->notFound($response);

        $form   = $this->normalizeForm((array) $request->getParsedBody());
        $errors = $this->validate($form, isCreate: false, exceptId: (int) $user['id']);

        if ($errors !== []) {
            return $this->view->render($response, 'portal/users/form.twig', [
                'user' => $user, 'tenants' => $this->tenants->all(), 'form' => $form, 'errors' => $errors,
            ]);
        }

        $tid = $form['tenant_id'] !== '' ? (int) $form['tenant_id'] : null;
        $this->users->update(
            (int) $user['id'],
            $tid,
            $form['role'],
            $form['name'] !== '' ? $form['name'] : null,
        );
        if ($form['password'] !== '') {
            $this->users->setPassword((int) $user['id'], $this->hasher->hash($form['password']));
            $this->audit->record($tid, 'user.password_reset', 'user', (int) $user['id'], "Password reset for {$user['email']}");
        }
        $this->audit->record($tid, 'user.updated', 'user', (int) $user['id'], "User {$user['email']} updated");

        $this->session->flash('success', 'User updated.');
        return $response->withHeader('Location', '/portal/users')->withStatus(302);
    }

    public function unlock(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (($r = $this->requireSuperadmin($request)) !== null) return $r;
        $target = $this->users->find((int) $args['uid']);
        $this->users->unlock((int) $args['uid']);
        $this->audit->record(
            $target !== null && $target['tenant_id'] !== null ? (int) $target['tenant_id'] : null,
            'user.unlocked',
            'user',
            (int) $args['uid'],
            $target !== null ? "User {$target['email']} unlocked" : 'User unlocked',
        );
        $this->session->flash('success', 'User unlocked.');
        return $response->withHeader('Location', '/portal/users')->withStatus(302);
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (($r = $this->requireSuperadmin($request)) !== null) return $r;

        $sessionUser = (array) $request->getAttribute('current_user');
        if ((int) ($sessionUser['id'] ?? 0) === (int) $args['uid']) {
            $this->session->flash('error', 'You cannot delete your own account.');
            return $response->withHeader('Location', '/portal/users')->withStatus(302);
        }
        $target = $this->users->find((int) $args['uid']);
        $this->users->delete((int) $args['uid']);
        $this->audit->record(
            $target !== null && $target['tenant_id'] !== null ? (int) $target['tenant_id'] : null,
            'user.deleted',
            'user',
            (int) $args['uid'],
            $target !== null ? "User {$target['email']} deleted" : 'User deleted',
        );
        $this->session->flash('success', 'User deleted.');
        return $response->withHeader('Location', '/portal/users')->withStatus(302);
    }

    // ---------- helpers ----------

    private function requireSuperadmin(ServerRequestInterface $request): ?ResponseInterface
    {
        $user = (array) $request->getAttribute('current_user');
        if (!AccessControl::isSuperadmin($user)) {
            $r = (new ResponseFactory())->createResponse(403);
            $r->getBody()->write('Forbidden — only superadmins can manage users.');
            return $r;
        }
        return null;
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
            'email'     => mb_strtolower(trim((string) ($body['email'] ?? ''))),
            'name'      => trim((string) ($body['name'] ?? '')),
            'role'      => (string) ($body['role'] ?? 'tenant_editor'),
            'tenant_id' => trim((string) ($body['tenant_id'] ?? '')),
            'password'  => (string) ($body['password'] ?? ''),
        ];
    }

    /** @param array<string,string> $form */
    private function validate(array $form, bool $isCreate, ?int $exceptId): array
    {
        $errors = [];
        if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid email is required.';
        } elseif ($this->users->existsByEmail($form['email'], $exceptId)) {
            $errors[] = 'A user with this email already exists.';
        }
        if (!in_array($form['role'], self::ROLES, true)) {
            $errors[] = 'Invalid role.';
        }
        // superadmins must not be tenant-scoped, tenant_* must be tenant-scoped
        if ($form['role'] === 'superadmin' && $form['tenant_id'] !== '') {
            $errors[] = 'Superadmins must not be assigned to a tenant.';
        }
        if (in_array($form['role'], ['tenant_admin', 'tenant_editor'], true) && $form['tenant_id'] === '') {
            $errors[] = 'Tenant admins and editors must be assigned to a tenant.';
        }
        if ($isCreate && mb_strlen($form['password']) < 10) {
            $errors[] = 'Password must be at least 10 characters.';
        }
        if (!$isCreate && $form['password'] !== '' && mb_strlen($form['password']) < 10) {
            $errors[] = 'New password must be at least 10 characters (or leave blank to keep the existing one).';
        }
        return $errors;
    }
}
