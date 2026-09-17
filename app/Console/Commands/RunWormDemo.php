<?php

namespace App\Console\Commands;

use App\Exceptions\ObjectAlreadyWrittenException;
use App\Exceptions\ObjectLockedException;
use App\Services\WormArchive;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('worm:demo {key? : Object key to write} {--contents=WORM payload from artisan : Contents to store}')]
#[Description('Write, read, then try to overwrite and delete an object in MinIO WORM storage')]
class RunWormDemo extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(WormArchive $archive): int
    {
        if (! $archive->isReachable()) {
            $this->components->error('MinIO is not reachable at '.config('filesystems.disks.minio.endpoint'));
            $this->components->bulletList([
                'docker compose up -d',
                'Open the console at http://127.0.0.1:9001 (minioadmin / minioadmin)',
            ]);

            return self::FAILURE;
        }

        $key = $this->argument('key') ?: 'demo/invoice-'.now()->format('YmdHis').'.txt';
        $contents = (string) $this->option('contents');

        $this->components->info('MinIO WORM demo');
        $this->components->twoColumnDetail('Endpoint', (string) config('filesystems.disks.minio.endpoint'));
        $this->components->twoColumnDetail('Bucket', $archive->bucket());
        $this->components->twoColumnDetail('Lock mode', $archive->lockMode());
        $this->components->twoColumnDetail('Retention', $archive->retentionDays().' day(s)');
        $this->newLine();

        $archive->ensureReady();
        $this->components->info("Created or reused lock-enabled bucket [{$archive->bucket()}]");

        $written = $archive->writeOnce($key, $contents);
        $this->components->info("Wrote [{$written->key}] once");
        $this->components->twoColumnDetail('Version', $written->versionId ?? 'n/a');
        $this->components->twoColumnDetail('Retain until', $written->retainUntil);

        $read = $archive->read($key);
        $this->components->info('Read back: '.$read->contents);

        try {
            $archive->writeOnce($key, 'tampered payload');
            $this->components->error('Overwrite unexpectedly succeeded.');

            return self::FAILURE;
        } catch (ObjectAlreadyWrittenException $exception) {
            $this->components->warn('Overwrite blocked: '.$exception->getMessage());
        }

        try {
            $archive->delete($key);
            $this->components->error('Delete unexpectedly succeeded.');

            return self::FAILURE;
        } catch (ObjectLockedException $exception) {
            $this->components->warn('Delete blocked by object lock: '.$exception->getMessage());
        } catch (Throwable $exception) {
            $this->components->warn('Delete blocked: '.$exception->getMessage());
        }

        $stillThere = $archive->read($key);
        $this->components->info('Object still readable: '.$stillThere->contents);

        return self::SUCCESS;
    }
}
