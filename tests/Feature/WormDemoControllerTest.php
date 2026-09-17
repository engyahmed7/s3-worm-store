<?php

namespace Tests\Feature;

use App\Exceptions\ObjectAlreadyWrittenException;
use App\Exceptions\ObjectLockedException;
use App\Services\WormArchive;
use App\Services\WormObject;
use Illuminate\Http\UploadedFile;
use Mockery\MockInterface;
use Tests\TestCase;

class WormDemoControllerTest extends TestCase
{
    public function test_renders_the_worm_demo_page(): void
    {
        $this->mockArchive(function (MockInterface $archive): void {
            $archive->shouldReceive('isReachable')->once()->andReturn(true);
            $archive->shouldReceive('bucket')->once()->andReturn('worm-archive');
            $archive->shouldReceive('lockMode')->once()->andReturn('GOVERNANCE');
            $archive->shouldReceive('retentionDays')->once()->andReturn(1);
            $archive->shouldReceive('list')->once()->andReturn([]);
        });

        $this->get(route('demo.index'))
            ->assertOk()
            ->assertSee('Upload a file')
            ->assertSee('worm-archive');
    }

    public function test_index_lists_stored_files(): void
    {
        $this->mockArchive(function (MockInterface $archive): void {
            $archive->shouldReceive('isReachable')->once()->andReturn(true);
            $archive->shouldReceive('bucket')->once()->andReturn('worm-archive');
            $archive->shouldReceive('lockMode')->once()->andReturn('GOVERNANCE');
            $archive->shouldReceive('retentionDays')->once()->andReturn(1);
            $archive->shouldReceive('list')->once()->andReturn([
                [
                    'key' => 'uploads/invoice.pdf',
                    'size' => 2048,
                    'lastModified' => '2026-09-17 12:00',
                ],
            ]);
        });

        $this->get(route('demo.index'))
            ->assertOk()
            ->assertSee('uploads/invoice.pdf')
            ->assertSee('Download');
    }

    public function test_write_uploads_a_file_and_flashes_status(): void
    {
        $file = UploadedFile::fake()->create('My Invoice.PDF', 120, 'application/pdf');

        $this->mockArchive(function (MockInterface $archive) use ($file): void {
            $archive->shouldReceive('writeOnce')
                ->once()
                ->with('uploads/my-invoice.pdf', $file->get(), 'application/pdf')
                ->andReturn(new WormObject(
                    key: 'uploads/my-invoice.pdf',
                    contents: $file->get(),
                    versionId: 'v1',
                    etag: 'abc123',
                    lockMode: 'GOVERNANCE',
                    retainUntil: '2026-09-18T12:00:00+00:00',
                    contentType: 'application/pdf',
                ));
        });

        $this->from(route('demo.index'))
            ->post(route('demo.write'), [
                'file' => $file,
            ])
            ->assertRedirect(route('demo.index'))
            ->assertSessionHas('status', 'Uploaded [uploads/my-invoice.pdf] once. Version v1. Locked until 2026-09-18T12:00:00+00:00.');
    }

    public function test_write_rejects_an_existing_file_name(): void
    {
        $this->mockArchive(function (MockInterface $archive): void {
            $archive->shouldReceive('writeOnce')
                ->once()
                ->andThrow(new ObjectAlreadyWrittenException('uploads/invoice.pdf'));
        });

        $this->from(route('demo.index'))
            ->post(route('demo.write'), [
                'file' => UploadedFile::fake()->create('invoice.pdf', 120, 'application/pdf'),
            ])
            ->assertRedirect(route('demo.index'))
            ->assertSessionHasErrors(['file' => 'Object [uploads/invoice.pdf] has already been written and cannot be replaced.']);
    }

    public function test_write_rejects_a_missing_file(): void
    {
        $this->mock(WormArchive::class);

        $this->from(route('demo.index'))
            ->post(route('demo.write'))
            ->assertRedirect(route('demo.index'))
            ->assertSessionHasErrors(['file' => 'The file field is required.']);
    }

    public function test_write_rejects_a_disallowed_file_type(): void
    {
        $this->mock(WormArchive::class);

        $this->from(route('demo.index'))
            ->post(route('demo.write'), [
                'file' => UploadedFile::fake()->create('shell.php', 20, 'text/x-php'),
            ])
            ->assertRedirect(route('demo.index'))
            ->assertSessionHasErrors(['file' => 'The file field must be a file of type: pdf, png, jpg, jpeg, gif, webp, txt, csv, doc, docx, xls, xlsx, zip.']);
    }

    public function test_download_returns_the_stored_file(): void
    {
        $this->mockArchive(function (MockInterface $archive): void {
            $archive->shouldReceive('read')
                ->once()
                ->with('uploads/invoice.pdf')
                ->andReturn(new WormObject(
                    key: 'uploads/invoice.pdf',
                    contents: '%PDF-fake',
                    versionId: 'v1',
                    etag: 'abc123',
                    lockMode: 'GOVERNANCE',
                    retainUntil: '2026-09-18T12:00:00+00:00',
                    contentType: 'application/pdf',
                ));
        });

        $this->get(route('demo.download', ['key' => 'uploads/invoice.pdf']))
            ->assertOk()
            ->assertDownload('invoice.pdf')
            ->assertHeader('content-type', 'application/pdf')
            ->assertStreamedContent('%PDF-fake');
    }

    public function test_delete_reports_when_object_lock_blocks_it(): void
    {
        $this->mockArchive(function (MockInterface $archive): void {
            $archive->shouldReceive('delete')
                ->once()
                ->with('uploads/invoice.pdf')
                ->andThrow(new ObjectLockedException('uploads/invoice.pdf', 'Access Denied.'));
        });

        $this->from(route('demo.index'))
            ->post(route('demo.delete'), [
                'key' => 'uploads/invoice.pdf',
            ])
            ->assertRedirect(route('demo.index'))
            ->assertSessionHasErrors(['file' => 'Delete blocked by WORM lock: Access Denied.']);
    }

    /**
     * @param  callable(MockInterface): void  $setup
     */
    private function mockArchive(callable $setup): void
    {
        $this->mock(WormArchive::class, $setup);
    }
}
