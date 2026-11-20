<?php

namespace App\Concerns;

use App\Contracts\DocumentStatus;
use App\Exceptions\DocumentLocked;
use App\Exceptions\InvalidTransition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Gives a document model a guarded status lifecycle.
 *
 * Once the document leaves its editable states, every attribute except the
 * whitelisted ones is locked and the document cannot be deleted. The status
 * column itself can only change through transitionTo() (normally reached via
 * the lifecycle engine), and new documents must start in the enum's initial state.
 *
 * Stored-state reads ignore global scopes, so a soft-deleted or scoped document
 * stays locked. Inside a database transaction they also take a row lock, so
 * callers that wrap their writes in a transaction are protected from a
 * concurrent transition.
 *
 * The using model must cast `status` to a DocumentStatus enum and implement
 * `lockedStateEditableAttributes()`.
 */
trait HasLifecycle
{
    use RefusesQuietWrites;

    /**
     * The attributes that stay editable after the document is locked; everything else is locked.
     *
     * @return list<string>
     */
    abstract public function lockedStateEditableAttributes(): array;

    /**
     * True only while transitionTo() is saving, so the guard lets that one status write through.
     */
    private bool $lifecycleTransitioning = false;

    /**
     * Registers the creating, updating and deleting guards on the model.
     */
    public static function bootHasLifecycle(): void
    {
        static::creating(function (Model $document): void {
            $status = $document->getAttribute('status');

            if (! $status instanceof DocumentStatus) {
                throw InvalidTransition::missingStatus();
            }

            if ($status !== $status::initialState()) {
                throw InvalidTransition::notInitial($status);
            }
        });

        static::updating(function (Model $document): void {
            if ($document->isDirty('status') && ! $document->lifecycleTransitioning) {
                throw InvalidTransition::directWrite();
            }

            $blocked = array_diff(
                array_keys($document->getDirty()),
                ['status', 'updated_at'],
                $document->lockedStateEditableAttributes(),
            );

            if ($blocked !== [] && $document->isLockedByLifecycle()) {
                throw DocumentLocked::forAttributes(array_values($blocked));
            }
        });

        static::deleting(function (Model $document): void {
            if ($document->isLockedByLifecycle()) {
                throw DocumentLocked::forDeletion();
            }
        });
    }

    /**
     * The document's current in-memory status.
     */
    public function lifecycleStatus(): DocumentStatus
    {
        return $this->status;
    }

    /**
     * Whether the document's in-memory status may legally move to the given state.
     *
     * The single definition of "allowed transition".
     */
    public function canTransitionTo(DocumentStatus $to): bool
    {
        return in_array($to, $this->lifecycleStatus()->allowedTransitions(), true);
    }

    /**
     * Whether the status stored in the database right now has left the editable
     * states. Reads the row, so an out-of-date in-memory copy cannot unlock it.
     */
    public function isLockedByLifecycle(): bool
    {
        $query = $this->newQueryWithoutScopes()->whereKey($this->getKey());

        if (DB::transactionLevel() > 0) {
            $query->lockForUpdate();
        }

        $stored = $query->first();

        return $stored !== null && ! $stored->lifecycleStatus()->isEditable();
    }

    /**
     * Move the document to a new state, validated against the row as stored right now.
     *
     * Runs in a transaction that locks the row, then syncs this model to the stored
     * values before validating. If anything fails, the in-memory model is restored
     * to the stored state, so it never keeps a status that was rolled back.
     * The model must have no unsaved changes.
     *
     * @param  DocumentStatus  $to  The state to move to.
     * @return $this
     *
     * @throws InvalidTransition When the move is not allowed or the model has unsaved changes.
     */
    public function transitionTo(DocumentStatus $to): static
    {
        if ($this->isDirty()) {
            throw InvalidTransition::pendingChanges();
        }

        return DB::transaction(function () use ($to): static {
            $stored = $this->newQueryWithoutScopes()->lockForUpdate()->findOrFail($this->getKey());
            $storedAttributes = $stored->getAttributes();
            $this->setRawAttributes($storedAttributes, true);

            try {
                if (! $this->canTransitionTo($to)) {
                    throw InvalidTransition::between($this->lifecycleStatus(), $to);
                }

                $this->lifecycleTransitioning = true;

                try {
                    $this->status = $to;
                    $this->save();
                } finally {
                    $this->lifecycleTransitioning = false;
                }
            } catch (Throwable $exception) {
                $this->setRawAttributes($storedAttributes, true);

                throw $exception;
            }

            return $this;
        });
    }
}
