<?php

namespace Tests\Feature\Http\Controllers\Api;

use App\Models\Import;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowImportControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_the_current_state_of_a_completed_import(): void
    {
        $this->freezeTime();
        $supplier = Supplier::factory()->create(['code' => 'supplier-a']);
        $import = Import::factory()
            ->for($supplier)
            ->completed(offers: 20)
            ->create([
                'external_import_id' => 'import-2026-09-01-001',
                'sent_at' => '2026-09-01T10:00:00Z',
            ]);

        $this->getJson(route('imports.show', $import))
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'id' => $import->id,
                    'supplier' => 'supplier-a',
                    'external_import_id' => 'import-2026-09-01-001',
                    'sent_at' => '2026-09-01T10:00:00Z',
                    'status' => 'completed',
                    'total_offers' => 20,
                    'processed_offers' => 20,
                    'error' => null,
                    'created_at' => $import->created_at->toIso8601ZuluString(),
                    'completed_at' => $import->completed_at->toIso8601ZuluString(),
                ],
            ]);
    }

    public function test_reports_the_error_of_a_failed_import(): void
    {
        $import = Import::factory()->failed('Data too long for column')->create();

        $this->getJson(route('imports.show', $import))
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error', 'Data too long for column');
    }

    public function test_returns_404_for_an_unknown_import(): void
    {
        $this->getJson(route('imports.show', 404))->assertNotFound();
    }
}
