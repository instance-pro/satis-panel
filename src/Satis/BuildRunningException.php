<?php

declare(strict_types=1);

namespace App\Satis;

final class BuildRunningException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('A satis build is already running.');
    }
}
