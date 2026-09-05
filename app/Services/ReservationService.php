<?php

namespace App\Services;

use App\Exceptions\OfferNotBookableException;
use App\Models\Offer;
use App\Models\Reservation;
use Illuminate\Support\Facades\DB;
use Throwable;

class ReservationService
{
    /**
     * @param  Offer  $offer
     * @param  array  $data
     *
     * @return Reservation
     * @throws Throwable
     */
    public function create(Offer $offer, array $data): Reservation
    {
        return DB::transaction(function () use ($offer, $data): Reservation {
            $locked = Offer::query()->whereKey($offer->getKey())
                ->lockForUpdate()->firstOrFail();

            $existing = Reservation::query()
                ->where('client_reference', $data['client_reference'])
                ->first();

            if ($existing !== null) {
                if ($existing->offer_id !== $locked->id) {
                    throw OfferNotBookableException::referenceUsedElsewhere();
                }

                return $existing;
            }

            if ($locked->isExpired()) {
                throw OfferNotBookableException::expired();
            }

            if ($locked->unitsLeft() < 1) {
                throw OfferNotBookableException::soldOut();
            }

            $locked->increment('reserved_units');

            return $locked->reservations()->create([
                'client_reference' => $data['client_reference'],
                'customer_name' => $data['customer_name'],
                'customer_email' => $data['customer_email'],
                'units' => 1,
            ]);
        });
    }
}
