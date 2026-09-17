<?php

namespace App\Services;

readonly class WormObject
{
    public function __construct(
        public string $key,
        public string $contents,
        public ?string $versionId,
        public ?string $etag,
        public string $lockMode,
        public string $retainUntil,
        public string $contentType = 'application/octet-stream',
    ) {}
}
