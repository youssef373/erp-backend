<?php

namespace App\Contracts;

use BackedEnum;

/**
 * Contract for the status enum of a lifecycle-guarded document type.
 *
 * Each document type (purchase order, quotation, invoice and so on) implements
 * this on its own backed enum to declare its states, the moves allowed between
 * them, and which states still allow editing. The lifecycle engine and the
 * model guards rely on nothing else.
 */
interface DocumentStatus extends BackedEnum
{
    /**
     * The only state a new document may be created in.
     */
    public static function initialState(): static;

    /**
     * The states this state may legally move to.
     *
     * @return list<static>
     */
    public function allowedTransitions(): array;

    /**
     * Whether a document in this state may still have its content edited.
     */
    public function isEditable(): bool;
}
