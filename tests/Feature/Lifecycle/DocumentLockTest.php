<?php

namespace Tests\Feature\Lifecycle;

use App\Exceptions\DocumentLocked;
use App\Services\LifecycleEngine;
use Illuminate\Database\Eloquent\Builder;
use Tests\Support\Lifecycle\CreatesLifecycleTables;
use Tests\Support\Lifecycle\TestDocument;
use Tests\Support\Lifecycle\TestDocumentStatus;
use Tests\TestCase;

/**
 * Feature tests for the lock-after-Draft guard on documents: locked fields, the whitelist, deletes and stale copies.
 */
class DocumentLockTest extends TestCase
{
    use CreatesLifecycleTables;

    /**
     * Draft document allows any edit and delete.
     */
    public function test_draft_document_allows_any_edit_and_delete(): void
    {
        $document = $this->makeDocument();

        $document->update(['supplier' => 'Other', 'total' => 5, 'notes' => 'n']);
        $document->delete();

        $this->assertModelMissing($document);
    }

    /**
     * Sent document is still editable.
     */
    public function test_sent_document_is_still_editable(): void
    {
        $document = $this->makeDocument(TestDocumentStatus::Sent);

        $document->update(['supplier' => 'Other']);

        $this->assertSame('Other', $document->fresh()->supplier);
    }

    /**
     * Locked document refuses edit of locked fields.
     */
    public function test_locked_document_refuses_edit_of_locked_fields(): void
    {
        $document = $this->makeDocument(TestDocumentStatus::Confirmed);

        $exception = $this->assertRefused(DocumentLocked::class, fn () => $document->update(['supplier' => 'Other', 'total' => 5]));

        $this->assertStringContainsString('supplier', $exception->getMessage());
        $this->assertStringContainsString('total', $exception->getMessage());

        $this->assertSame('Acme', $document->fresh()->supplier);
        $this->assertSame(100, $document->fresh()->total);
    }

    /**
     * Locked document allows whitelisted field.
     */
    public function test_locked_document_allows_whitelisted_field(): void
    {
        $document = $this->makeDocument(TestDocumentStatus::Confirmed);

        $document->update(['notes' => 'Call before delivery']);

        $this->assertSame('Call before delivery', $document->fresh()->notes);
    }

    /**
     * Mixed save is refused entirely.
     */
    public function test_mixed_save_is_refused_entirely(): void
    {
        $document = $this->makeDocument(TestDocumentStatus::Confirmed);

        $this->assertRefused(DocumentLocked::class, fn () => $document->update(['notes' => 'changed', 'supplier' => 'Other']));

        $this->assertNull($document->fresh()->notes);
        $this->assertSame('Acme', $document->fresh()->supplier);
    }

    /**
     * Touch on locked document is allowed.
     */
    public function test_touch_on_locked_document_is_allowed(): void
    {
        $document = $this->makeDocument(TestDocumentStatus::Confirmed);

        $this->assertTrue($document->touch());
    }

    /**
     * Locked document cannot be deleted.
     */
    public function test_locked_document_cannot_be_deleted(): void
    {
        $document = $this->makeDocument(TestDocumentStatus::Confirmed);

        $this->expectException(DocumentLocked::class);

        $document->delete();
    }

    /**
     * Stale draft copy cannot edit a document that has since been locked.
     */
    public function test_stale_draft_copy_cannot_edit_a_document_that_has_since_been_locked(): void
    {
        $document = $this->makeDocument();
        $stale = TestDocument::find($document->id);
        app(LifecycleEngine::class)->transition($document, TestDocumentStatus::PendingApproval);

        $this->assertRefused(DocumentLocked::class, fn () => $stale->update(['supplier' => 'Other']));

        $this->assertSame('Acme', $document->fresh()->supplier);
    }

    /**
     * Stale draft copy cannot delete a document that has since been locked.
     */
    public function test_stale_draft_copy_cannot_delete_a_document_that_has_since_been_locked(): void
    {
        $document = $this->makeDocument();
        $stale = TestDocument::find($document->id);
        app(LifecycleEngine::class)->transition($document, TestDocumentStatus::PendingApproval);

        $this->expectException(DocumentLocked::class);

        $stale->delete();
    }

    /**
     * Transition out of locked state works with only status dirty.
     */
    public function test_transition_out_of_locked_state_works_with_only_status_dirty(): void
    {
        $document = $this->makeDocument(TestDocumentStatus::PendingApproval);

        app(LifecycleEngine::class)->transition($document, TestDocumentStatus::Confirmed);

        $this->assertSame(TestDocumentStatus::Confirmed, $document->fresh()->status);
    }

    /**
     * Lock lifts when returned to draft.
     */
    public function test_lock_lifts_when_returned_to_draft(): void
    {
        $document = $this->makeDocument(TestDocumentStatus::PendingApproval);

        app(LifecycleEngine::class)->transition($document, TestDocumentStatus::Draft);
        $document->update(['supplier' => 'Other']);

        $this->assertSame('Other', $document->fresh()->supplier);
    }

    /**
     * Lock check ignores global scopes on the document.
     */
    public function test_lock_check_ignores_global_scopes_on_the_document(): void
    {
        $document = $this->makeDocument(TestDocumentStatus::Confirmed);
        TestDocument::addGlobalScope('hide-everything', fn (Builder $query) => $query->whereRaw('1 = 0'));

        $this->assertRefused(DocumentLocked::class, fn () => $document->update(['supplier' => 'Other']));
        $this->assertRefused(DocumentLocked::class, fn () => $document->delete());
    }
}
