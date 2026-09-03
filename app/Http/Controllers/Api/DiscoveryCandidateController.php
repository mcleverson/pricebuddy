<?php

namespace App\Http\Controllers\Api;

use App\Actions\IngestProductCandidateAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\DiscoveryCandidateRequest;
use App\Filament\Resources\ProductResource\Api\Transformers\ProductTransformer;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Discovery')]
class DiscoveryCandidateController extends Controller
{
    public function __construct(
        protected IngestProductCandidateAction $ingestCandidate,
    ) {}

    /**
     * Ingest a product candidate collected by an external discovery source.
     */
    public function __invoke(DiscoveryCandidateRequest $request): JsonResponse
    {
        $result = ($this->ingestCandidate)($request->validated());

        if ($result['conflict']) {
            return response()->json([
                'message' => 'The URL already belongs to another product.',
            ], 409);
        }

        return response()->json([
            'data' => new ProductTransformer($result['product']),
            'created' => $result['created'],
            'url_created' => $result['url_created'],
            'message' => $result['created'] ? 'Product candidate ingested' : 'Product already exists',
        ], $result['created'] ? 201 : 200);
    }
}
