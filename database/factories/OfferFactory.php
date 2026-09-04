<?php

namespace Database\Factories;

use App\Models\Offer;
use App\Models\Property;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Offer>
 */
class OfferFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'supplier_id' => Supplier::factory(),
            'property_id' => Property::factory(),
            'import_id' => null,
            'external_id' => 'offer-'.fake()->unique()->bothify('#####'),
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-15',
            'max_guests' => 4,
            'price' => fake()->numberBetween(10_000, 200_000),
            'currency' => 'EUR',
            'available_units' => 2,
            'reserved_units' => 0,
            'expires_at' => now()->addDays(7),
            'source_sent_at' => now(),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'expires_at' => now()->subMinute(),
        ]);
    }

    public function soldOut(): static
    {
        return $this->state(fn (array $attributes): array => [
            'reserved_units' => $attributes['available_units'],
        ]);
    }

    public function lastUnit(): static
    {
        return $this->state(fn (): array => [
            'available_units' => 1,
            'reserved_units' => 0,
        ]);
    }
}
