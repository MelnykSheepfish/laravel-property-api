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
use Throwable;

class ImportService
{
    /**
     * @param  Supplier  $supplier
     * @param  array  $data
     * @return Import
     */
    public function create(Supplier $supplier, array $data): Import
    {
        $existing = $this->find($supplier, $data['external_import_id']);

        if ($existing !== null) {
            return $existing;
        }

        try {
            $import = Import::create([
                'supplier_id' => $supplier->id,
                'external_import_id' => $data['external_import_id'],
                'sent_at' => $data['sent_at'],
                'total_offers' => count($data['offers']),
                'payload' => ['offers' => $data['offers']],
            ]);
        } catch (UniqueConstraintViolationException $e) {
            $concurrent = $this->find($supplier, $data['external_import_id']);

            if ($concurrent === null) {
                throw $e;
            }

            return $concurrent;
        }

        ProcessImportJob::dispatch($import);

        return $import;
    }

    /**
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
            $import->markFailed($e->getMessage());

            throw $e;
        }
    }

    private function find(Supplier $supplier, string $externalImportId): ?Import
    {
        return Import::query()
            ->where('supplier_id', $supplier->id)
            ->where('external_import_id', $externalImportId)
            ->first();
    }

    /**
     * @param  Import  $import
     * @param  array  $row
     * @return void
     * @throws AvailabilityBelowReservationsException
     */
    private function storeOffer(Import $import, array $row): void
    {
        $property = Property::firstOrCreate(
            ['code' => $row['property']['code']],
            [
                'name' => $row['property']['name'],
                'city' => $row['property']['city'],
            ],
        );

        $offer = Offer::query()
            ->where('supplier_id', $import->supplier_id)
            ->where('external_id', $row['external_id'])
            ->lockForUpdate()
            ->first();

        if ($offer !== null && $offer->source_sent_at->greaterThan($import->sent_at)) {
            return;
        }

        $attributes = [
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

        if ($offer !== null) {
            if ((int)$row['available_units'] < $offer->reserved_units) {
                throw AvailabilityBelowReservationsException::forOffer(
                    $row['external_id'],
                    (int)$row['available_units'],
                    $offer->reserved_units,
                );
            }

            $offer->update($attributes);

            return;
        }

        Offer::create($attributes + [
                'supplier_id' => $import->supplier_id,
                'external_id' => $row['external_id'],
            ]);
    }
}
