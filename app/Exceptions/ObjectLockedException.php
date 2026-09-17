<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;

class ObjectLockedException extends Exception implements ShouldntReport
{
    public function __construct(public string $key, string $message)
    {
        parent::__construct($message);
    }

    /**
     * @return array<string, string>
     */
    public function context(): array
    {
        return ['key' => $this->key];
    }
}
