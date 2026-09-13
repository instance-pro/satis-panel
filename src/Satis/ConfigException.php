<?php

declare(strict_types=1);

namespace App\Satis;

final class ConfigException extends \RuntimeException
{
    /**
     * @param list<string> $errors
     */
    public function __construct(string $message, public readonly array $errors = [])
    {
        parent::__construct($message);
    }
}
