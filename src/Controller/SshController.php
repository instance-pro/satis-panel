<?php

declare(strict_types=1);

namespace App\Controller;

use App\Form\KnownHostType;
use App\Form\SshGenerateType;
use App\Form\SshImportType;
use App\Ssh\SshKeyManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/ssh')]
final class SshController extends AbstractController
{
    public function __construct(private readonly SshKeyManager $ssh)
    {
    }

    #[Route('', name: 'app_ssh', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $generateForm = $this->createForm(SshGenerateType::class);
        $importForm = $this->createForm(SshImportType::class);
        $knownHostForm = $this->createForm(KnownHostType::class);

        $generateForm->handleRequest($request);
        if ($generateForm->isSubmitted() && $generateForm->isValid()) {
            /** @var array{type: string, comment: ?string} $data */
            $data = $generateForm->getData();
            try {
                $this->ssh->generate($data['type'], (string) ($data['comment'] ?? ''));
                $this->addFlash('success', 'SSH key generated. Add the public key to your git hosting as a read-only deploy key.');
            } catch (\RuntimeException|\InvalidArgumentException $e) {
                $this->addFlash('error', $e->getMessage());
            }

            return $this->redirectToRoute('app_ssh');
        }

        $importForm->handleRequest($request);
        if ($importForm->isSubmitted() && $importForm->isValid()) {
            /** @var array{privateKey: string} $data */
            $data = $importForm->getData();
            try {
                $this->ssh->import($data['privateKey']);
                $this->addFlash('success', 'SSH key imported.');

                return $this->redirectToRoute('app_ssh');
            } catch (\RuntimeException|\InvalidArgumentException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        $knownHostForm->handleRequest($request);
        if ($knownHostForm->isSubmitted() && $knownHostForm->isValid()) {
            /** @var array{host: string, port: int} $data */
            $data = $knownHostForm->getData();
            try {
                $this->ssh->addKnownHost($data['host'], (int) $data['port']);
                $this->addFlash('success', sprintf('Host key of %s added.', $data['host']));

                return $this->redirectToRoute('app_ssh');
            } catch (\RuntimeException|\InvalidArgumentException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render('ssh/index.html.twig', [
            'has_key' => $this->ssh->hasKey(),
            'public_key' => $this->ssh->publicKey(),
            'fingerprint' => $this->ssh->fingerprint(),
            'key_path' => $this->ssh->privateKeyPath(),
            'known_hosts' => $this->ssh->knownHosts(),
            'known_hosts_path' => $this->ssh->knownHostsPath(),
            'generate_form' => $generateForm,
            'import_form' => $importForm,
            'known_host_form' => $knownHostForm,
        ]);
    }

    #[Route('/delete', name: 'app_ssh_delete', methods: ['POST'])]
    public function delete(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delete-ssh-key', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $this->ssh->delete();
        $this->addFlash('success', 'SSH key deleted.');

        return $this->redirectToRoute('app_ssh');
    }

    #[Route('/known-hosts/delete', name: 'app_ssh_known_host_delete', methods: ['POST'])]
    public function deleteKnownHost(Request $request): Response
    {
        $host = (string) $request->request->get('host');
        if (!$this->isCsrfTokenValid('delete-known-host-'.$host, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $this->ssh->removeKnownHost(trim($host, '[]'));
        $this->addFlash('success', sprintf('Host key of %s removed.', $host));

        return $this->redirectToRoute('app_ssh');
    }
}
