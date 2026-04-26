<?php
declare(strict_types=1);

use App\Addin\SigController;
use App\Http\Middleware\AuthMiddleware;
use App\Portal\AuthController;
use App\Portal\DashboardController;
use App\Portal\RuleController;
use App\Portal\SimulatorController;
use App\Portal\TemplateController;
use App\Portal\TenantController;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app): void {
    // Add-in API — public, key-authenticated, no session/CSRF.
    $app->get('/api/sig', [SigController::class, 'getSignature']);

    $app->get('/', static fn(ServerRequestInterface $r, ResponseInterface $s) =>
        $s->withHeader('Location', '/portal/dashboard')->withStatus(302));

    $app->get('/portal', static fn(ServerRequestInterface $r, ResponseInterface $s) =>
        $s->withHeader('Location', '/portal/dashboard')->withStatus(302));

    $app->get('/portal/login',  [AuthController::class, 'showLogin']);
    $app->post('/portal/login', [AuthController::class, 'doLogin']);

    // Authenticated portal area.
    $app->group('/portal', function (RouteCollectorProxy $g): void {
        $g->post('/logout', [AuthController::class, 'logout']);

        $g->get('/dashboard', [DashboardController::class, 'index']);

        $g->get('/tenants',                 [TenantController::class, 'index']);
        $g->get('/tenants/new',             [TenantController::class, 'newForm']);
        $g->post('/tenants',                [TenantController::class, 'create']);
        $g->get('/tenants/{id:[0-9]+}',     [TenantController::class, 'show']);
        $g->get('/tenants/{id:[0-9]+}/edit',[TenantController::class, 'editForm']);
        $g->post('/tenants/{id:[0-9]+}',    [TenantController::class, 'update']);
        $g->post('/tenants/{id:[0-9]+}/delete',     [TenantController::class, 'delete']);
        $g->post('/tenants/{id:[0-9]+}/rotate-key', [TenantController::class, 'rotateKey']);
        $g->get('/tenants/{id:[0-9]+}/manifest.xml',[TenantController::class, 'manifest']);

        $g->get('/tenants/{id:[0-9]+}/templates',                       [TemplateController::class, 'index']);
        $g->get('/tenants/{id:[0-9]+}/templates/new',                   [TemplateController::class, 'newForm']);
        $g->post('/tenants/{id:[0-9]+}/templates',                      [TemplateController::class, 'create']);
        $g->get('/tenants/{id:[0-9]+}/templates/{tid:[0-9]+}/edit',     [TemplateController::class, 'editForm']);
        $g->post('/tenants/{id:[0-9]+}/templates/{tid:[0-9]+}',         [TemplateController::class, 'update']);
        $g->post('/tenants/{id:[0-9]+}/templates/{tid:[0-9]+}/delete',  [TemplateController::class, 'delete']);
        $g->post('/tenants/{id:[0-9]+}/templates/preview',              [TemplateController::class, 'preview']);

        $g->get('/tenants/{id:[0-9]+}/rules',                       [RuleController::class, 'index']);
        $g->get('/tenants/{id:[0-9]+}/rules/new',                   [RuleController::class, 'newForm']);
        $g->post('/tenants/{id:[0-9]+}/rules',                      [RuleController::class, 'create']);
        $g->get('/tenants/{id:[0-9]+}/rules/{rid:[0-9]+}/edit',     [RuleController::class, 'editForm']);
        $g->post('/tenants/{id:[0-9]+}/rules/{rid:[0-9]+}',         [RuleController::class, 'update']);
        $g->post('/tenants/{id:[0-9]+}/rules/{rid:[0-9]+}/delete',  [RuleController::class, 'delete']);

        $g->get('/tenants/{id:[0-9]+}/simulator',  [SimulatorController::class, 'show']);
        $g->post('/tenants/{id:[0-9]+}/simulator', [SimulatorController::class, 'run']);
    })->add(AuthMiddleware::class);
};
