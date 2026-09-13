<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class JsonExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [new TwigFilter('json_decode_safe', $this->decode(...))];
    }

    public function decode(string $json): mixed
    {
        try {
            return json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }
}
