<?php

namespace Tests\Support\Lifecycle;

use App\Concerns\HasLifecycle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Throwaway document model that uses the lifecycle guard in tests.
 */
class TestDocument extends Model
{
    use HasLifecycle;

    protected $table = 'test_documents';

    protected $guarded = [];

    protected $attributes = ['status' => 'draft'];

    /**
     * Casts the status column to the test status enum.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => TestDocumentStatus::class];
    }

    /**
     * Only the notes stay editable once the document is locked.
     *
     * @return list<string>
     */
    public function lockedStateEditableAttributes(): array
    {
        return ['notes'];
    }

    /**
     * The document's lines.
     */
    public function lines(): HasMany
    {
        return $this->hasMany(TestDocumentLine::class, 'test_document_id');
    }
}
