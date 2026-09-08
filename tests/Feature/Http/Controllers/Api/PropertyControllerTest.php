<?php

namespace Tests\Feature\Http\Controllers\Api;

use App\Models\Offer;
use App\Models\Property;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PropertyControllerTest extends TestCase
{
    use RefreshDatabase;

    private const CHECK_IN = '2026-10-10';

    private const CHECK_OUT = '2026-10-15';

    public function test_returns_the_cheapest_bookable_offer_of_each_property(): void
    {
        $property = Property::factory()->create([
            'code' => 'BCN-0001',
            'name' => 'Apartment near Sagrada Familia',
            'city' => 'Barcelona',
        ]);
        $cheapest = $this->offerFor($property, ['price' => 72500], 'supplier-a');
        $this->offerFor($property, ['price' => 90000], 'supplier-b');

        $this->search()
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'BCN-0001')
            ->assertJsonPath('data.0.name', 'Apartment near Sagrada Familia')
            ->assertJsonPath('data.0.city', 'Barcelona')
            ->assertJsonPath('data.0.best_offer', [
                'id' => $cheapest->id,
                'supplier' => 'supplier-a',
                'price' => 72500,
                'currency' => 'EUR',
                'available_units' => 2,
                'expires_at' => $cheapest->expires_at->toIso8601ZuluString(),
            ]);
    }

    public function test_reports_the_units_that_are_still_free(): void
    {
        $property = Property::factory()->create();
        $this->offerFor($property, ['available_units' => 5, 'reserved_units' => 3]);

        $this->search()->assertJsonPath('data.0.best_offer.available_units', 2);
    }

    public function test_omits_properties_whose_offers_are_for_other_dates(): void
    {
        $property = Property::factory()->create();
        $this->offerFor($property, ['check_in' => '2026-11-01', 'check_out' => '2026-11-05']);

        $this->search()->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_omits_offers_that_cannot_host_the_requested_guests(): void
    {
        $property = Property::factory()->create();
        $this->offerFor($property, ['max_guests' => 2, 'price' => 10000]);
        $roomy = $this->offerFor($property, ['max_guests' => 6, 'price' => 50000]);

        $this->search(['guests' => 5])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.best_offer.id', $roomy->id);
    }

    public function test_omits_offers_without_free_units(): void
    {
        $property = Property::factory()->create();
        $this->offerFor($property, ['price' => 10000, 'available_units' => 1, 'reserved_units' => 1]);
        $bookable = $this->offerFor($property, ['price' => 50000]);

        $this->search()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.best_offer.id', $bookable->id);
    }

    public function test_omits_expired_offers(): void
    {
        $property = Property::factory()->create();
        $this->offerFor($property, ['price' => 10000, 'expires_at' => now()->subMinute()]);
        $live = $this->offerFor($property, ['price' => 50000]);

        $this->search()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.best_offer.id', $live->id);
    }

    public function test_filters_by_city(): void
    {
        $barcelona = Property::factory()->create(['city' => 'Barcelona']);
        $madrid = Property::factory()->create(['city' => 'Madrid']);
        $this->offerFor($barcelona);
        $this->offerFor($madrid);

        $this->search(['city' => 'Barcelona'])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', $barcelona->code);
    }

    public function test_orders_by_price_and_paginates(): void
    {
        foreach ([30000, 10000, 20000] as $price) {
            $this->offerFor(Property::factory()->create(), ['price' => $price]);
        }

        $firstPage = $this->search(['per_page' => 2]);

        $firstPage->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.best_offer.price', 10000)
            ->assertJsonPath('data.1.best_offer.price', 20000)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('links.prev', null);

        $this->assertNotNull($firstPage->json('links.next'));

        $this->getJson($firstPage->json('links.next'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.best_offer.price', 30000)
            ->assertJsonPath('links.next', null);
    }

    public function test_the_number_of_queries_does_not_grow_with_the_number_of_properties(): void
    {
        foreach (range(1, 3) as $ignored) {
            $this->offerFor(Property::factory()->create());
        }
        $forThree = $this->countQueriesDuringSearch();

        foreach (range(1, 6) as $ignored) {
            $this->offerFor(Property::factory()->create());
        }
        $forNine = $this->countQueriesDuringSearch();

        $this->assertSame($forThree, $forNine);
    }

    public function test_returns_422_when_the_search_dates_are_missing(): void
    {
        $this->getJson(route('properties.index'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['check_in', 'check_out', 'guests']);
    }

    public function test_returns_422_when_check_out_is_not_after_check_in(): void
    {
        $this->search(['check_out' => self::CHECK_IN])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['check_out']);
    }

    private function countQueriesDuringSearch(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->search()->assertOk();

        $count = count(DB::getRawQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function search(array $overrides = []): TestResponse
    {
        return $this->getJson(route('properties.index', array_merge([
            'check_in' => self::CHECK_IN,
            'check_out' => self::CHECK_OUT,
            'guests' => 2,
        ], $overrides)));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function offerFor(Property $property, array $attributes = [], ?string $supplierCode = null): Offer
    {
        $supplier = $supplierCode === null
            ? Supplier::factory()->create()
            : Supplier::firstOrCreate(['code' => $supplierCode], ['name' => $supplierCode]);

        return Offer::factory()->for($property)->for($supplier)->create(array_merge([
            'check_in' => self::CHECK_IN,
            'check_out' => self::CHECK_OUT,
        ], $attributes));
    }
}
