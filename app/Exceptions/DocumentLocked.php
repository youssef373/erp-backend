<?php

namespace App\Exceptions;

use DomainException;

/**
 * Thrown when something tries to change or delete a document, or its lines,
 * after the document has left its editable states.
 */
final class DocumentLocked extends DomainException
{
    /**
     * Locked attributes of the document itself were changed.
     *
     * @param  list<string>  $attributes  Names of the attributes that may no longer change.
     */
    public static function forAttributes(array $attributes): self
    {
        return new self('The document is locked; these fields can no longer be edited: '.implode(', ', $attributes).'.');
    }

    /**
     * A line was added, changed, moved or removed on a locked document.
     */
    public static function forLines(): self
    {
        return new self('The document is locked; its lines can no longer be changed.');
    }

    /**
     * A locked document was deleted.
     */
    public static function forDeletion(): self
    {
        return new self('The document is locked and can no longer be deleted.');
    }
}
