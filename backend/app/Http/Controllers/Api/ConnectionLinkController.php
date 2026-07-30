<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LinkConnectionRequest;
use App\Models\ConnectionLink;
use App\Models\ServiceConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConnectionLinkController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $links = $request->user()->connectionLinks()
            ->with('serviceConnection.barangay')
            ->where('status', 'active')
            ->get();

        return response()->json($links);
    }

    public function store(LinkConnectionRequest $request): JsonResponse
    {
        $connection = ServiceConnection::where('account_number', $request->account_number)
            ->where('meter_number', $request->meter_number)
            ->firstOrFail();

        $link = ConnectionLink::updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'service_connection_id' => $connection->id,
            ],
            [
                'status' => 'active',
                'linked_at' => now(),
                'unlinked_at' => null,
            ]
        );

        return response()->json(
            $link->load('serviceConnection.barangay'),
            201,
        );
    }

    public function destroy(Request $request, ConnectionLink $link): JsonResponse
    {
        if ($link->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $link->update([
            'status' => 'revoked',
            'unlinked_at' => now(),
        ]);

        return response()->json(['message' => 'Link revoked']);
    }
}
