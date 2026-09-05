<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SearchPropertiesRequest;
use App\Http\Resources\PropertyResource;
use App\Queries\AvailablePropertiesQuery;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PropertyController extends Controller
{
    public function index(SearchPropertiesRequest $request, AvailablePropertiesQuery $properties): AnonymousResourceCollection
    {
        $page = $properties->paginate(
            $request->filters(),
            (int) ($request->validated('per_page') ?? 15),
        );

        return PropertyResource::collection($page)->preserveQuery();
    }
}
