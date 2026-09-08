<?php

namespace Database\Factories;

use App\Models\Offer;
use App\Models\Reservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reservation>
 */
class ReservationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'offer_id' => Offer::factory(),
            'client_reference' => 'web-order-'.fake()->unique()->bothify('????####'),
            'customer_name' => fake()->name(),
            'customer_email' => fake()->safeEmail(),
            'units' => 1,
        ];
    }
}
