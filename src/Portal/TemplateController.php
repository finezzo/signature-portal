<?php
declare(strict_types=1);

namespace App\Portal;

use App\Auth\SessionManager;
use App\Tenant\Template;
use App\Tenant\TemplateRenderer;
use App\Tenant\TemplateRepository;
use App\Tenant\TenantRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Views\Twig;

final class TemplateController
{
    public function __construct(
        private readonly Twig $view,
        private readonly TenantRepository $tenants,
        private readonly TemplateRepository $templates,
        private readonly TemplateRenderer $renderer,
        private readonly SessionManager $session,
    ) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [$tenant, $err] = $this->resolveTenant($request, $args);
        if ($err) return $err;

        return $this->view->render($response, 'portal/templates/index.twig', [
            'tenant'    => $tenant,
            'templates' => $this->templates->listForTenant($tenant->id),
        ]);
    }

    public function newForm(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [$tenant, $err] = $this->resolveTenant($request, $args);
        if ($err) return $err;

        return $this->view->render($response, 'portal/templates/form.twig', [
            'tenant'   => $tenant,
            'template' => null,
            'form'     => ['name' => '', 'html' => self::starterHtml()],
            'errors'   => [],
        ]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [$tenant, $err] = $this->resolveTenant($request, $args);
        if ($err) return $err;

        $body = (array) $request->getParsedBody();
        $form = [
            'name' => trim((string) ($body['name'] ?? '')),
            'html' => (string) ($body['html'] ?? ''),
        ];
        $errors = $this->validate($form);
        if ($errors !== []) {
            return $this->view->render($response, 'portal/templates/form.twig', [
                'tenant' => $tenant, 'template' => null, 'form' => $form, 'errors' => $errors,
            ]);
        }

        $sanitized = $this->renderer->sanitize($form['html']);
        $id = $this->templates->create($tenant->id, $form['name'], $sanitized);

        $this->session->flash('success', 'Template created.');
        return $response->withHeader('Location', "/portal/tenants/{$tenant->id}/templates/{$id}/edit")->withStatus(302);
    }

    public function editForm(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [$tenant, $err] = $this->resolveTenant($request, $args);
        if ($err) return $err;
        $template = $this->templates->find((int) $args['tid'], $tenant->id);
        if ($template === null) return $this->notFound($response);

        return $this->view->render($response, 'portal/templates/form.twig', [
            'tenant'   => $tenant,
            'template' => $template,
            'form'     => ['name' => $template->name, 'html' => $template->html],
            'errors'   => [],
        ]);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [$tenant, $err] = $this->resolveTenant($request, $args);
        if ($err) return $err;
        $template = $this->templates->find((int) $args['tid'], $tenant->id);
        if ($template === null) return $this->notFound($response);

        $body = (array) $request->getParsedBody();
        $form = [
            'name' => trim((string) ($body['name'] ?? '')),
            'html' => (string) ($body['html'] ?? ''),
        ];
        $errors = $this->validate($form);
        if ($errors !== []) {
            return $this->view->render($response, 'portal/templates/form.twig', [
                'tenant' => $tenant, 'template' => $template, 'form' => $form, 'errors' => $errors,
            ]);
        }

        $sanitized = $this->renderer->sanitize($form['html']);
        $this->templates->update($template->id, $tenant->id, $form['name'], $sanitized);

        $this->session->flash('success', 'Template saved.');
        return $response->withHeader('Location', "/portal/tenants/{$tenant->id}/templates/{$template->id}/edit")->withStatus(302);
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [$tenant, $err] = $this->resolveTenant($request, $args);
        if ($err) return $err;
        $this->templates->delete((int) $args['tid'], $tenant->id);
        $this->session->flash('success', 'Template deleted.');
        return $response->withHeader('Location', "/portal/tenants/{$tenant->id}/templates")->withStatus(302);
    }

    /** Live preview endpoint — sanitizes the posted HTML and renders with sample data. */
    public function preview(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [$tenant, $err] = $this->resolveTenant($request, $args);
        if ($err) return $err;

        $body = (array) $request->getParsedBody();
        $html = (string) ($body['html'] ?? '');
        $rendered = $this->renderer->render(
            $this->renderer->sanitize($html),
            TemplateRenderer::sampleTokens(),
        );

        $response->getBody()->write($rendered);
        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    // ---------- helpers ----------

    /** @return array{0:\App\Tenant\Tenant|null, 1:?ResponseInterface} */
    private function resolveTenant(ServerRequestInterface $request, array $args): array
    {
        $id     = (int) ($args['id'] ?? 0);
        $tenant = $id > 0 ? $this->tenants->find($id) : null;
        if ($tenant === null) {
            return [null, $this->notFound((new ResponseFactory())->createResponse())];
        }
        $user = (array) $request->getAttribute('current_user');
        if (!AccessControl::canAccessTenant($user, $tenant->id)) {
            return [null, $this->forbidden((new ResponseFactory())->createResponse())];
        }
        return [$tenant, null];
    }

    private function notFound(ResponseInterface $r): ResponseInterface { $r = $r->withStatus(404); $r->getBody()->write('Not found.'); return $r; }
    private function forbidden(ResponseInterface $r): ResponseInterface { $r = $r->withStatus(403); $r->getBody()->write('Forbidden.'); return $r; }

    /** @param array<string,string> $form */
    private function validate(array $form): array
    {
        $errors = [];
        if ($form['name'] === '') {
            $errors[] = 'Name is required.';
        }
        if (trim($form['html']) === '') {
            $errors[] = 'Template body cannot be empty.';
        }
        return $errors;
    }

    private static function starterHtml(): string
    {
        return <<<HTML
<table cellpadding="0" cellspacing="0" border="0" style="font-family:Arial,sans-serif;font-size:13px;color:#333;">
  <tr>
    <td style="padding-right:12px;border-right:2px solid #2f6feb;">
      <strong style="font-size:15px;">{display_name}</strong><br>
      <span style="color:#6a737d;">{job_title_line}</span>
    </td>
    <td style="padding-left:12px;">
      {phone_lines}<br>
      <a href="mailto:{email}" style="color:#2f6feb;">{email}</a>
    </td>
  </tr>
</table>
HTML;
    }
}
