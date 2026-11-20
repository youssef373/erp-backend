<?php

namespace Tests\Feature\Lifecycle;

use App\Exceptions\InvalidTransition;
use App\Services\LifecycleEngine;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use RuntimeException;
use Tests\Support\Lifecycle\CreatesLifecycleTables;
use Tests\Support\Lifecycle\TestDocument;
use Tests\Support\Lifecycle\TestDocumentStatus;
use Tests\TestCase;

/**
 * Feature tests for the lifecycle engine: allowed and refused transitions, engine-only status writes and creation rules.
 */
class LifecycleEngineTest extends TestCase
{
    use CreatesLifecycleTables;

    /**
     * Allowed transition updates status.
     */
    public function test_allowed_transition_updates_status(): void
    {
        $document = $this->makeDocument();

        app(LifecycleEngine::class)->transition($document, TestDocumentStatus::Sent);

        $this->assertSame(TestDocumentStatus::Sent, $document->fresh()->status);
    }

    /**
     * Illegal transition is refused with clear message.
     */
    public function test_illegal_transition_is_refused_with_clear_message(): void
    {
        $document = $this->makeDocument();

        $exception = $this->assertRefused(InvalidTransition::class, fn () => app(LifecycleEngine::class)->transition($document, TestDocumentStatus::Confirmed));

        $this->assertStringContainsString('draft', $exception->getMessage());
        $this->assertStringContainsString('confirmed', $exception->getMessage());

        $this->assertSame(TestDocumentStatus::Draft, $document->fresh()->status);
    }

    /**
     * Same state transition is refused.
     */
    public function test_same_state_transition_is_refused(): void
    {
        $document = $this->makeDocument();

        $this->expectException(InvalidTransition::class);

        app(LifecycleEngine::class)->transition($document, TestDocumentStatus::Draft);
    }

    /**
     * Rejected document returns to draft.
     */
    public function test_rejected_document_returns_to_draft(): void
    {
        $document = $this->makeDocument(TestDocumentStatus::PendingApproval);

        app(LifecycleEngine::class)->transition($document, TestDocumentStatus::Draft);

        $this->assertSame(TestDocumentStatus::Draft, $document->fresh()->status);
    }

    /**
     * Stale model is validated against database status.
     */
    public function test_stale_model_is_validated_against_database_status(): void
    {
        $created = $this->makeDocument();
        $first = TestDocument::find($created->id);
        $stale = TestDocument::find($created->id);

        app(LifecycleEngine::class)->transition($first, TestDocumentStatus::Cancelled);

        $this->expectException(InvalidTransition::class);

        app(LifecycleEngine::class)->transition($stale, TestDocumentStatus::Sent);
    }

    /**
     * Mass assigned status write is refused.
     */
    public function test_mass_assigned_status_write_is_refused(): void
    {
        $document = $this->makeDocument();

        $this->expectException(InvalidTransition::class);

        $document->update(['status' => 'sent']);
    }

    /**
     * Assigned status save is refused.
     */
    public function test_assigned_status_save_is_refused(): void
    {
        $document = $this->makeDocument();
        $document->status = TestDocumentStatus::Sent;

        $this->expectException(InvalidTransition::class);

        $document->save();
    }

    /**
     * Can transition reports without changing anything.
     */
    public function test_can_transition_reports_without_changing_anything(): void
    {
        $document = $this->makeDocument();
        $engine = app(LifecycleEngine::class);

        $this->assertTrue($engine->canTransition($document, TestDocumentStatus::Sent));
        $this->assertFalse($engine->canTransition($document, TestDocumentStatus::Confirmed));
        $this->assertSame(TestDocumentStatus::Draft, $document->fresh()->status);
    }

    /**
     * Transition refuses document with unsaved changes.
     */
    public function test_transition_refuses_document_with_unsaved_changes(): void
    {
        $document = $this->makeDocument();
        $document->notes = 'unsaved';

        $this->expectException(InvalidTransition::class);

        app(LifecycleEngine::class)->transition($document, TestDocumentStatus::Sent);
    }

    /**
     * Document cannot be created outside its initial state.
     */
    public function test_document_cannot_be_created_outside_its_initial_state(): void
    {
        $this->expectException(InvalidTransition::class);

        TestDocument::create(['status' => TestDocumentStatus::Confirmed, 'supplier' => 'Acme']);
    }

    /**
     * Replicated confirmed document cannot be saved.
     */
    public function test_replicated_confirmed_document_cannot_be_saved(): void
    {
        $confirmed = $this->makeDocument(TestDocumentStatus::Confirmed);

        $this->expectException(InvalidTransition::class);

        $confirmed->replicate()->save();
    }

    /**
     * Transition to on the model validates the move.
     */
    public function test_transition_to_on_the_model_validates_the_move(): void
    {
        $document = $this->makeDocument();

        $this->assertRefused(InvalidTransition::class, fn () => $document->transitionTo(TestDocumentStatus::Confirmed));

        $this->assertSame(TestDocumentStatus::Draft, $document->fresh()->status);
    }

    /**
     * Transition to on the model refuses pending changes.
     */
    public function test_transition_to_on_the_model_refuses_pending_changes(): void
    {
        $document = $this->makeDocument();
        $document->notes = 'unsaved';

        $this->assertRefused(InvalidTransition::class, fn () => $document->transitionTo(TestDocumentStatus::Sent));
    }

    /**
     * Transition to on a stale copy validates against the database.
     */
    public function test_transition_to_on_a_stale_copy_validates_against_the_database(): void
    {
        $document = $this->makeDocument();
        $stale = TestDocument::find($document->id);
        $document->transitionTo(TestDocumentStatus::Cancelled);

        $this->assertRefused(InvalidTransition::class, fn () => $stale->transitionTo(TestDocumentStatus::Sent));
    }

    /**
     * Document without status is refused with a domain error.
     */
    public function test_document_without_status_is_refused_with_a_domain_error(): void
    {
        $document = new TestDocument(['supplier' => 'Acme']);
        $document->status = null;

        $this->assertRefused(InvalidTransition::class, fn () => $document->save());
    }

    /**
     * Failed transition leaves the in memory model unchanged.
     */
    public function test_failed_transition_leaves_the_in_memory_model_unchanged(): void
    {
        $document = $this->makeDocument();
        TestDocument::updated(function (): void {
            throw new RuntimeException('listener failed');
        });

        $this->assertRefused(RuntimeException::class, fn () => app(LifecycleEngine::class)->transition($document, TestDocumentStatus::Sent));

        $this->assertSame(TestDocumentStatus::Draft, $document->status);
        $this->assertSame(TestDocumentStatus::Draft, $document->fresh()->status);
        $this->assertTrue(app(LifecycleEngine::class)->canTransition($document, TestDocumentStatus::Sent));
    }

    /**
     * Engine refuses a model that has no lifecycle.
     */
    public function test_engine_refuses_a_model_that_has_no_lifecycle(): void
    {
        $plain = new class extends Model {};
        $engine = app(LifecycleEngine::class);

        $this->assertRefused(InvalidArgumentException::class, fn () => $engine->transition($plain, TestDocumentStatus::Sent));
        $this->assertRefused(InvalidArgumentException::class, fn () => $engine->canTransition($plain, TestDocumentStatus::Sent));
    }
}
