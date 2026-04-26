<?php
declare(strict_types=1);

namespace App\Portal;

use App\Auth\Authenticator;
use App\Auth\SessionManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

final class AuthController
{
    public function __construct(
        private readonly Twig $view,
        private readonly Authenticator $auth,
        private readonly SessionManager $session,
    ) {}

    public function showLogin(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($this->session->isAuthenticated()) {
            return $response->withHeader('Location', '/portal/dashboard')->withStatus(302);
        }

        $flash = $this->session->get('flash_login_error');
        $this->session->forget('flash_login_error');

        return $this->view->render($response, 'portal/login.twig', [
            'error' => $flash,
        ]);
    }

    public function doLogin(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body  = (array) $request->getParsedBody();
        $email = isset($body['email']) ? trim((string) $body['email']) : '';
        $pass  = isset($body['password']) ? (string) $body['password'] : '';

        if ($email === '' || $pass === '' || !$this->auth->attempt($email, $pass)) {
            $this->session->set('flash_login_error', 'Invalid email or password.');
            return $response->withHeader('Location', '/portal/login')->withStatus(302);
        }

        return $response->withHeader('Location', '/portal/dashboard')->withStatus(302);
    }

    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->auth->logout();
        return $response->withHeader('Location', '/portal/login')->withStatus(302);
    }
}
