<?php

namespace Tests\Support\Lifecycle;

use App\Concerns\LocksWithParentDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Throwaway line model that follows its parent document's lock in tests.
 */
class TestDocumentLine extends Model
{
    use LocksWithParentDocument;

    protected $table = 'test_document_lines';

    protected $guarded = [];

    /**
     * The parent document whose lock this line follows.
     */
    public function lockingDocument(): BelongsTo
    {
        return $this->belongsTo(TestDocument::class, 'test_document_id');
    }
}
