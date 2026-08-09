<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RateService;
use Illuminate\Http\JsonResponse;

class RateController extends Controller
{
    public function index(RateService $rates): JsonResponse
    {
        $payload = $rates->publicPayload();

        if (! $payload) {
            return response()->json([
                'message' => 'No rate schedule is currently in effect.',
            ], 404);
        }

        return response()->json($payload);
    }
}
