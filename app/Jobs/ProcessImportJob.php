<?php

namespace App\Jobs;

use App\Exceptions\AvailabilityBelowReservationsException;
use App\Models\Import;
use App\Services\ImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessImportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public Import $import) {}

    /**
     * @throws Throwable
     * @throws AvailabilityBelowReservationsException
     */
    public function handle(ImportService $imports): void
    {
        $imports->process($this->import);
    }

    /**
     * @param  Throwable|null  $exception
     * @return void
     */
    public function failed(?Throwable $exception): void
    {
        $this->import->markFailed($exception?->getMessage() ?? 'The import job failed.');
    }
}
