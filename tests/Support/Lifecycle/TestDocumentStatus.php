<?php

namespace Tests\Support\Lifecycle;

use App\Contracts\DocumentStatus;

/**
 * Throwaway status enum used to exercise the lifecycle engine in tests.
 *
 * Draft and Sent are editable; PendingApproval can return to Draft, mirroring a rejected PO.
 */
enum TestDocumentStatus: string implements DocumentStatus
{
    case Draft = 'draft';
    case Sent = 'sent';
    case PendingApproval = 'pending_approval';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Sent, self::PendingApproval, self::Cancelled],
            self::Sent => [self::Confirmed, self::Cancelled],
            self::PendingApproval => [self::Confirmed, self::Draft],
            self::Confirmed => [self::Cancelled],
            self::Cancelled => [],
        };
    }

    /**
     * New documents always start as Draft.
     */
    public static function initialState(): static
    {
        return self::Draft;
    }

    /**
     * Only Draft and Sent allow editing.
     */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Sent], true);
    }
}
