<?php

declare(strict_types=1);

namespace App\Controller;

use App\Auth\TokenManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Target of nginx "auth_request" for the package files. Answers 204 when the
 * request carries a valid Composer access token (Authorization: Bearer) or a
 * logged-in admin session, 401 otherwise. Basic auth is handled by nginx.
 */
final class SessionCheckController extends AbstractController
{
    public function __construct(private readonly TokenManager $tokens)
    {
    }

    #[Route('/_auth/session', name: 'app_session_check', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $ok = false;
        $authorization = (string) $request->headers->get('Authorization', '');
        if (0 === stripos($authorization, 'bearer ')) {
            $ok = null !== $this->tokens->verify(substr($authorization, 7));
        } else {
            $ok = $this->isGranted('ROLE_ADMIN');
        }

        $response = new Response('', $ok ? 204 : 401);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
