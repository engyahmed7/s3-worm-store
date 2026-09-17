<?php

namespace App\Http\Controllers;

use App\Exceptions\ObjectAlreadyWrittenException;
use App\Exceptions\ObjectLockedException;
use App\Exceptions\ObjectNotFoundException;
use App\Services\WormArchive;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\File;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class WormDemoController extends Controller
{
    public function index(WormArchive $archive): View
    {
        $reachable = $archive->isReachable();

        return view('demo', [
            'reachable' => $reachable,
            'bucket' => $archive->bucket(),
            'endpoint' => config('filesystems.disks.minio.endpoint'),
            'lockMode' => $archive->lockMode(),
            'retentionDays' => $archive->retentionDays(),
            'maxUploadKilobytes' => $this->maxUploadKilobytes(),
            'allowedMimes' => config('worm.allowed_mimes'),
            'files' => $reachable ? $archive->list() : [],
        ]);
    }

    public function store(Request $request, WormArchive $archive): RedirectResponse
    {
        $file = $request->file('file');

        if ($file instanceof UploadedFile && ! $file->isValid()) {
            Log::warning('PHP rejected the upload before it reached MinIO.', [
                'error' => $file->getError(),
                'message' => $file->getErrorMessage(),
                'client_name' => $file->getClientOriginalName(),
                'upload_max_filesize' => ini_get('upload_max_filesize'),
                'post_max_size' => ini_get('post_max_size'),
            ]);
        }

        $request->validate([
            'file' => ['required', File::types(config('worm.allowed_mimes'))->max($this->maxUploadKilobytes())],
        ], [
            'file.uploaded' => 'PHP rejected this file before it reached MinIO. upload_max_filesize is '.ini_get('upload_max_filesize').' and post_max_size is '.ini_get('post_max_size').'. Use a smaller file, or raise those php.ini values.',
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('file');

        $key = $this->keyForUpload($file);
        $contentType = $file->getMimeType() ?: 'application/octet-stream';

        try {
            $object = $archive->writeOnce($key, $file->get(), $contentType);
            Log::info("Uploaded [{$object->key}] once. Version ".($object->versionId ?? 'n/a').'. Locked until '.$object->retainUntil.'.');
        } catch (ObjectAlreadyWrittenException $exception) {
            Log::warning('Object already written: '.$exception->getMessage());

            return back()->withErrors(['file' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            Log::error('Error writing object: '.$exception->getMessage(), [
                'exception' => $exception,
            ]);

            return back()->withErrors(['file' => $this->storageError()]);
        }

        return back()->with('status', "Uploaded [{$object->key}] once. Version ".($object->versionId ?? 'n/a').'. Locked until '.$object->retainUntil.'.');
    }

    public function download(Request $request, WormArchive $archive): StreamedResponse|RedirectResponse
    {
        $data = $request->validate([
            'key' => $this->keyRules(),
        ]);

        try {
            $object = $archive->read($data['key']);
        } catch (ObjectNotFoundException $exception) {
            return back()->withErrors(['file' => $exception->getMessage()]);
        } catch (Throwable) {
            return back()->withErrors(['file' => $this->storageError()]);
        }

        return response()->streamDownload(
            function () use ($object): void {
                echo $object->contents;
            },
            basename($object->key),
            [
                'Content-Type' => $object->contentType,
            ],
        );
    }

    public function destroy(Request $request, WormArchive $archive): RedirectResponse
    {
        $data = $request->validate([
            'key' => $this->keyRules(),
        ]);

        try {
            $archive->delete($data['key']);
        } catch (ObjectNotFoundException $exception) {
            return back()->withErrors(['file' => $exception->getMessage()]);
        } catch (ObjectLockedException $exception) {
            return back()->withErrors(['file' => 'Delete blocked by WORM lock: '.$exception->getMessage()]);
        } catch (Throwable) {
            return back()->withErrors(['file' => $this->storageError()]);
        }

        return back()->with('status', "Deleted [{$data['key']}]. This should not happen while the object is locked.");
    }

    private function storageError(): string
    {
        return 'MinIO request failed. Start it with `docker compose up -d` if it is not running.';
    }

    /**
     * @return list<string>
     */
    private function keyRules(): array
    {
        return ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*(?:\/[A-Za-z0-9][A-Za-z0-9._-]*)*$/'];
    }

    private function keyForUpload(UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $name = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));

        if ($name === '') {
            $name = 'file';
        }

        return 'uploads/'.$name.'.'.$extension;
    }

    private function maxUploadKilobytes(): int
    {
        return min(
            (int) config('worm.upload_max_kilobytes'),
            $this->iniKilobytes('upload_max_filesize'),
            $this->iniKilobytes('post_max_size'),
        );
    }

    private function iniKilobytes(string $directive): int
    {
        $value = strtolower(trim((string) ini_get($directive)));

        if ($value === '' || $value === '-1') {
            return PHP_INT_MAX;
        }

        $unit = $value[-1];
        $number = (float) $value;

        $bytes = match ($unit) {
            'g' => (int) round($number * 1024 * 1024 * 1024),
            'm' => (int) round($number * 1024 * 1024),
            'k' => (int) round($number * 1024),
            default => (int) $number,
        };

        return max(1, intdiv($bytes, 1024));
    }
}
