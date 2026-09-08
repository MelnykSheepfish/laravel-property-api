<?php

namespace App\Jobs;

use App\Exceptions\AvailabilityBelowReservationsException;
use App\Models\Import;
use App\Services\ImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessImportJob implements ShouldQueue
{
    use Queueable;

    // Failed import is not retried
    public int $tries = 1;

    /**
     * @param  Import  $import
     */
    public function __construct(public Import $import) {}

    /**
     * @param  ImportService  $imports
     * @return void
     *
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
        if ($exception !== null) {
            Log::error('Import job failed.', [
                'import_id' => $this->import->id,
                'exception' => $exception,
            ]);
        }

        $message = $exception instanceof AvailabilityBelowReservationsException
            ? $exception->getMessage()
            : 'The import failed.';

        $this->import->markFailed($message);
    }
}
