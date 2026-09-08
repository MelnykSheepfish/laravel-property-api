<?php

namespace App\Queries;

use App\Models\Offer;
use App\Models\Property;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

class AvailablePropertiesQuery
{
    /**
     * @param  array{check_in: string, check_out: string, guests: int, city: string|null}  $filters
     * @param  int  $perPage
     * @return LengthAwarePaginator
     */
    public function paginate(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        // Rank cheapest bookable offer first
        $bookableOffers = Offer::query()
            ->select([
                'offers.id',
                'offers.property_id',
                'offers.supplier_id',
                'offers.price',
                'offers.currency',
                'offers.available_units',
                'offers.reserved_units',
                'offers.expires_at',
            ])
            ->selectRaw(
                'ROW_NUMBER() OVER (PARTITION BY offers.property_id ORDER BY offers.price ASC, offers.id ASC) AS price_rank'
            )
            ->where('offers.check_in', $filters['check_in'])
            ->where('offers.check_out', $filters['check_out'])
            ->where('offers.max_guests', '>=', $filters['guests'])
            ->whereColumn(
                'offers.reserved_units',
                '<',
                'offers.available_units'
            )
            ->where('offers.expires_at', '>', now());

        return Property::query()
            ->joinSub(
                $bookableOffers,
                'best_offer',
                fn (JoinClause $join) => $join
                    ->on('best_offer.property_id', '=', 'properties.id')
                    ->where('best_offer.price_rank', 1)
            )
            ->join('suppliers', 'suppliers.id', '=', 'best_offer.supplier_id')
            ->when(
                $filters['city'] ?? null,
                fn ($query, string $city) => $query->where(
                    'properties.city',
                    $city
                )
            )
            ->select([
                'properties.id',
                'properties.code',
                'properties.name',
                'properties.city',
                'best_offer.id as best_offer_id',
                'suppliers.code as best_offer_supplier',
                'best_offer.price as best_offer_price',
                'best_offer.currency as best_offer_currency',
                'best_offer.expires_at as best_offer_expires_at',
                DB::raw(
                    '(best_offer.available_units - best_offer.reserved_units) as best_offer_available_units'
                ),
            ])
            ->withCasts([
                'best_offer_price' => 'integer',
                'best_offer_available_units' => 'integer',
                'best_offer_expires_at' => 'datetime',
            ])
            ->orderBy('best_offer.price')
            ->orderBy('properties.id')
            ->paginate($perPage);
    }
}
