<?php
declare(strict_types=1);

namespace App\Portal;

use App\Auth\PasswordResetService;
use App\Auth\SessionManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * Public "forgot password" pages. Both forms are CSRF-protected by the
 * global middleware. Every outcome of the request form shows the same
 * neutral message so the endpoint can't be used to probe which email
 * addresses have an account.
 */
final class PasswordResetController
{
    public function __construct(
        private readonly Twig $view,
        private readonly PasswordResetService $service,
        private readonly SessionManager $session,
    ) {}

    public function showForgot(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->view->render($response, 'portal/password_forgot.twig', ['sent' => false]);
    }

    public function doForgot(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body  = (array) $request->getParsedBody();
        $email = trim((string) ($body['email'] ?? ''));

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->service->request($email);
        }
        // Same response regardless of outcome — see class docblock.
        return $this->view->render($response, 'portal/password_forgot.twig', ['sent' => true]);
    }

    public function showReset(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $token = (string) ($request->getQueryParams()['token'] ?? '');
        $valid = $this->service->validateToken($token) !== null;

        return $this->view->render($response, 'portal/password_reset.twig', [
            'token'  => $token,
            'valid'  => $valid,
            'errors' => [],
        ]);
    }

    public function doReset(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body    = (array) $request->getParsedBody();
        $token   = (string) ($body['token'] ?? '');
        $pass    = (string) ($body['password'] ?? '');
        $confirm = (string) ($body['password_confirm'] ?? '');

        $errors = [];
        if (mb_strlen($pass) < 10) {
            $errors[] = 'Password must be at least 10 characters.';
        }
        if ($pass !== $confirm) {
            $errors[] = 'Password and confirmation do not match.';
        }

        if ($errors !== []) {
            return $this->view->render($response, 'portal/password_reset.twig', [
                'token'  => $token,
                'valid'  => $this->service->validateToken($token) !== null,
                'errors' => $errors,
            ]);
        }

        if (!$this->service->reset($token, $pass)) {
            return $this->view->render($response, 'portal/password_reset.twig', [
                'token'  => $token,
                'valid'  => false,
                'errors' => [],
            ]);
        }

        $this->session->set('flash_login_error', null);
        $this->session->flash('success', 'Password changed. You can sign in now.');
        return $response->withHeader('Location', '/portal/login')->withStatus(302);
    }
}
