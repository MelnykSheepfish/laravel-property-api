<?php

namespace App\Models;

use App\Enums\ImportStatus;
use Database\Factories\ImportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Import extends Model
{
    /** @use HasFactory<ImportFactory> */
    use HasFactory;

    protected $fillable = [
        'supplier_id',
        'external_import_id',
        'sent_at',
        'status',
        'total_offers',
        'processed_offers',
        'error',
        'payload',
        'completed_at',
    ];

    protected $attributes = [
        'status' => ImportStatus::Pending->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'completed_at' => 'datetime',
            'status' => ImportStatus::class,
            'payload' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return HasMany<Offer, $this>
     */
    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function offerPayload(): array
    {
        return $this->payload['offers'] ?? [];
    }

    public function markProcessing(): void
    {
        $this->update([
            'status' => ImportStatus::Processing,
            'processed_offers' => 0,
            'error' => null,
            'completed_at' => null,
        ]);
    }

    public function markCompleted(int $processedOffers): void
    {
        $this->update([
            'status' => ImportStatus::Completed,
            'processed_offers' => $processedOffers,
            'error' => null,
            'completed_at' => now(),
        ]);
    }

    public function markFailed(string $error): void
    {
        $this->update([
            'status' => ImportStatus::Failed,
            'error' => Str::limit($error, 1000),
            'completed_at' => now(),
        ]);
    }
}
