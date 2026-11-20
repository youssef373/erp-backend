<?php

namespace App\Concerns;

use LogicException;

/**
 * Refuses the "quiet" save, update and delete methods on a guarded model.
 *
 * Quiet writes run without model events, so the lifecycle guard hooks would
 * never see them and the lock could be bypassed without any error.
 */
trait RefusesQuietWrites
{
    /**
     * Always refused: a quiet save would skip the lifecycle guard.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws LogicException
     */
    public function saveQuietly(array $options = []): never
    {
        throw $this->quietWriteRefused();
    }

    /**
     * Always refused: a quiet update would skip the lifecycle guard.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $options
     *
     * @throws LogicException
     */
    public function updateQuietly(array $attributes = [], array $options = []): never
    {
        throw $this->quietWriteRefused();
    }

    /**
     * Always refused: a quiet delete would skip the lifecycle guard.
     *
     * @throws LogicException
     */
    public function deleteQuietly(): never
    {
        throw $this->quietWriteRefused();
    }

    /**
     * Builds the exception thrown by the refused quiet writes.
     */
    private function quietWriteRefused(): LogicException
    {
        return new LogicException(static::class.' is lifecycle-guarded: save and delete it with model events enabled.');
    }
}
