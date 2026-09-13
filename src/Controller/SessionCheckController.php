<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Target of nginx "auth_request" for the package files: answers 204 when the
 * request carries a logged-in admin session, 401 otherwise. Together with
 * "satisfy any" an admin can open the package index without a Composer user.
 */
final class SessionCheckController extends AbstractController
{
    #[Route('/_auth/session', name: 'app_session_check', methods: ['GET'])]
    public function __invoke(): Response
    {
        $response = new Response('', $this->isGranted('ROLE_ADMIN') ? 204 : 401);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
