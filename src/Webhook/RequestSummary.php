<?php

declare(strict_types=1);

namespace App\Webhook;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Builds the log entry for one webhook request.
 */
final class RequestSummary
{
    /**
     * @param list<string> $candidates
     * @param list<string> $matched
     *
     * @return array<string, mixed>
     */
    public static function build(Request $request, JsonResponse $response, string $message, array $candidates, array $matched, bool $authorized): array
    {
        $body = $request->getContent();
        $truncated = strlen($body) > WebhookLog::MAX_PAYLOAD_BYTES;
        if ($truncated) {
            $body = substr($body, 0, WebhookLog::MAX_PAYLOAD_BYTES);
        }
        [$provider, $event] = self::provider($request);
        $responseBody = json_decode((string) $response->getContent(), true);

        $headers = [];
        foreach (['content-type', 'user-agent', 'x-github-event', 'x-github-delivery', 'x-gitlab-event', 'x-gitea-event', 'x-event-key', 'x-request-uuid', 'x-hook-uuid'] as $name) {
            if ($request->headers->has($name)) {
                $headers[$name] = (string) $request->headers->get($name);
            }
        }

        return [
            'received_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'ip' => $request->getClientIp(),
            'provider' => $provider,
            'event' => $event,
            'query' => $request->getQueryString(),
            'headers' => $headers,
            'authorized' => $authorized,
            'status' => $response->getStatusCode(),
            'message' => $message,
            'response' => is_array($responseBody) ? $responseBody : (string) $response->getContent(),
            'candidates' => $authorized ? $candidates : [],
            'matched' => $matched,
            'payload' => $authorized ? $body : '',
            'payload_bytes' => strlen($request->getContent()),
            'payload_truncated' => $truncated,
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function provider(Request $request): array
    {
        $h = $request->headers;
        if ($h->has('x-github-event')) {
            return ['github', (string) $h->get('x-github-event')];
        }
        if ($h->has('x-gitea-event')) {
            return ['gitea', (string) $h->get('x-gitea-event')];
        }
        if ($h->has('x-gitlab-event')) {
            return ['gitlab', (string) $h->get('x-gitlab-event')];
        }
        if ($h->has('x-event-key')) {
            return ['bitbucket', (string) $h->get('x-event-key')];
        }
        $ua = strtolower((string) $h->get('user-agent', ''));
        if (str_contains($ua, 'vsservices') || str_contains($ua, 'azure')) {
            return ['azure-devops', ''];
        }
        if (str_starts_with($ua, 'curl/')) {
            return ['manual', ''];
        }

        return ['unknown', ''];
    }
}
