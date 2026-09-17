<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;

class ObjectNotFoundException extends Exception implements ShouldntReport
{
    public function __construct(public string $key)
    {
        parent::__construct("Object [{$key}] was not found in the WORM archive.");
    }

    /**
     * @return array<string, string>
     */
    public function context(): array
    {
        return ['key' => $this->key];
    }
}
