<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreImportRequest;
use App\Http\Resources\ImportAcceptedResource;
use App\Http\Resources\ImportResource;
use App\Models\Import;
use App\Models\Supplier;
use App\Services\ImportService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ImportController extends Controller
{
    /**
     * @param  StoreImportRequest  $request
     * @param  ImportService  $imports
     * @return JsonResponse
     */
    public function store(StoreImportRequest $request, ImportService $imports): JsonResponse
    {
        $supplier = Supplier::query()->where('code', $request->validated('supplier'))->firstOrFail();

        $import = $imports->create($supplier, $request->validated());

        return ImportAcceptedResource::make($import)
            ->response()
            ->setStatusCode(Response::HTTP_ACCEPTED);
    }

    /**
     * @param  Import  $import
     * @return ImportResource
     */
    public function show(Import $import): ImportResource
    {
        return ImportResource::make($import->load('supplier'));
    }
}
