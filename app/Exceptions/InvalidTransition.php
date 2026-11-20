<?php

namespace App\Exceptions;

use App\Contracts\DocumentStatus;
use DomainException;

/**
 * Thrown when a document status change is not allowed by its lifecycle.
 *
 * Covers moves the lifecycle does not permit, status writes that bypass the
 * lifecycle engine, and documents created or changed in an invalid way.
 */
final class InvalidTransition extends DomainException
{
    /**
     * The lifecycle does not allow moving from one state to the other.
     */
    public static function between(DocumentStatus $from, DocumentStatus $to): self
    {
        return new self("A document cannot move from '{$from->value}' to '{$to->value}'.");
    }

    /**
     * A new document was created outside its type's initial state.
     */
    public static function notInitial(DocumentStatus $status): self
    {
        return new self("A new document must start in its initial state, not '{$status->value}'.");
    }

    /**
     * A new document was created without any status.
     */
    public static function missingStatus(): self
    {
        return new self('A new document needs a status; set it to its initial state.');
    }

    /**
     * The status column was changed without going through the lifecycle engine.
     */
    public static function directWrite(): self
    {
        return new self('A document status can only be changed through the lifecycle engine.');
    }

    /**
     * A transition was requested while the document had unsaved changes.
     */
    public static function pendingChanges(): self
    {
        return new self('Save or discard pending changes before changing the document status.');
    }
}
