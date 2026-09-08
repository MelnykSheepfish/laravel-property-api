<?php

namespace Tests\Feature\Http\Controllers\Api;

use App\Enums\ImportStatus;
use App\Jobs\ProcessImportJob;
use App\Models\Import;
use App\Models\Offer;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StoreImportControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_payload_queues_an_import_and_returns_202(): void
    {
        Queue::fake();
        $supplier = Supplier::factory()->create(['code' => 'supplier-a']);

        $response = $this->postJson(route('imports.store'), $this->payload());

        $import = Import::sole();
        $response->assertAccepted()
            ->assertExactJson(['data' => ['id' => $import->id, 'status' => 'pending']]);

        $this->assertSame($supplier->id, $import->supplier_id);
        $this->assertSame('import-2026-09-01-001', $import->external_import_id);
        $this->assertSame(ImportStatus::Pending, $import->status);
        $this->assertSame(1, $import->total_offers);
        Queue::assertPushed(ProcessImportJob::class, fn (ProcessImportJob $job): bool => $job->import->is($import));
    }

    public function test_sync_queue_processes_the_import_and_creates_the_offer(): void
    {
        Supplier::factory()->create(['code' => 'supplier-a']);

        $response = $this->postJson(route('imports.store'), $this->payload());

        $import = Import::sole()->fresh();
        $response->assertAccepted()
            ->assertJsonPath('data.id', $import->id);

        $this->assertSame(ImportStatus::Completed, $import->status);
        $this->assertSame(1, $import->processed_offers);

        $offer = Offer::sole();
        $this->assertSame('offer-a-10001', $offer->external_id);
        $this->assertSame('BCN-0001', $offer->property->code);
        $this->assertSame(72500, $offer->price);
    }

    public function test_resending_the_same_import_returns_the_original_and_does_not_queue_it_again(): void
    {
        Queue::fake();
        Supplier::factory()->create(['code' => 'supplier-a']);

        $first = $this->postJson(route('imports.store'), $this->payload());
        $second = $this->postJson(route('imports.store'), $this->payload());

        $second->assertAccepted()
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertSame(1, Import::count());
        Queue::assertPushed(ProcessImportJob::class, 1);
    }

    public function test_the_same_external_import_id_from_another_supplier_is_a_separate_import(): void
    {
        Queue::fake();
        Supplier::factory()->create(['code' => 'supplier-a']);
        Supplier::factory()->create(['code' => 'supplier-b']);

        $this->postJson(route('imports.store'), $this->payload());
        $this->postJson(route('imports.store'), $this->payload(['supplier' => 'supplier-b']))->assertAccepted();

        $this->assertSame(2, Import::count());
        Queue::assertPushed(ProcessImportJob::class, 2);
    }

    public function test_returns_422_when_the_supplier_is_unknown(): void
    {
        Queue::fake();

        $this->postJson(route('imports.store'), $this->payload(['supplier' => 'supplier-z']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['supplier' => 'The selected supplier is invalid.']);

        Queue::assertNothingPushed();
    }

    public function test_returns_422_when_the_payload_is_empty(): void
    {
        $this->postJson(route('imports.store'), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['supplier', 'external_import_id', 'sent_at', 'offers']);
    }

    public function test_returns_422_when_check_out_is_not_after_check_in(): void
    {
        Supplier::factory()->create(['code' => 'supplier-a']);

        $payload = $this->payload();
        $payload['offers'][0]['check_out'] = $payload['offers'][0]['check_in'];

        $this->postJson(route('imports.store'), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['offers.0.check_out']);
    }

    public function test_returns_422_when_the_same_offer_is_sent_twice_in_one_import(): void
    {
        Supplier::factory()->create(['code' => 'supplier-a']);

        $payload = $this->payload();
        $payload['offers'][] = $payload['offers'][0];

        $this->postJson(route('imports.store'), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['offers.0.external_id', 'offers.1.external_id']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'supplier' => 'supplier-a',
            'external_import_id' => 'import-2026-09-01-001',
            'sent_at' => '2026-09-01T10:00:00Z',
            'offers' => [
                [
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
                ],
            ],
        ], $overrides);
    }
}
