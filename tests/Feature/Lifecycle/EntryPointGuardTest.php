<?php

namespace Tests\Feature\Lifecycle;

use App\Exceptions\DocumentLocked;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use LogicException;
use Tests\Support\Lifecycle\CreatesLifecycleTables;
use Tests\Support\Lifecycle\TestDocument;
use Tests\Support\Lifecycle\TestDocumentLine;
use Tests\Support\Lifecycle\TestDocumentStatus;
use Tests\TestCase;

/**
 * Feature tests proving the guard holds over HTTP, queued jobs and console, and documenting its known gaps.
 */
class EntryPointGuardTest extends TestCase
{
    use CreatesLifecycleTables;

    /**
     * Guard holds over http.
     */
    public function test_guard_holds_over_http(): void
    {
        $document = $this->makeDocument(TestDocumentStatus::Confirmed);
        Route::put('/__test/documents/{id}', function (Request $request, int $id) {
            TestDocument::findOrFail($id)->update($request->only('supplier'));
        });

        $this->withoutExceptionHandling();
        $this->expectException(DocumentLocked::class);

        $this->putJson("/__test/documents/{$document->id}", ['supplier' => 'Other']);
    }

    /**
     * Guard holds in queued job.
     */
    public function test_guard_holds_in_queued_job(): void
    {
        $id = $this->makeDocument(TestDocumentStatus::Confirmed)->id;

        $this->expectException(DocumentLocked::class);

        dispatch(function () use ($id): void {
            TestDocument::findOrFail($id)->update(['supplier' => 'Other']);
        });
    }

    /**
     * Guard holds in console command.
     */
    public function test_guard_holds_in_console_command(): void
    {
        $id = $this->makeDocument(TestDocumentStatus::Confirmed)->id;
        Artisan::command('test:edit-locked {id}', function (int $id): void {
            TestDocument::findOrFail($id)->update(['supplier' => 'Other']);
        });

        $this->expectException(DocumentLocked::class);

        $this->artisan('test:edit-locked', ['id' => $id]);
    }

    /**
     * Documents a known limitation: query-builder updates never fire model events,
     * so the guard cannot see them. The convention is "no raw updates on documents".
     */
    public function test_raw_query_builder_update_bypasses_guard_known_gap(): void
    {
        $document = $this->makeDocument(TestDocumentStatus::Confirmed);

        TestDocument::query()->whereKey($document->id)->update(['supplier' => 'Other']);

        $this->assertSame('Other', $document->fresh()->supplier);
    }

    /**
     * Quiet writes are refused on documents.
     */
    public function test_quiet_writes_are_refused_on_documents(): void
    {
        $document = $this->makeDocument();

        foreach ([
            fn () => $document->saveQuietly(),
            fn () => $document->updateQuietly(['supplier' => 'Other']),
            fn () => $document->deleteQuietly(),
        ] as $write) {
            $this->assertRefused(LogicException::class, fn () => $write());
        }

        $this->assertSame('Acme', $document->fresh()->supplier);
    }

    /**
     * Quiet writes are refused on lines.
     */
    public function test_quiet_writes_are_refused_on_lines(): void
    {
        $line = TestDocumentLine::create(['test_document_id' => $this->makeDocument()->id, 'quantity' => 1, 'unit_price' => 1]);

        $this->expectException(LogicException::class);

        $line->updateQuietly(['quantity' => 5]);
    }

    /**
     * Documents a known limitation: wrapping a save in Model::withoutEvents() is a
     * deliberate opt-out of every model event, so the guard cannot see it.
     */
    public function test_without_events_bypasses_guard_known_gap(): void
    {
        $document = $this->makeDocument(TestDocumentStatus::Confirmed);

        TestDocument::withoutEvents(fn () => $document->update(['supplier' => 'Other']));

        $this->assertSame('Other', $document->fresh()->supplier);
    }
}
