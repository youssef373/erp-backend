<?php

namespace Tests\Support\Lifecycle;

use App\Services\LifecycleEngine;
use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Test helpers: creates the fixture tables and builds documents in any status.
 */
trait CreatesLifecycleTables
{
    /**
     * Creates the test_documents and test_document_lines tables before each test
     * (Laravel runs setUp{TraitName} methods automatically).
     */
    protected function setUpCreatesLifecycleTables(): void
    {
        Schema::create('test_documents', function (Blueprint $table): void {
            $table->id();
            $table->string('status')->default('draft');
            $table->string('notes')->nullable();
            $table->string('supplier')->nullable();
            $table->integer('total')->default(0);
            $table->timestamps();
        });

        Schema::create('test_document_lines', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('test_document_id');
            $table->integer('quantity');
            $table->integer('unit_price');
            $table->timestamps();
        });
    }

    /**
     * Creates a Draft document and walks it to the wanted status through the engine.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function makeDocument(TestDocumentStatus $status = TestDocumentStatus::Draft, array $attributes = []): TestDocument
    {
        $document = TestDocument::create(['supplier' => 'Acme', 'total' => 100, ...$attributes]);

        $path = match ($status) {
            TestDocumentStatus::Draft => [],
            TestDocumentStatus::Sent => [TestDocumentStatus::Sent],
            TestDocumentStatus::PendingApproval => [TestDocumentStatus::PendingApproval],
            TestDocumentStatus::Confirmed => [TestDocumentStatus::Sent, TestDocumentStatus::Confirmed],
            TestDocumentStatus::Cancelled => [TestDocumentStatus::Cancelled],
        };

        foreach ($path as $step) {
            app(LifecycleEngine::class)->transition($document, $step);
        }

        return $document;
    }

    /**
     * Runs the write, asserts it throws the given exception, and returns it for further assertions.
     *
     * @param  class-string<Throwable>  $exception
     */
    protected function assertRefused(string $exception, Closure $write): Throwable
    {
        try {
            $write();
        } catch (Throwable $caught) {
            $this->assertInstanceOf($exception, $caught);

            return $caught;
        }

        $this->fail("Expected {$exception}.");
    }
}
