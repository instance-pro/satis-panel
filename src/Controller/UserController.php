<?php

declare(strict_types=1);

namespace App\Controller;

use App\Auth\HtpasswdManager;
use App\Form\HtpasswdUserType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/users')]
final class UserController extends AbstractController
{
    public function __construct(
        private readonly HtpasswdManager $htpasswd,
        private readonly bool $satisAuthDisabled,
    ) {
    }

    #[Route('', name: 'app_users', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $form = $this->createForm(HtpasswdUserType::class);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{username: string, password: string} $data */
            $data = $form->getData();
            try {
                $existed = $this->htpasswd->has($data['username']);
                $this->htpasswd->set($data['username'], $data['password']);
                $this->addFlash('success', sprintf('User "%s" %s.', $data['username'], $existed ? 'updated' : 'added'));

                return $this->redirectToRoute('app_users');
            } catch (\RuntimeException|\InvalidArgumentException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render('user/index.html.twig', [
            'users' => $this->htpasswd->users(),
            'form' => $form,
            'htpasswd_path' => $this->htpasswd->path(),
            'auth_disabled' => $this->satisAuthDisabled,
        ]);
    }

    #[Route('/{username}/delete', name: 'app_user_delete', methods: ['POST'])]
    public function delete(string $username, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delete-user-'.$username, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $this->htpasswd->remove($username);
        $this->addFlash('success', sprintf('User "%s" removed.', $username));

        return $this->redirectToRoute('app_users');
    }
}
