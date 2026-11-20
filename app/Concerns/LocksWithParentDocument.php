<?php

namespace App\Concerns;

use App\Exceptions\DocumentLocked;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Locks a document line while its parent document is outside the editable states.
 *
 * Creating, changing, moving or deleting a line is refused when the parent it
 * belongs to, or is being moved from, is locked. The parent is read from the
 * database ignoring global scopes, and inside a transaction the read takes a
 * row lock, so callers that wrap line writes in a transaction are protected
 * from a concurrent transition.
 *
 * The using model must implement `lockingDocument()`, a BelongsTo pointing at a
 * model that uses HasLifecycle.
 */
trait LocksWithParentDocument
{
    use RefusesQuietWrites;

    /**
     * Registers the creating, updating and deleting guards on the line model.
     */
    public static function bootLocksWithParentDocument(): void
    {
        static::creating(function (Model $line): void {
            $line->refuseWhenParentLocked($line->getAttribute($line->lockingForeignKey()));
        });

        static::updating(function (Model $line): void {
            $foreignKey = $line->lockingForeignKey();

            $line->refuseWhenParentLocked($line->getOriginal($foreignKey));

            if ($line->isDirty($foreignKey)) {
                $line->refuseWhenParentLocked($line->getAttribute($foreignKey));
            }
        });

        static::deleting(function (Model $line): void {
            $line->refuseWhenParentLocked($line->getOriginal($line->lockingForeignKey()));
        });
    }

    /**
     * The parent document this line belongs to.
     */
    abstract public function lockingDocument(): BelongsTo;

    /**
     * The name of the foreign key column that points at the parent document.
     */
    private function lockingForeignKey(): string
    {
        return $this->lockingDocument()->getForeignKeyName();
    }

    /**
     * Refuses the write when the given parent document is locked.
     *
     * Checks the parent as stored right now, never a possibly stale loaded copy.
     *
     * @param  mixed  $parentId  The parent's key; null means there is no parent to check.
     *
     * @throws DocumentLocked
     */
    private function refuseWhenParentLocked(mixed $parentId): void
    {
        if ($parentId === null) {
            return;
        }

        $query = $this->lockingDocument()->getRelated()->newQueryWithoutScopes();

        if (DB::transactionLevel() > 0) {
            $query->lockForUpdate();
        }

        $parent = $query->find($parentId);

        if ($parent !== null && ! $parent->lifecycleStatus()->isEditable()) {
            throw DocumentLocked::forLines();
        }
    }
}
