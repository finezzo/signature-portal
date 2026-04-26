<?php
declare(strict_types=1);

use App\Addin\ManifestGenerator;
use App\Addin\SignatureService;
use App\Auth\Authenticator;
use App\Auth\Csrf;
use App\Auth\PasswordHasher;
use App\Auth\SessionManager;
use App\Config\Config;
use App\Crypto\Encryption;
use App\Db\Database;
use App\Graph\GraphClient;
use App\Graph\TokenCache;
use App\Http\Middleware\AuthMiddleware;
use App\Http\Middleware\CsrfMiddleware;
use App\Http\Middleware\SessionStartMiddleware;
use App\Http\Middleware\TwigGlobalsMiddleware;
use App\Tenant\DisplayEmailDeriver;
use App\Tenant\RecipientClassifier;
use App\Tenant\RuleEngine;
use App\Tenant\RuleRepository;
use App\Tenant\TemplateRenderer;
use App\Tenant\TemplateRepository;
use App\Tenant\TenantRepository;
use App\Tenant\TenantService;
use DI\Container;
use GuzzleHttp\Client as GuzzleHttp;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

require_once __DIR__ . '/../vendor/autoload.php';

return (static function (): \Slim\App {
    $rootDir    = dirname(__DIR__);
    $configPath = $rootDir . '/config/config.php';

    if (!is_file($configPath)) {
        header('Location: /install.php');
        exit;
    }

    $container = new Container();

    $container->set(Config::class, fn() => Config::loadFromFile($configPath));

    $container->set(\PDO::class, fn(Container $c) => Database::fromConfig($c->get(Config::class)));

    $container->set(Encryption::class, fn(Container $c) => new Encryption(
        (string) $c->get(Config::class)->get('app_key', '')
    ));

    $container->set(SessionManager::class, fn(Container $c) => new SessionManager($c->get(Config::class)));
    $container->set(PasswordHasher::class, fn() => new PasswordHasher());
    $container->set(Csrf::class, fn(Container $c) => new Csrf($c->get(SessionManager::class)));
    $container->set(Authenticator::class, fn(Container $c) => new Authenticator(
        $c->get(\PDO::class),
        $c->get(PasswordHasher::class),
        $c->get(SessionManager::class),
    ));

    $container->set(SessionStartMiddleware::class, fn(Container $c) => new SessionStartMiddleware($c->get(SessionManager::class)));
    $container->set(CsrfMiddleware::class,         fn(Container $c) => new CsrfMiddleware($c->get(Csrf::class)));
    $container->set(AuthMiddleware::class,         fn(Container $c) => new AuthMiddleware($c->get(SessionManager::class)));

    $container->set(Twig::class, function () use ($rootDir) {
        $cacheDir = $rootDir . '/var/cache/twig';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0775, true);
        }
        return Twig::create($rootDir . '/templates', [
            'cache' => $cacheDir,
            'auto_reload' => true,
        ]);
    });

    $container->set(TwigGlobalsMiddleware::class, fn(Container $c) => new TwigGlobalsMiddleware(
        $c->get(Twig::class), $c->get(SessionManager::class), $c->get(Csrf::class)
    ));

    // Phase 2 services
    $container->set(TenantRepository::class,   fn(Container $c) => new TenantRepository($c->get(\PDO::class)));
    $container->set(TemplateRepository::class, fn(Container $c) => new TemplateRepository($c->get(\PDO::class)));
    $container->set(RuleRepository::class,     fn(Container $c) => new RuleRepository($c->get(\PDO::class)));
    $container->set(RecipientClassifier::class,fn() => new RecipientClassifier());
    $container->set(RuleEngine::class,         fn(Container $c) => new RuleEngine(
        $c->get(RuleRepository::class), $c->get(RecipientClassifier::class)
    ));
    $container->set(TemplateRenderer::class,   fn() => new TemplateRenderer(
        $rootDir . '/var/cache/htmlpurifier'
    ));
    $container->set(TenantService::class,      fn(Container $c) => new TenantService(
        $c->get(TenantRepository::class), $c->get(Encryption::class)
    ));
    $container->set(ManifestGenerator::class,  fn(Container $c) => new ManifestGenerator(
        (string) $c->get(Config::class)->get('base_url', '')
    ));

    // Phase 3 — Graph + signature pipeline
    $container->set(GuzzleHttp::class,         fn() => new GuzzleHttp(['http_errors' => true]));
    $container->set(TokenCache::class,         fn(Container $c) => new TokenCache($c->get(\PDO::class)));
    $container->set(GraphClient::class,        fn(Container $c) => new GraphClient(
        $c->get(GuzzleHttp::class),
        $c->get(TokenCache::class),
        $c->get(Encryption::class),
    ));
    $container->set(DisplayEmailDeriver::class,fn() => new DisplayEmailDeriver());
    $container->set(SignatureService::class,   fn(Container $c) => new SignatureService(
        $c->get(TenantRepository::class),
        $c->get(TenantService::class),
        $c->get(TemplateRepository::class),
        $c->get(RuleEngine::class),
        $c->get(TemplateRenderer::class),
        $c->get(GraphClient::class),
        $c->get(DisplayEmailDeriver::class),
    ));

    AppFactory::setContainer($container);
    $app = AppFactory::create();

    $app->addBodyParsingMiddleware();
    $app->add(TwigMiddleware::createFromContainer($app, Twig::class));
    $app->add(TwigGlobalsMiddleware::class);
    $app->add(CsrfMiddleware::class);
    $app->add(SessionStartMiddleware::class);
    $app->addRoutingMiddleware();
    $app->addErrorMiddleware(true, true, true);

    (require $rootDir . '/src/routes.php')($app);

    return $app;
})();
