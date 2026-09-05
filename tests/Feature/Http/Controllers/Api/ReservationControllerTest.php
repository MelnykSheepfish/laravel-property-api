<?php

namespace Tests\Feature\Http\Controllers\Api;

use App\Models\Offer;
use App\Models\Reservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_payload_creates_the_reservation_and_returns_201(
    ): void
    {
        $offer = Offer::factory()->create(['available_units' => 2]);

        $response = $this->postJson(
            route('offers.reservations.store', $offer),
            $this->payload()
        );

        $reservation = Reservation::sole();
        $response->assertCreated()
            ->assertJsonPath('data.id', $reservation->id)
            ->assertJsonPath('data.offer_id', $offer->id)
            ->assertJsonPath('data.client_reference', 'web-order-9f782b1c')
            ->assertJsonPath('data.customer_name', 'John Smith')
            ->assertJsonPath('data.customer_email', 'john@example.com')
            ->assertJsonPath('data.units', 1);

        $this->assertSame(1, $offer->refresh()->reserved_units);
        $this->assertSame(1, $offer->unitsLeft());
    }

    public function test_returns_409_when_the_last_unit_is_already_taken(): void
    {
        $offer = Offer::factory()->lastUnit()->create();
        $this->postJson(
            route('offers.reservations.store', $offer),
            $this->payload()
        )->assertCreated();

        $this->postJson(
            route('offers.reservations.store', $offer),
            $this->payload([
                'client_reference' => 'web-order-second',
            ])
        )
            ->assertConflict()
            ->assertJsonPath('message', 'The offer has no units left.');

        $this->assertSame(1, Reservation::count());
        $this->assertSame(1, $offer->refresh()->reserved_units);
    }

    public function test_returns_409_for_an_expired_offer(): void
    {
        $offer = Offer::factory()->expired()->create();

        $this->postJson(
            route('offers.reservations.store', $offer),
            $this->payload()
        )
            ->assertConflict()
            ->assertJsonPath('message', 'The offer has expired.');

        $this->assertSame(0, Reservation::count());
    }

    public function test_repeating_a_client_reference_returns_the_existing_reservation(
    ): void
    {
        $offer = Offer::factory()->create(['available_units' => 3]);
        $first = $this->postJson(
            route('offers.reservations.store', $offer),
            $this->payload()
        );

        $this->postJson(
            route('offers.reservations.store', $offer),
            $this->payload()
        )
            ->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertSame(1, Reservation::count());
        $this->assertSame(1, $offer->refresh()->reserved_units);
    }

    public function test_returns_409_when_the_client_reference_belongs_to_another_offer(
    ): void
    {
        Reservation::factory()->create(
            ['client_reference' => 'web-order-9f782b1c']
        );
        $offer = Offer::factory()->create();

        $this->postJson(
            route('offers.reservations.store', $offer),
            $this->payload()
        )
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'The client reference is already used for another offer.'
            );
    }

    public function test_returns_422_when_the_customer_email_is_not_an_email(
    ): void
    {
        $offer = Offer::factory()->create();

        $this->postJson(
            route('offers.reservations.store', $offer),
            $this->payload([
                'customer_email' => 'not-an-email',
            ])
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_email']);

        $this->assertSame(0, Reservation::count());
    }

    public function test_returns_422_when_the_payload_is_empty(): void
    {
        $offer = Offer::factory()->create();

        $this->postJson(route('offers.reservations.store', $offer), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(
                ['client_reference', 'customer_name', 'customer_email']
            );
    }

    public function test_returns_404_for_an_unknown_offer(): void
    {
        $this->postJson(
            route('offers.reservations.store', 404),
            $this->payload()
        )->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $overrides
     *
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'client_reference' => 'web-order-9f782b1c',
            'customer_name' => 'John Smith',
            'customer_email' => 'john@example.com',
        ], $overrides);
    }
}
