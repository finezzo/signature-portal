<?php
declare(strict_types=1);

namespace Tests\Portal;

use App\Audit\AuditLogger;
use App\Auth\Authenticator;
use App\Auth\EntraProvider;
use App\Auth\MfaService;
use App\Auth\PasswordHasher;
use App\Auth\SessionManager;
use App\Auth\SsoAuthenticator;
use App\Auth\Totp;
use App\Config\Config;
use App\Crypto\Encryption;
use App\Http\RateLimiter;
use App\Portal\AuthController;
use App\Tenant\TenantRepository;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Views\Twig;
use Tests\Auth\MfaTestDatabase;

require_once __DIR__ . '/../Auth/MfaTestDatabase.php';

/** The redirects that take a user through the two sign-in steps. */
final class AuthControllerMfaTest extends TestCase
{
    private const EMAIL    = 'x@example.com';
    private const PASSWORD = 'correct horse battery';

    private PDO $pdo;
    private AuthController $controller;
    private string $secret;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->pdo = MfaTestDatabase::create();

        $hasher = new PasswordHasher();
        $insert = $this->pdo->prepare("INSERT INTO users (id, email, password_hash, role) VALUES (5, :email, :hash, 'tenant_admin')");
        $insert->execute([':email' => self::EMAIL, ':hash' => $hasher->hash(self::PASSWORD)]);

        $crypto  = new Encryption(Encryption::generateAppKey());
        $session = new SessionManager(new Config([]));
        $mfa     = new MfaService($this->pdo, $crypto);

        $this->secret = Totp::generateSecret();
        $mfa->enable(5, $this->secret, intdiv(time(), 30) - 5);

        $this->controller = new AuthController(
            Twig::create(dirname(__DIR__, 2) . '/templates'),
            new Authenticator($this->pdo, $hasher, $session, $mfa, new AuditLogger($this->pdo, $session)),
            $session,
            new TenantRepository($this->pdo),
            new EntraProvider($crypto, 'https://sig.example.com'),
            new SsoAuthenticator($this->pdo, $session),
            new RateLimiter($this->pdo),
        );
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    /** @param array<string,string> $form */
    private function post(string $action, array $form): ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/portal/login', ['REMOTE_ADDR' => '203.0.113.9'])
            ->withParsedBody($form);
        return $this->controller->{$action}($request, (new ResponseFactory())->createResponse());
    }

    public function testCorrectPasswordLeadsToTheCodeStepWithoutAnError(): void
    {
        $response = $this->post('doLogin', ['email' => self::EMAIL, 'password' => self::PASSWORD]);

        $this->assertSame('/portal/login/verify', $response->getHeaderLine('Location'));
        $this->assertArrayNotHasKey('flash_login_error', $_SESSION);
    }

    public function testWrongPasswordStaysOnTheLoginPage(): void
    {
        $response = $this->post('doLogin', ['email' => self::EMAIL, 'password' => 'wrong']);

        $this->assertSame('/portal/login', $response->getHeaderLine('Location'));
    }

    public function testCorrectCodeLeadsToTheDashboard(): void
    {
        $this->post('doLogin', ['email' => self::EMAIL, 'password' => self::PASSWORD]);

        $response = $this->post('doSecondFactor', ['code' => Totp::code($this->secret, intdiv(time(), 30))]);

        $this->assertSame('/portal/dashboard', $response->getHeaderLine('Location'));
        $this->assertSame(5, $_SESSION['user']['id']);
    }

    public function testWrongCodeStaysOnTheCodeStep(): void
    {
        $this->post('doLogin', ['email' => self::EMAIL, 'password' => self::PASSWORD]);

        $response = $this->post('doSecondFactor', ['code' => '000000']);

        $this->assertSame('/portal/login/verify', $response->getHeaderLine('Location'));
        $this->assertSame('That code is not valid.', $_SESSION['flash_login_error']);
    }

    public function testCodeWithoutAPendingSignInGoesBackToTheStart(): void
    {
        $response = $this->post('doSecondFactor', ['code' => Totp::code($this->secret, intdiv(time(), 30))]);

        $this->assertSame('/portal/login', $response->getHeaderLine('Location'));
        $this->assertArrayNotHasKey('user', $_SESSION);
    }
}
