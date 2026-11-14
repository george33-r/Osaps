<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\FabricResource;
use App\Services\TrendingFabricService;
use Illuminate\Http\JsonResponse;

class TrendingFabricController extends Controller
{
    public function __invoke(TrendingFabricService $trending): JsonResponse
    {
        return response()->json(['data' => $trending->top()->map(fn (array $row) => [
            'fabric' => FabricResource::make($row['fabric'])->resolve(),
            'trend' => ['orders' => $row['orders'], 'buyers' => $row['buyers'], 'window_days' => TrendingFabricService::WINDOW_DAYS],
        ])->values()]);
    }
}
