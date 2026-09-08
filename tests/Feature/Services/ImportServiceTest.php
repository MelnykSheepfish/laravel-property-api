<?php

namespace Tests\Feature\Services;

use App\Enums\ImportStatus;
use App\Exceptions\AvailabilityBelowReservationsException;
use App\Jobs\ProcessImportJob;
use App\Models\Import;
use App\Models\Offer;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Supplier;
use App\Services\ImportService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ImportServiceTest extends TestCase
{
    use RefreshDatabase;

    private ImportService $imports;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->imports = app(ImportService::class);
        $this->supplier = Supplier::factory()->create(['code' => 'supplier-a']);
    }

    public function test_creates_the_property_and_the_offer_and_completes_the_import(): void
    {
        $import = $this->importWith([$this->offerRow()]);

        $this->imports->process($import);

        $offer = Offer::sole();
        $this->assertSame('BCN-0001', $offer->property->code);
        $this->assertSame('Barcelona', $offer->property->city);
        $this->assertSame($this->supplier->id, $offer->supplier_id);
        $this->assertSame($import->id, $offer->import_id);
        $this->assertSame(72500, $offer->price);
        $this->assertSame('EUR', $offer->currency);
        $this->assertSame(2, $offer->available_units);
        $this->assertSame(0, $offer->reserved_units);
        $this->assertTrue($offer->source_sent_at->equalTo($import->sent_at));

        $import->refresh();
        $this->assertSame(ImportStatus::Completed, $import->status);
        $this->assertSame(1, $import->processed_offers);
        $this->assertNull($import->error);
        $this->assertNotNull($import->completed_at);
    }

    public function test_reuses_the_property_that_already_has_the_same_code(): void
    {
        $property = Property::factory()->create(['code' => 'BCN-0001', 'city' => 'Barcelona']);

        $this->imports->process($this->importWith([$this->offerRow()]));

        $this->assertSame(1, Property::count());
        $this->assertSame($property->id, Offer::sole()->property_id);
    }

    public function test_updates_the_offer_that_arrived_in_an_earlier_import(): void
    {
        $first = $this->importWith([$this->offerRow()], 'import-001', '2026-09-01T10:00:00Z');
        $this->imports->process($first);

        $second = $this->importWith(
            [$this->offerRow(['price' => 60000, 'available_units' => 5])],
            'import-002',
            '2026-09-02T10:00:00Z',
        );
        $this->imports->process($second);

        $offer = Offer::sole();
        $this->assertSame(60000, $offer->price);
        $this->assertSame(5, $offer->available_units);
        $this->assertSame($second->id, $offer->import_id);
    }

    public function test_keeps_reservations_when_the_offer_is_imported_again(): void
    {
        $this->imports->process($this->importWith([$this->offerRow()], 'import-001', '2026-09-01T10:00:00Z'));
        $offer = Offer::sole();
        Reservation::factory()->for($offer)->create();
        $offer->increment('reserved_units');

        $this->imports->process($this->importWith(
            [$this->offerRow(['available_units' => 4])],
            'import-002',
            '2026-09-02T10:00:00Z',
        ));

        $offer->refresh();
        $this->assertSame(4, $offer->available_units);
        $this->assertSame(1, $offer->reserved_units);
        $this->assertSame(3, $offer->unitsLeft());
    }

    public function test_fails_when_available_units_would_fall_below_reservations(): void
    {
        $this->imports->process($this->importWith(
            [$this->offerRow(['available_units' => 2])],
            'import-001',
            '2026-09-01T10:00:00Z',
        ));

        $offer = Offer::sole();
        Reservation::factory()->for($offer)->create();
        Reservation::factory()->for($offer)->create(['client_reference' => 'web-order-second']);
        $offer->update(['reserved_units' => 2]);

        $import = $this->importWith(
            [$this->offerRow(['available_units' => 1])],
            'import-002',
            '2026-09-02T10:00:00Z',
        );

        try {
            $this->imports->process($import);
            $this->fail('The import was expected to fail.');
        } catch (AvailabilityBelowReservationsException $e) {
            $this->assertStringContainsString('offer-a-10001', $e->getMessage());
        }

        $import->refresh();
        $offer->refresh();

        $this->assertSame(ImportStatus::Failed, $import->status);
        $this->assertNotNull($import->error);
        $this->assertSame(2, $offer->available_units);
        $this->assertSame(2, $offer->reserved_units);
        $this->assertSame(2, Reservation::count());
    }

    public function test_does_not_overwrite_offer_data_with_an_import_the_supplier_sent_earlier(): void
    {
        $this->imports->process($this->importWith(
            [$this->offerRow(['price' => 60000])],
            'import-002',
            '2026-09-02T10:00:00Z',
        ));

        $stale = $this->importWith(
            [$this->offerRow(['price' => 99000])],
            'import-001',
            '2026-09-01T10:00:00Z',
        );
        $this->imports->process($stale);

        $this->assertSame(60000, Offer::sole()->price);
        $this->assertSame(ImportStatus::Completed, $stale->refresh()->status);
        $this->assertSame(1, $stale->processed_offers);
    }

    public function test_marks_the_import_failed_and_stores_nothing_when_a_row_cannot_be_saved(): void
    {
        $import = $this->importWith([
            $this->offerRow(),
            $this->offerRow([
                'external_id' => 'offer-a-10002',
                'property' => [
                    'code' => str_repeat('X', 300),
                    'name' => 'Too long to store',
                    'city' => 'Barcelona',
                ],
            ]),
        ]);

        try {
            $this->imports->process($import);
            $this->fail('The import was expected to fail.');
        } catch (QueryException) {
            //
        }

        $import->refresh();
        $this->assertSame(ImportStatus::Failed, $import->status);
        $this->assertSame('The import failed.', $import->error);
        $this->assertSame(0, $import->processed_offers);
        $this->assertSame(0, Offer::count());
        $this->assertSame(0, Property::count());
    }

    public function test_create_returns_the_existing_import_and_does_not_queue_again(): void
    {
        Queue::fake();

        $payload = [
            'external_import_id' => 'import-001',
            'sent_at' => '2026-09-01T10:00:00Z',
            'offers' => [$this->offerRow()],
        ];

        $first = $this->imports->create($this->supplier, $payload);
        $second = $this->imports->create($this->supplier, $payload);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, Import::count());
        Queue::assertPushed(ProcessImportJob::class, 1);
    }

    public function test_create_returns_the_existing_import_when_a_concurrent_insert_races(): void
    {
        Queue::fake();

        $externalId = 'import-race';
        $payload = [
            'external_import_id' => $externalId,
            'sent_at' => '2026-09-01T10:00:00Z',
            'offers' => [$this->offerRow()],
        ];

        // Simulate the race: SELECT misses, then a concurrent insert wins before ours.
        Import::creating(function (Import $import) use ($externalId): void {
            if ($import->external_import_id !== $externalId) {
                return;
            }

            DB::table('imports')->insert([
                'supplier_id' => $import->supplier_id,
                'external_import_id' => $externalId,
                'sent_at' => '2026-09-01 10:00:00',
                'status' => ImportStatus::Pending->value,
                'total_offers' => 1,
                'processed_offers' => 0,
                'payload' => json_encode(['offers' => []], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $result = $this->imports->create($this->supplier, $payload);

        $this->assertSame(1, Import::count());
        $this->assertSame($externalId, $result->external_import_id);
        Queue::assertNothingPushed();
    }

    /**
     * @param  array<int, array<string, mixed>>  $offers
     */
    private function importWith(array $offers, string $externalId = 'import-001', string $sentAt = '2026-09-01T10:00:00Z'): Import
    {
        return Import::factory()->for($this->supplier)->create([
            'external_import_id' => $externalId,
            'sent_at' => $sentAt,
            'total_offers' => count($offers),
            'payload' => ['offers' => $offers],
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function offerRow(array $overrides = []): array
    {
        return array_merge([
            'external_id' => 'offer-a-10001',
            'property' => [
                'code' => 'BCN-0001',
                'name' => 'Apartment near Sagrada Familia',
                'city' => 'Barcelona',
            ],
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-15',
            'max_guests' => 4,
            'price' => 72500,
            'currency' => 'EUR',
            'available_units' => 2,
            'expires_at' => '2026-09-10T23:59:59Z',
        ], $overrides);
    }
}
