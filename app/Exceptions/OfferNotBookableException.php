<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class OfferNotBookableException extends Exception implements ShouldntReport
{
    /**
     * @return self
     */
    public static function expired(): self
    {
        return new self('The offer has expired');
    }

    /**
     * @return self
     */
    public static function soldOut(): self
    {
        return new self('The offer has no units left');
    }

    /**
     * @return self
     */
    public static function referenceUsedElsewhere(): self
    {
        return new self(
            'The client reference is already used for another offer'
        );
    }

    /**
     * @param  Request  $request
     *
     * @return JsonResponse
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()],
            Response::HTTP_CONFLICT);
    }
}
