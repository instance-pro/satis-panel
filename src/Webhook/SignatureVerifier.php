<?php

declare(strict_types=1);

namespace App\Webhook;

use Symfony\Component\HttpFoundation\Request;

/**
 * Verifies provider signatures of webhook requests:
 *   Bitbucket Cloud/Server  X-Hub-Signature: sha256=<hmac>
 *   GitHub                  X-Hub-Signature-256: sha256=<hmac> (X-Hub-Signature: sha1=<hmac> as legacy)
 *   Gitea                   X-Gitea-Signature: <hmac sha256>
 *   GitLab                  X-Gitlab-Token: <secret>
 */
final class SignatureVerifier
{
    public const VALID = 'valid';
    public const INVALID = 'invalid';
    public const MISSING = 'missing';

    public function verify(Request $request, #[\SensitiveParameter] string $secret): string
    {
        $body = $request->getContent();
        $headers = $request->headers;

        $checks = [];
        if ($headers->has('x-hub-signature-256')) {
            $checks[] = ['sha256', self::strip((string) $headers->get('x-hub-signature-256'), 'sha256=')];
        }
        if ($headers->has('x-hub-signature')) {
            $value = (string) $headers->get('x-hub-signature');
            $checks[] = str_starts_with($value, 'sha1=') ? ['sha1', self::strip($value, 'sha1=')] : ['sha256', self::strip($value, 'sha256=')];
        }
        if ($headers->has('x-gitea-signature')) {
            $checks[] = ['sha256', (string) $headers->get('x-gitea-signature')];
        }
        if ($headers->has('x-gitlab-token')) {
            return hash_equals($secret, (string) $headers->get('x-gitlab-token')) ? self::VALID : self::INVALID;
        }
        if ([] === $checks) {
            return self::MISSING;
        }

        foreach ($checks as [$algo, $given]) {
            if ('' !== $given && hash_equals(hash_hmac($algo, $body, $secret), strtolower(trim($given)))) {
                return self::VALID;
            }
        }

        return self::INVALID;
    }

    private static function strip(string $value, string $prefix): string
    {
        $value = trim($value);

        return str_starts_with($value, $prefix) ? substr($value, strlen($prefix)) : $value;
    }
}
