<?php

namespace App\Services;

use App\Concerns\HasLifecycle;
use App\Contracts\DocumentStatus;
use App\Exceptions\InvalidTransition;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * The entry point for changing a document's status.
 *
 * Validates each move against the lifecycle declared by the document's status
 * enum, using the row as stored in the database rather than a possibly stale
 * in-memory copy. Models using HasLifecycle refuse any other status write.
 */
final class LifecycleEngine
{
    /**
     * Whether the document's current in-memory status may move to the given state.
     *
     * This is a quick check only; transition() re-validates against the stored row.
     *
     * @throws InvalidArgumentException When the model does not use HasLifecycle.
     */
    public function canTransition(Model $document, DocumentStatus $to): bool
    {
        $this->assertHasLifecycle($document);

        return $document->canTransitionTo($to);
    }

    /**
     * Move a document to a new state, validated against the row as stored right now.
     *
     * @param  Model  $document  A model using the HasLifecycle trait.
     * @param  DocumentStatus  $to  The state to move to.
     * @return Model The same model, now in the new state.
     *
     * @throws InvalidTransition When the move is not allowed or the model has unsaved changes.
     * @throws InvalidArgumentException When the model does not use HasLifecycle.
     */
    public function transition(Model $document, DocumentStatus $to): Model
    {
        $this->assertHasLifecycle($document);

        return $document->transitionTo($to);
    }

    /**
     * Fails fast, before any query, when the model has no lifecycle to run.
     *
     * @throws InvalidArgumentException
     */
    private function assertHasLifecycle(Model $document): void
    {
        if (! in_array(HasLifecycle::class, class_uses_recursive($document), true)) {
            throw new InvalidArgumentException($document::class.' does not use the HasLifecycle trait.');
        }
    }
}
