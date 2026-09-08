<?php

namespace App\Services;

use App\Exceptions\AvailabilityBelowReservationsException;
use App\Jobs\ProcessImportJob;
use App\Models\Import;
use App\Models\Offer;
use App\Models\Property;
use App\Models\Supplier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ImportService
{
    /**
     * @param  Supplier  $supplier
     * @param  array{
     *     external_import_id: string,
     *     sent_at: string,
     *     offers: array<int, array<string, mixed>>
     * }  $data
     * @return Import
     */
    public function create(Supplier $supplier, array $data): Import
    {
        $existing = Import::query()
            ->where('supplier_id', $supplier->id)
            ->where('external_import_id', $data['external_import_id'])
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            $import = Import::query()->create([
                'supplier_id' => $supplier->id,
                'external_import_id' => $data['external_import_id'],
                'sent_at' => $data['sent_at'],
                'total_offers' => count($data['offers']),
                'payload' => ['offers' => $data['offers']],
            ]);
        } catch (UniqueConstraintViolationException) {
            // Concurrent duplicate of (supplier_id, external_import_id)
            return Import::query()
                ->where('supplier_id', $supplier->id)
                ->where('external_import_id', $data['external_import_id'])
                ->firstOrFail();
        }

        ProcessImportJob::dispatch($import);

        return $import;
    }

    /**
     * @param  Import  $import
     * @return void
     *
     * @throws Throwable
     * @throws AvailabilityBelowReservationsException
     */
    public function process(Import $import): void
    {
        $import->markProcessing();

        try {
            DB::transaction(function () use ($import): void {
                $processed = 0;

                foreach ($import->offerPayload() as $row) {
                    $this->storeOffer($import, $row);
                    $processed++;
                }

                $import->markCompleted($processed);
            });
        } catch (Throwable $e) {
            Log::error('Import processing failed.', [
                'import_id' => $import->id,
                'exception' => $e,
            ]);

            $import->markFailed(
                $e instanceof AvailabilityBelowReservationsException
                    ? $e->getMessage()
                    : 'The import failed.'
            );

            throw $e;
        }
    }

    /**
     * @param  Import  $import
     * @param  array{
     *     external_id: string,
     *     property: array{code: string, name: string, city: string},
     *     check_in: string,
     *     check_out: string,
     *     max_guests: int,
     *     price: int,
     *     currency: string,
     *     available_units: int,
     *     expires_at: string
     * }  $row
     * @return void
     *
     * @throws AvailabilityBelowReservationsException
     */
    private function storeOffer(Import $import, array $row): void
    {
        $offer = Offer::query()
            ->where('supplier_id', $import->supplier_id)
            ->where('external_id', $row['external_id'])
            ->lockForUpdate()
            ->first();

        if (! $offer) {
            Offer::query()->createOrFirst(
                [
                    'supplier_id' => $import->supplier_id,
                    'external_id' => $row['external_id'],
                ],
                $this->offerAttributes($import, $this->propertyFor($row), $row),
            );

            return;
        }

        // Older payload must not overwrite
        if ($offer->source_sent_at->greaterThan($import->sent_at)) {
            return;
        }

        // Stock cannot fall below bookings
        if ((int) $row['available_units'] < $offer->reserved_units) {
            throw AvailabilityBelowReservationsException::forOffer(
                $row['external_id'],
                (int) $row['available_units'],
                $offer->reserved_units,
            );
        }

        $offer->update(
            $this->offerAttributes($import, $this->propertyFor($row), $row)
        );
    }

    /**
     * @param  array{property: array{code: string, name: string, city: string}}  $row
     * @return Property
     */
    private function propertyFor(array $row): Property
    {
        return Property::query()->firstOrCreate(
            ['code' => $row['property']['code']],
            [
                'name' => $row['property']['name'],
                'city' => $row['property']['city'],
            ],
        );
    }

    /**
     * @param  Import  $import
     * @param  Property  $property
     * @param  array{
     *     check_in: string,
     *     check_out: string,
     *     max_guests: int,
     *     price: int,
     *     currency: string,
     *     available_units: int,
     *     expires_at: string
     * }  $row
     * @return array<string, mixed>
     */
    private function offerAttributes(Import $import, Property $property, array $row): array
    {
        return [
            'property_id' => $property->id,
            'import_id' => $import->id,
            'check_in' => $row['check_in'],
            'check_out' => $row['check_out'],
            'max_guests' => $row['max_guests'],
            'price' => $row['price'],
            'currency' => mb_strtoupper($row['currency']),
            'available_units' => $row['available_units'],
            'expires_at' => $row['expires_at'],
            'source_sent_at' => $import->sent_at,
        ];
    }
}
