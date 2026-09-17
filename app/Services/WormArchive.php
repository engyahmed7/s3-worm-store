<?php

namespace App\Services;

use App\Exceptions\ObjectAlreadyWrittenException;
use App\Exceptions\ObjectLockedException;
use App\Exceptions\ObjectNotFoundException;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Throwable;

class WormArchive
{
    public function __construct(
        private S3Client $client,
        private string $bucket,
        private string $lockMode,
        private int $retentionDays,
    ) {}

    public function bucket(): string
    {
        return $this->bucket;
    }

    public function lockMode(): string
    {
        return $this->lockMode;
    }

    public function retentionDays(): int
    {
        return $this->retentionDays;
    }

    public function isReachable(): bool
    {
        try {
            $this->client->listBuckets();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function writeOnce(string $key, string $contents, string $contentType = 'text/plain; charset=UTF-8'): WormObject
    {
        $this->ensureReady();

        if ($this->objectExists($key)) {
            throw new ObjectAlreadyWrittenException($key);
        }

        $retainUntil = now()->addDays($this->retentionDays)->utc();

        $result = $this->client->putObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
            'Body' => $contents,
            'ContentType' => $contentType,
            'ObjectLockMode' => $this->lockMode,
            'ObjectLockRetainUntilDate' => $retainUntil,
        ]);

        return new WormObject(
            key: $key,
            contents: $contents,
            versionId: $result['VersionId'] ?? null,
            etag: isset($result['ETag']) ? trim((string) $result['ETag'], '"') : null,
            lockMode: $this->lockMode,
            retainUntil: $retainUntil->toIso8601String(),
            contentType: $contentType,
        );
    }

    public function read(string $key): WormObject
    {
        $this->ensureReady();

        try {
            $result = $this->client->getObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
            ]);
        } catch (S3Exception $exception) {
            if ($this->isMissing($exception)) {
                throw new ObjectNotFoundException($key);
            }

            throw $exception;
        }

        return new WormObject(
            key: $key,
            contents: (string) $result['Body'],
            versionId: $result['VersionId'] ?? null,
            etag: isset($result['ETag']) ? trim((string) $result['ETag'], '"') : null,
            lockMode: (string) ($result['ObjectLockMode'] ?? $this->lockMode),
            retainUntil: isset($result['ObjectLockRetainUntilDate'])
                ? (string) $result['ObjectLockRetainUntilDate']
                : now()->addDays($this->retentionDays)->utc()->toIso8601String(),
            contentType: (string) ($result['ContentType'] ?? 'application/octet-stream'),
        );
    }

    /**
     * @return list<array{key: string, size: int, lastModified: string}>
     */
    public function list(): array
    {
        $this->ensureReady();

        $result = $this->client->listObjectsV2([
            'Bucket' => $this->bucket,
        ]);

        $files = [];

        foreach ($result['Contents'] ?? [] as $item) {
            $lastModified = $item['LastModified'] ?? null;

            $files[] = [
                'key' => (string) $item['Key'],
                'size' => (int) ($item['Size'] ?? 0),
                'lastModified' => $lastModified instanceof \DateTimeInterface
                    ? $lastModified->format('Y-m-d H:i')
                    : (string) $lastModified,
            ];
        }

        return $files;
    }

    public function delete(string $key): void
    {
        $this->ensureReady();

        $object = $this->read($key);

        try {
            $this->client->deleteObject(array_filter([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'VersionId' => $object->versionId,
            ]));
        } catch (S3Exception $exception) {
            throw new ObjectLockedException(
                $key,
                $exception->getAwsErrorMessage() ?: $exception->getMessage(),
            );
        }
    }

    public function ensureReady(): void
    {
        if ($this->client->doesBucketExist($this->bucket)) {
            return;
        }

        $this->client->createBucket([
            'Bucket' => $this->bucket,
            'ObjectLockEnabledForBucket' => true,
        ]);

        $this->client->putObjectLockConfiguration([
            'Bucket' => $this->bucket,
            'ObjectLockConfiguration' => [
                'ObjectLockEnabled' => 'Enabled',
                'Rule' => [
                    'DefaultRetention' => [
                        'Mode' => $this->lockMode,
                        'Days' => $this->retentionDays,
                    ],
                ],
            ],
        ]);
    }

    private function objectExists(string $key): bool
    {
        try {
            $this->client->headObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
            ]);

            return true;
        } catch (S3Exception $exception) {
            if ($this->isMissing($exception)) {
                return false;
            }

            throw $exception;
        }
    }

    private function isMissing(S3Exception $exception): bool
    {
        return $exception->getStatusCode() === 404
            || in_array($exception->getAwsErrorCode(), ['NotFound', 'NoSuchKey', 'NoSuchBucket'], true);
    }
}
