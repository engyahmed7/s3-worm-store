<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;

class ObjectAlreadyWrittenException extends Exception implements ShouldntReport
{
    public function __construct(public string $key)
    {
        parent::__construct("Object [{$key}] has already been written and cannot be replaced.");
    }

    /**
     * @return array<string, string>
     */
    public function context(): array
    {
        return ['key' => $this->key];
    }
}
