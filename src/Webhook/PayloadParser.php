<?php

declare(strict_types=1);

namespace App\Webhook;

/**
 * Provider-agnostic extraction of repository URLs from a webhook payload.
 * Collects every string that looks like a git remote (GitHub, GitLab, Gitea,
 * Bitbucket Cloud/Server and Azure DevOps all include at least one).
 */
final class PayloadParser
{
    private const MAX_DEPTH = 12;

    /**
     * @return list<string>
     */
    public function extractUrls(mixed $payload): array
    {
        $urls = [];
        $this->walk($payload, $urls, 0);

        return array_values(array_unique($urls));
    }

    /**
     * @param list<string> $urls
     */
    private function walk(mixed $value, array &$urls, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            return;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->walk($item, $urls, $depth + 1);
            }

            return;
        }
        if (!is_string($value)) {
            return;
        }
        $value = trim($value);
        if ('' === $value || strlen($value) > 500 || str_contains($value, ' ')) {
            return;
        }
        if (preg_match('#^(https?://|ssh://|git://|git@|[a-z0-9._-]+@[a-z0-9.-]+:)#i', $value)) {
            $urls[] = $value;
        }
    }
}
