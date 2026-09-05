<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\OfferNotBookableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReservationRequest;
use App\Http\Resources\ReservationResource;
use App\Models\Offer;
use App\Services\ReservationService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ReservationController extends Controller
{
    /**
     * @param  StoreReservationRequest  $request
     * @param  Offer                    $offer
     * @param  ReservationService       $reservations
     *
     * @return JsonResponse
     * @throws OfferNotBookableException
     * @throws Throwable
     */
    public function store(
        StoreReservationRequest $request,
        Offer $offer,
        ReservationService $reservations
    ): JsonResponse {
        $reservation = $reservations->create($offer, $request->validated());

        return ReservationResource::make($reservation)
            ->response()
            ->setStatusCode(
                $reservation->wasRecentlyCreated ? Response::HTTP_CREATED
                    : Response::HTTP_OK
            );
    }
}
