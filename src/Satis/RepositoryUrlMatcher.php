<?php

declare(strict_types=1);

namespace App\Satis;

/**
 * Matches repository URLs from webhook payloads against the URLs configured
 * in satis.json, ignoring scheme, credentials, ".git" suffix and case.
 */
final class RepositoryUrlMatcher
{
    /**
     * @param list<array<string, mixed>> $repositories satis.json repositories
     * @param list<string>               $candidates   URLs found in the payload
     *
     * @return list<string> configured URLs (verbatim, as satis expects them)
     */
    public function match(array $repositories, array $candidates): array
    {
        $wanted = [];
        foreach ($candidates as $candidate) {
            $normalized = self::normalize($candidate);
            if ('' !== $normalized) {
                $wanted[$normalized] = true;
            }
        }

        $matched = [];
        foreach ($repositories as $repository) {
            $url = $repository['url'] ?? null;
            if (!is_string($url) || '' === $url) {
                continue;
            }
            if (isset($wanted[self::normalize($url)])) {
                $matched[$url] = true;
            }
        }

        return array_keys($matched);
    }

    public static function normalize(string $url): string
    {
        $u = strtolower(trim($url));
        $u = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $u); // scheme
        $u = (string) preg_replace('#^[^/@]+@#', '', $u);              // user[:pass]@
        $u = (string) preg_replace('#^([^/:]+):(?!\d+(/|$))#', '$1/', $u); // scp-like host:path
        $u = (string) preg_replace('#^([^/:]+):\d+/#', '$1/', $u);     // host:port/
        $u = rtrim($u, '/');
        $u = (string) preg_replace('#\.git$#', '', $u);

        return rtrim($u, '/');
    }
}
