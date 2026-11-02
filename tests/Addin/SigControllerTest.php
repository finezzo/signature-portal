<?php
declare(strict_types=1);

namespace Tests\Addin;

use App\Addin\SigController;
use App\Addin\SignatureService;
use App\Crypto\Encryption;
use App\Graph\GraphClient;
use App\Graph\ProfileResolver;
use App\Graph\TokenCache;
use App\Http\RateLimiter;
use App\Tenant\DisplayEmailDeriver;
use App\Tenant\ProfileRepository;
use App\Tenant\RecipientClassifier;
use App\Tenant\RuleEngine;
use App\Tenant\RuleRepository;
use App\Tenant\TemplateRenderer;
use App\Tenant\TemplateRepository;
use App\Tenant\TenantRepository;
use App\Tenant\TenantService;
use App\Tenant\UserOverrideRepository;
use GuzzleHttp\Client;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * The real pipeline on in-memory SQLite. The tenant has no Entra credentials, so
 * Graph fails immediately and profiles come from the local store. RateLimiter
 * fails open here (its SQL is MySQL-only), which leaves it out of these tests.
 */
final class SigControllerTest extends TestCase
{
    private const KEY    = 'test-api-key';
    private const TESTER = 'tester@acme.example';
    private const USER   = 'user@acme.example';

    private SigController $controller;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec("CREATE TABLE tenants (
            id INTEGER PRIMARY KEY, slug TEXT, name TEXT, email_domains TEXT,
            entra_tenant_id TEXT, entra_client_id TEXT, entra_client_secret_encrypted TEXT,
            api_key_encrypted TEXT, api_key_last_rotated_at TEXT, api_key_acknowledged_at TEXT,
            manifest_guid TEXT, sso_enabled INT, sso_auto_provision INT, sso_default_role TEXT,
            shared_display_mode TEXT, shared_display_mode_per_domain TEXT, disclaimer_html TEXT,
            addin_testers TEXT, created_at TEXT, updated_at TEXT)");
        $pdo->exec('CREATE TABLE templates (id INTEGER PRIMARY KEY, tenant_id INT, name TEXT, html TEXT,
            is_manually_selectable INT, created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE rules (id INTEGER PRIMARY KEY, tenant_id INT, template_id INT, from_domain TEXT,
            language TEXT, mailbox_type TEXT, recipient_scope TEXT, priority INT, is_fallback INT, is_enabled INT,
            valid_from TEXT, valid_until TEXT, created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE user_overrides (id INTEGER PRIMARY KEY, tenant_id INT, email TEXT, template_id INT,
            note TEXT, created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE user_profiles (id INTEGER PRIMARY KEY, tenant_id INT, email TEXT, display_name TEXT,
            source TEXT, profile_json TEXT, graph_synced_at TEXT, created_at TEXT, updated_at TEXT)');

        $encryption = new Encryption(Encryption::generateAppKey());
        $insert = $pdo->prepare("INSERT INTO tenants (id, slug, name, email_domains, api_key_encrypted, addin_testers,
                entra_tenant_id, entra_client_id, entra_client_secret_encrypted, api_key_last_rotated_at, manifest_guid,
                created_at, updated_at)
            VALUES (1, 'acme', 'Acme', :domains, :key, :testers, NULL, NULL, NULL, NULL, NULL, '', '')");
        $insert->execute([
            ':domains' => json_encode(['acme.example']),
            ':key'     => $encryption->encrypt(self::KEY),
            ':testers' => json_encode([self::TESTER]),
        ]);
        $pdo->exec("INSERT INTO templates (id, tenant_id, name, html, is_manually_selectable, created_at, updated_at)
            VALUES (1, 1, 'Default', '<div>{first_name} via rule</div>', 1, '', '')");
        $pdo->exec("INSERT INTO rules (tenant_id, template_id, from_domain, recipient_scope, priority, is_fallback, is_enabled, created_at, updated_at)
            VALUES (1, 1, 'acme.example', 'all', 100, 0, 1, '', '')");
        $pdo->exec("INSERT INTO rules (tenant_id, template_id, from_domain, recipient_scope, priority, is_fallback, is_enabled, created_at, updated_at)
            VALUES (1, 1, 'external.example', 'external', 50, 0, 1, '', '')");

        $store = new ProfileRepository($pdo);
        foreach ([self::TESTER => 'Tess', self::USER => 'Uli'] as $email => $firstName) {
            $store->upsert(1, $email, [
                'userPrincipalName' => $email,
                'mail'              => $email,
                'givenName'         => $firstName,
                'assignedLicenses'  => [['skuId' => 'local-manual']],
            ], 'manual');
        }

        $tenants = new TenantRepository($pdo);
        $rules   = new RuleRepository($pdo);
        $service = new SignatureService(
            $tenants,
            new TenantService($tenants, $encryption),
            new TemplateRepository($pdo),
            new RuleEngine($rules, new RecipientClassifier()),
            new TemplateRenderer(sys_get_temp_dir() . '/sigportal-test-purifier'),
            new ProfileResolver(new GraphClient(new Client(), new TokenCache($pdo, $encryption), $encryption), $store),
            new DisplayEmailDeriver(),
            new UserOverrideRepository($pdo),
        );
        $this->controller = new SigController($service, new RateLimiter($pdo));
    }

    /**
     * @param array<string,string> $query
     * @param array<string,string> $headers
     */
    private function call(array $query, array $headers = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/sig')
            ->withQueryParams($query + ['tenant' => 'acme'])
            ->withHeader('X-Sig-Key', self::KEY);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        return $this->controller->getSignature($request, (new ResponseFactory())->createResponse());
    }

    public function testRegularUserGetsASignatureFromTheStableAddin(): void
    {
        $response = $this->call(['email' => self::USER, 'primary' => self::USER]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Uli via rule', (string) $response->getBody());
    }

    public function testStableAddinStandsDownForATester(): void
    {
        $response = $this->call(['email' => self::TESTER, 'primary' => self::TESTER]);

        $this->assertSame(204, $response->getStatusCode());
    }

    public function testTesterIsMatchedByPrimaryMailboxWhenSendingFromSharedOne(): void
    {
        $response = $this->call(['email' => 'info@acme.example', 'primary' => 'TESTER@acme.example']);

        $this->assertSame(204, $response->getStatusCode());
    }

    public function testPreviewAddinServesATester(): void
    {
        $response = $this->call(['email' => self::TESTER, 'primary' => self::TESTER, 'channel' => 'preview']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Tess via rule', (string) $response->getBody());
    }

    public function testManualPickFromStableTaskpaneStillWorksForATester(): void
    {
        $response = $this->call(['email' => self::TESTER, 'primary' => self::TESTER, 'template_id' => '1']);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testAddressesInHeadersAreUsedAndPercentDecoded(): void
    {
        $response = $this->call(['channel' => 'preview'], [
            'X-Sig-Email'   => rawurlencode(self::USER),
            'X-Sig-Primary' => rawurlencode(self::USER),
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Uli via rule', (string) $response->getBody());
    }

    public function testHeadersWinOverQueryString(): void
    {
        $response = $this->call(
            ['email' => self::TESTER, 'primary' => self::TESTER, 'channel' => 'preview'],
            ['X-Sig-Email' => rawurlencode(self::USER), 'X-Sig-Primary' => rawurlencode(self::USER)],
        );

        $this->assertStringContainsString('Uli via rule', (string) $response->getBody());
    }

    public function testRecipientDomainAloneIsEnoughForRecipientScopedRules(): void
    {
        $sender = ['email' => 'someone@external.example', 'primary' => self::USER];

        $external = $this->call($sender, ['X-Sig-Recipients' => rawurlencode('@customer.example')]);
        $this->assertSame(200, $external->getStatusCode());

        $internalOnly = $this->call($sender, ['X-Sig-Recipients' => rawurlencode('@acme.example')]);
        $this->assertSame(204, $internalOnly->getStatusCode());
    }

    public function testWrongKeyIsRejected(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/sig')
            ->withQueryParams(['tenant' => 'acme', 'email' => self::USER])
            ->withHeader('X-Sig-Key', 'wrong');
        $response = $this->controller->getSignature($request, (new ResponseFactory())->createResponse());

        $this->assertSame(401, $response->getStatusCode());
    }
}
