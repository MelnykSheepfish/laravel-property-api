<?php

namespace App\Services;

use App\Exceptions\OfferNotBookableException;
use App\Models\Offer;
use App\Models\Reservation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

class ReservationService
{
    /**
     * @param  Offer  $offer
     * @param  array{client_reference: string, customer_name: string, customer_email: string}  $data
     * @return Reservation
     *
     * @throws OfferNotBookableException
     * @throws Throwable
     */
    public function create(Offer $offer, array $data): Reservation
    {
        return DB::transaction(function () use ($offer, $data): Reservation {
            // Block concurrent last-unit bookings
            $locked = Offer::query()->whereKey($offer->getKey())
                ->lockForUpdate()->firstOrFail();

            $existing = $this->reservationFor($data['client_reference']);

            if ($existing !== null) {
                return $this->sameOfferOrFail($existing, $locked);
            }

            if ($locked->isExpired()) {
                throw OfferNotBookableException::expired();
            }

            if ($locked->unitsLeft() < 1) {
                throw OfferNotBookableException::soldOut();
            }

            try {
                $reservation = $locked->reservations()->create([
                    'client_reference' => $data['client_reference'],
                    'customer_name' => $data['customer_name'],
                    'customer_email' => $data['customer_email'],
                    'units' => 1,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Duplicate reference after insert
                $concurrent = $this->reservationFor($data['client_reference']);

                if ($concurrent === null) {
                    throw OfferNotBookableException::referenceUsedElsewhere();
                }

                return $this->sameOfferOrFail($concurrent, $locked);
            }

            $locked->increment('reserved_units');

            return $reservation;
        });
    }

    /**
     * @param  string  $clientReference
     * @return Reservation|null
     */
    private function reservationFor(string $clientReference): ?Reservation
    {
        return Reservation::query()
            ->where('client_reference', $clientReference)
            ->first();
    }

    /**
     * @param  Reservation  $existing
     * @param  Offer  $offer
     * @return Reservation
     *
     * @throws OfferNotBookableException
     */
    private function sameOfferOrFail(Reservation $existing, Offer $offer): Reservation
    {
        if ($existing->offer_id !== $offer->id) {
            throw OfferNotBookableException::referenceUsedElsewhere();
        }

        return $existing;
    }
}
