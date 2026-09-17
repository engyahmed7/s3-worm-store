<?php

namespace Tests\Unit\Services;

use App\Exceptions\ObjectAlreadyWrittenException;
use App\Exceptions\ObjectLockedException;
use App\Exceptions\ObjectNotFoundException;
use App\Services\WormArchive;
use Aws\Command;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class WormArchiveTest extends TestCase
{
    public function test_write_once_stores_an_object_with_retention_lock(): void
    {
        $this->travelTo('2026-09-17 12:00:00');

        $client = $this->readyClient();
        $client->shouldReceive('headObject')->once()->andThrow($this->notFound('HeadObject'));
        $client->shouldReceive('putObject')
            ->once()
            ->with(Mockery::on(function (array $arguments): bool {
                return $arguments['Bucket'] === 'worm-archive'
                    && $arguments['Key'] === 'docs/invoice.txt'
                    && $arguments['Body'] === 'original payload'
                    && $arguments['ContentType'] === 'text/plain; charset=UTF-8'
                    && $arguments['ObjectLockMode'] === 'GOVERNANCE'
                    && $arguments['ObjectLockRetainUntilDate']->equalTo(now()->addDay());
            }))
            ->andReturn(new Result([
                'VersionId' => 'v1',
                'ETag' => '"abc123"',
            ]));

        $object = $this->archive($client)->writeOnce('docs/invoice.txt', 'original payload');

        $this->assertSame('docs/invoice.txt', $object->key);
        $this->assertSame('original payload', $object->contents);
        $this->assertSame('v1', $object->versionId);
        $this->assertSame('abc123', $object->etag);
        $this->assertSame('GOVERNANCE', $object->lockMode);
        $this->assertSame('2026-09-18T12:00:00+00:00', $object->retainUntil);
        $this->assertSame('text/plain; charset=UTF-8', $object->contentType);
    }

    public function test_write_once_stores_the_uploaded_content_type(): void
    {
        $client = $this->readyClient();
        $client->shouldReceive('headObject')->once()->andThrow($this->notFound('HeadObject'));
        $client->shouldReceive('putObject')
            ->once()
            ->with(Mockery::on(function (array $arguments): bool {
                return $arguments['Key'] === 'uploads/invoice.pdf'
                    && $arguments['ContentType'] === 'application/pdf'
                    && $arguments['Body'] === '%PDF';
            }))
            ->andReturn(new Result([
                'VersionId' => 'v1',
                'ETag' => '"abc123"',
            ]));

        $object = $this->archive($client)->writeOnce('uploads/invoice.pdf', '%PDF', 'application/pdf');

        $this->assertSame('application/pdf', $object->contentType);
    }

    public function test_write_once_rejects_an_existing_key(): void
    {
        $client = $this->readyClient();
        $client->shouldReceive('headObject')->once()->andReturn(new Result(['VersionId' => 'v1']));

        $this->expectException(ObjectAlreadyWrittenException::class);

        $this->archive($client)->writeOnce('docs/invoice.txt', 'tampered');
    }

    public function test_read_returns_stored_contents(): void
    {
        $client = $this->readyClient();
        $client->shouldReceive('getObject')
            ->once()
            ->with([
                'Bucket' => 'worm-archive',
                'Key' => 'docs/invoice.txt',
            ])
            ->andReturn(new Result([
                'Body' => 'original payload',
                'VersionId' => 'v1',
                'ETag' => '"abc123"',
                'ObjectLockMode' => 'GOVERNANCE',
                'ObjectLockRetainUntilDate' => '2026-09-18T12:00:00+00:00',
                'ContentType' => 'text/plain; charset=UTF-8',
            ]));

        $object = $this->archive($client)->read('docs/invoice.txt');

        $this->assertSame('original payload', $object->contents);
        $this->assertSame('v1', $object->versionId);
        $this->assertSame('GOVERNANCE', $object->lockMode);
        $this->assertSame('text/plain; charset=UTF-8', $object->contentType);
    }

    public function test_read_rejects_a_missing_key(): void
    {
        $client = $this->readyClient();
        $client->shouldReceive('getObject')->once()->andThrow($this->notFound('GetObject'));

        $this->expectException(ObjectNotFoundException::class);

        $this->archive($client)->read('missing.txt');
    }

    public function test_delete_is_blocked_when_object_lock_denies_it(): void
    {
        $client = $this->readyClient();
        $client->shouldReceive('getObject')->once()->andReturn(new Result([
            'Body' => 'original payload',
            'VersionId' => 'v1',
            'ETag' => '"abc123"',
            'ObjectLockMode' => 'GOVERNANCE',
            'ObjectLockRetainUntilDate' => '2026-09-18T12:00:00+00:00',
        ]));
        $client->shouldReceive('deleteObject')
            ->once()
            ->with([
                'Bucket' => 'worm-archive',
                'Key' => 'docs/invoice.txt',
                'VersionId' => 'v1',
            ])
            ->andThrow(new S3Exception(
                'Access Denied',
                new Command('DeleteObject'),
                ['code' => 'AccessDenied', 'statusCode' => 403, 'message' => 'Access Denied.'],
            ));

        $this->expectException(ObjectLockedException::class);

        $this->archive($client)->delete('docs/invoice.txt');
    }

    public function test_list_returns_object_keys_from_the_bucket(): void
    {
        $client = $this->readyClient();
        $client->shouldReceive('listObjectsV2')
            ->once()
            ->with(['Bucket' => 'worm-archive'])
            ->andReturn(new Result([
                'Contents' => [
                    [
                        'Key' => 'uploads/invoice.pdf',
                        'Size' => 2048,
                        'LastModified' => new \DateTimeImmutable('2026-09-17 12:00:00', new \DateTimeZone('UTC')),
                    ],
                ],
            ]));

        $files = $this->archive($client)->list();

        $this->assertSame('uploads/invoice.pdf', $files[0]['key']);
        $this->assertSame(2048, $files[0]['size']);
        $this->assertSame('2026-09-17 12:00', $files[0]['lastModified']);
    }

    private function archive(S3Client $client): WormArchive
    {
        return new WormArchive($client, 'worm-archive', 'GOVERNANCE', 1);
    }

    private function readyClient(): MockInterface
    {
        $client = Mockery::mock(S3Client::class);
        $client->shouldReceive('doesBucketExist')->andReturn(true);

        return $client;
    }

    private function notFound(string $command): S3Exception
    {
        return new S3Exception('Not Found', new Command($command), [
            'code' => 'NotFound',
            'statusCode' => 404,
        ]);
    }
}
