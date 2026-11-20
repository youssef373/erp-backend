<?php

namespace Tests\Feature\Lifecycle;

use App\Exceptions\DocumentLocked;
use App\Services\LifecycleEngine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Tests\Support\Lifecycle\CreatesLifecycleTables;
use Tests\Support\Lifecycle\TestDocument;
use Tests\Support\Lifecycle\TestDocumentLine;
use Tests\Support\Lifecycle\TestDocumentStatus;
use Tests\TestCase;

/**
 * Feature tests for document lines following their parent document's lock.
 */
class LineLockTest extends TestCase
{
    use CreatesLifecycleTables;

    /**
     * Creates a line (quantity 2, price 50) on the given document.
     */
    private function makeLine(TestDocument $document): TestDocumentLine
    {
        return TestDocumentLine::create([
            'test_document_id' => $document->id,
            'quantity' => 2,
            'unit_price' => 50,
        ]);
    }

    /**
     * Moves the document to Pending approval, which locks it.
     */
    private function lock(TestDocument $document): void
    {
        app(LifecycleEngine::class)->transition($document, TestDocumentStatus::PendingApproval);
    }

    /**
     * Lines are editable on draft parent.
     */
    public function test_lines_are_editable_on_draft_parent(): void
    {
        $line = $this->makeLine($this->makeDocument());

        $line->update(['quantity' => 9, 'unit_price' => 70]);
        $line->delete();

        $this->assertModelMissing($line);
    }

    /**
     * Cannot edit quantity or price of line on locked parent.
     */
    public function test_cannot_edit_quantity_or_price_of_line_on_locked_parent(): void
    {
        $document = $this->makeDocument();
        $line = $this->makeLine($document);
        $this->lock($document);

        $this->assertRefused(DocumentLocked::class, fn () => $line->update(['quantity' => 9, 'unit_price' => 70]));

        $this->assertSame(2, $line->fresh()->quantity);
        $this->assertSame(50, $line->fresh()->unit_price);
    }

    /**
     * Cannot add line to locked parent.
     */
    public function test_cannot_add_line_to_locked_parent(): void
    {
        $document = $this->makeDocument();
        $this->lock($document);

        $this->expectException(DocumentLocked::class);

        $this->makeLine($document);
    }

    /**
     * Cannot delete line of locked parent.
     */
    public function test_cannot_delete_line_of_locked_parent(): void
    {
        $document = $this->makeDocument();
        $line = $this->makeLine($document);
        $this->lock($document);

        $this->expectException(DocumentLocked::class);

        $line->delete();
    }

    /**
     * Cannot move line from locked parent to draft parent.
     */
    public function test_cannot_move_line_from_locked_parent_to_draft_parent(): void
    {
        $locked = $this->makeDocument();
        $line = $this->makeLine($locked);
        $this->lock($locked);
        $draft = $this->makeDocument();

        $this->expectException(DocumentLocked::class);

        $line->update(['test_document_id' => $draft->id]);
    }

    /**
     * Cannot move line from draft parent to locked parent.
     */
    public function test_cannot_move_line_from_draft_parent_to_locked_parent(): void
    {
        $draft = $this->makeDocument();
        $line = $this->makeLine($draft);
        $locked = $this->makeDocument();
        $this->lock($locked);

        $this->expectException(DocumentLocked::class);

        $line->update(['test_document_id' => $locked->id]);
    }

    /**
     * Lines are editable again after parent returns to draft.
     */
    public function test_lines_are_editable_again_after_parent_returns_to_draft(): void
    {
        $document = $this->makeDocument();
        $line = $this->makeLine($document);
        $this->lock($document);

        app(LifecycleEngine::class)->transition($document, TestDocumentStatus::Draft);
        $line->update(['quantity' => 9]);

        $this->assertSame(9, $line->fresh()->quantity);
    }

    /**
     * Line lock ignores global scopes on the parent.
     */
    public function test_line_lock_ignores_global_scopes_on_the_parent(): void
    {
        $document = $this->makeDocument();
        $line = $this->makeLine($document);
        $this->lock($document);
        TestDocument::addGlobalScope('hide-everything', fn (Builder $query) => $query->whereRaw('1 = 0'));

        $this->assertRefused(DocumentLocked::class, fn () => $line->update(['quantity' => 9]));
        $this->assertRefused(DocumentLocked::class, fn () => $line->delete());
    }

    /**
     * Line guard still works inside a transaction.
     */
    public function test_line_guard_still_works_inside_a_transaction(): void
    {
        $locked = $this->makeDocument();
        $lockedLine = $this->makeLine($locked);
        $this->lock($locked);
        $draftLine = $this->makeLine($this->makeDocument());

        DB::transaction(function () use ($lockedLine, $draftLine): void {
            $draftLine->update(['quantity' => 7]);
            $this->assertRefused(DocumentLocked::class, fn () => $lockedLine->update(['quantity' => 9]));
        });

        $this->assertSame(7, $draftLine->fresh()->quantity);
        $this->assertSame(2, $lockedLine->fresh()->quantity);
    }
}
