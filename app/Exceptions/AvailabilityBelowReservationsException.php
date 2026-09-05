<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;

class AvailabilityBelowReservationsException extends Exception implements ShouldntReport
{
    /**
     * @param  string  $externalId
     * @param  int  $availableUnits
     * @param  int  $reservedUnits
     * @return self
     */
    public static function forOffer(string $externalId, int $availableUnits, int $reservedUnits): self
    {
        return new self(
            "Offer {$externalId} cannot set available_units to {$availableUnits} while {$reservedUnits} units are reserved."
        );
    }
}
