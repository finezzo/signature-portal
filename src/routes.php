<?php
declare(strict_types=1);

use App\Addin\SigController;
use App\Http\Middleware\AuthMiddleware;
use App\Portal\AccountController;
use App\Portal\AssetController;
use App\Portal\AuditController;
use App\Portal\AuthController;
use App\Portal\DashboardController;
use App\Portal\RuleController;
use App\Portal\SimulatorController;
use App\Portal\TemplateController;
use App\Portal\TenantController;
use App\Portal\UserController;
use App\Portal\UserOverrideController;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app): void {
    // Add-in API — public, key-authenticated, no session/CSRF.
    $app->get('/api/sig', [SigController::class, 'getSignature']);

    $app->get('/', fn(ServerRequestInterface $r, ResponseInterface $s) =>
        $s->withHeader('Location', '/portal/dashboard')->withStatus(302));

    $app->get('/portal', fn(ServerRequestInterface $r, ResponseInterface $s) =>
        $s->withHeader('Location', '/portal/dashboard')->withStatus(302));

    $app->get('/portal/login',  [AuthController::class, 'showLogin']);
    $app->post('/portal/login', [AuthController::class, 'doLogin']);

    // Entra SSO. Public — these establish the session before AuthMiddleware
    // would normally apply.
    $app->get('/portal/sso/{slug:[a-z0-9][a-z0-9-]*}/start',    [AuthController::class, 'ssoStart']);
    $app->get('/portal/sso/{slug:[a-z0-9][a-z0-9-]*}/callback', [AuthController::class, 'ssoCallback']);

    // Authenticated portal area.
    $app->group('/portal', function (RouteCollectorProxy $g): void {
        $g->post('/logout', [AuthController::class, 'logout']);

        $g->get('/account',  [AccountController::class, 'show']);
        $g->post('/account', [AccountController::class, 'update']);

        $g->get('/dashboard', [DashboardController::class, 'index']);

        $g->get('/users',                       [UserController::class, 'index']);
        $g->get('/users/new',                   [UserController::class, 'newForm']);
        $g->post('/users',                      [UserController::class, 'create']);
        $g->get('/users/{uid:[0-9]+}/edit',     [UserController::class, 'editForm']);
        $g->post('/users/{uid:[0-9]+}',         [UserController::class, 'update']);
        $g->post('/users/{uid:[0-9]+}/unlock',  [UserController::class, 'unlock']);
        $g->post('/users/{uid:[0-9]+}/delete',  [UserController::class, 'delete']);

        $g->get('/tenants',                 [TenantController::class, 'index']);
        $g->get('/tenants/new',             [TenantController::class, 'newForm']);
        $g->post('/tenants',                [TenantController::class, 'create']);
        $g->get('/tenants/{id:[0-9]+}',     [TenantController::class, 'show']);
        $g->get('/tenants/{id:[0-9]+}/edit',[TenantController::class, 'editForm']);
        $g->post('/tenants/{id:[0-9]+}',    [TenantController::class, 'update']);
        $g->post('/tenants/{id:[0-9]+}/delete',     [TenantController::class, 'delete']);
        $g->post('/tenants/{id:[0-9]+}/rotate-key',           [TenantController::class, 'rotateKey']);
        $g->post('/tenants/{id:[0-9]+}/acknowledge-api-key',  [TenantController::class, 'acknowledgeKey']);
        $g->get('/tenants/{id:[0-9]+}/manifest.xml',[TenantController::class, 'manifest']);

        $g->get('/tenants/{id:[0-9]+}/assets',                       [AssetController::class, 'index']);
        $g->post('/tenants/{id:[0-9]+}/assets',                      [AssetController::class, 'upload']);
        $g->post('/tenants/{id:[0-9]+}/assets/delete',               [AssetController::class, 'delete']);
        $g->post('/tenants/{id:[0-9]+}/assets/upload-json',          [AssetController::class, 'jsonUpload']);

        $g->get('/tenants/{id:[0-9]+}/templates',                       [TemplateController::class, 'index']);
        $g->get('/tenants/{id:[0-9]+}/templates/new',                   [TemplateController::class, 'newForm']);
        $g->post('/tenants/{id:[0-9]+}/templates',                      [TemplateController::class, 'create']);
        $g->get('/tenants/{id:[0-9]+}/templates/{tid:[0-9]+}/edit',     [TemplateController::class, 'editForm']);
        $g->post('/tenants/{id:[0-9]+}/templates/{tid:[0-9]+}',         [TemplateController::class, 'update']);
        $g->post('/tenants/{id:[0-9]+}/templates/{tid:[0-9]+}/delete',     [TemplateController::class, 'delete']);
        $g->post('/tenants/{id:[0-9]+}/templates/{tid:[0-9]+}/duplicate',  [TemplateController::class, 'duplicate']);
        $g->post('/tenants/{id:[0-9]+}/templates/preview',              [TemplateController::class, 'preview']);

        $g->get('/tenants/{id:[0-9]+}/rules',                       [RuleController::class, 'index']);
        $g->get('/tenants/{id:[0-9]+}/rules/new',                   [RuleController::class, 'newForm']);
        $g->post('/tenants/{id:[0-9]+}/rules',                      [RuleController::class, 'create']);
        $g->get('/tenants/{id:[0-9]+}/rules/{rid:[0-9]+}/edit',     [RuleController::class, 'editForm']);
        $g->post('/tenants/{id:[0-9]+}/rules/{rid:[0-9]+}',         [RuleController::class, 'update']);
        $g->post('/tenants/{id:[0-9]+}/rules/{rid:[0-9]+}/delete',  [RuleController::class, 'delete']);
        $g->post('/tenants/{id:[0-9]+}/rules/{rid:[0-9]+}/toggle',  [RuleController::class, 'toggle']);

        $g->get('/tenants/{id:[0-9]+}/overrides',                       [UserOverrideController::class, 'index']);
        $g->get('/tenants/{id:[0-9]+}/overrides/new',                   [UserOverrideController::class, 'newForm']);
        $g->post('/tenants/{id:[0-9]+}/overrides',                      [UserOverrideController::class, 'create']);
        $g->get('/tenants/{id:[0-9]+}/overrides/{oid:[0-9]+}/edit',     [UserOverrideController::class, 'editForm']);
        $g->post('/tenants/{id:[0-9]+}/overrides/{oid:[0-9]+}',         [UserOverrideController::class, 'update']);
        $g->post('/tenants/{id:[0-9]+}/overrides/{oid:[0-9]+}/delete',  [UserOverrideController::class, 'delete']);

        $g->get('/tenants/{id:[0-9]+}/activity',   [AuditController::class, 'tenantActivity']);

        $g->get('/tenants/{id:[0-9]+}/simulator',  [SimulatorController::class, 'show']);
        $g->post('/tenants/{id:[0-9]+}/simulator', [SimulatorController::class, 'run']);
    })->add(AuthMiddleware::class);
};
