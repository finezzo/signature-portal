<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Auth\SessionManager;
use App\Config\Config;
use App\Http\Middleware\AuthMiddleware;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class AuthMiddlewareTest extends TestCase
{
    private PDO $pdo;
    private ?array $seenUser = null;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT, tenant_id INT, password_changed_at TEXT)');
        $this->pdo->exec("INSERT INTO users VALUES (5, 'tenant_editor', 2, '2026-01-01 00:00:00')");

        // What was captured at login, when this user still had more rights.
        $_SESSION = ['user' => [
            'id'        => 5,
            'email'     => 'x@example.com',
            'name'      => null,
            'role'      => 'superadmin',
            'tenant_id' => null,
            'pw_epoch'  => '2026-01-01 00:00:00',
        ]];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function run_(): ResponseInterface
    {
        $handler = new class ($this->seenUser) implements RequestHandlerInterface {
            public function __construct(private ?array &$seen) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->seen = $request->getAttribute('current_user');
                return (new ResponseFactory())->createResponse(200);
            }
        };
        $session = new SessionManager(new Config([]));
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/portal/dashboard');

        return (new AuthMiddleware($session, $this->pdo))->process($request, $handler);
    }

    public function testDemotionTakesEffectOnTheNextRequest(): void
    {
        $response = $this->run_();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('tenant_editor', $this->seenUser['role']);
        $this->assertSame(2, $this->seenUser['tenant_id']);
        $this->assertSame('tenant_editor', $_SESSION['user']['role'] ?? null);
    }

    public function testDeletedUserIsSentToLogin(): void
    {
        $this->pdo->exec('DELETE FROM users');

        $response = $this->run_();

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/portal/login', $response->getHeaderLine('Location'));
        $this->assertNull($this->seenUser);
    }

    public function testChangedPasswordEndsTheSession(): void
    {
        $this->pdo->exec("UPDATE users SET password_changed_at = '2026-06-01 00:00:00'");

        $this->assertSame(302, $this->run_()->getStatusCode());
        $this->assertNull($this->seenUser);
    }
}
