<?php

namespace Database\Factories;

use App\Enums\ImportStatus;
use App\Models\Import;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Import>
 */
class ImportFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'supplier_id' => Supplier::factory(),
            'external_import_id' => 'import-'.fake()->unique()->bothify('####-????'),
            'sent_at' => now(),
            'status' => ImportStatus::Pending,
            'total_offers' => 0,
            'processed_offers' => 0,
            'error' => null,
            'payload' => ['offers' => []],
            'completed_at' => null,
        ];
    }

    /**
     * @param  int  $offers
     * @return static
     */
    public function completed(int $offers = 0): static
    {
        return $this->state(fn (): array => [
            'status' => ImportStatus::Completed,
            'total_offers' => $offers,
            'processed_offers' => $offers,
            'completed_at' => now(),
        ]);
    }

    /**
     * @param  string  $error
     * @return static
     */
    public function failed(string $error = 'Something went wrong'): static
    {
        return $this->state(fn (): array => [
            'status' => ImportStatus::Failed,
            'error' => $error,
            'completed_at' => now(),
        ]);
    }
}
