<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SavedPaymentMethod;
use App\Services\PayMongoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Client\ConnectionException;
use RuntimeException;

class SavedPaymentMethodController extends Controller
{
    /**
     * Lists saved payment methods for the authenticated user.
     * Syncs from PayMongo (source of truth) before returning.
     */
    public function index(Request $request, PayMongoService $payMongo): JsonResponse
    {
        $user = $request->user();

        try {
            $customerId = $payMongo->getOrCreateCustomer($user);
            $remoteMethods = $payMongo->listCustomerPaymentMethods($customerId);
        } catch (ConnectionException|RuntimeException $e) {
            report($e);

            return response()->json([
                'message' => 'Payment gateway unavailable. Please try again.',
            ], 502);
        }

        $remoteIds = array_column($remoteMethods, 'id');

        // Remove local records that no longer exist on PayMongo
        $user->savedPaymentMethods()
            ->whereNotIn('paymongo_payment_method_id', $remoteIds)
            ->delete();

        // Sync each remote method to local
        foreach ($remoteMethods as $remote) {
            SavedPaymentMethod::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'paymongo_payment_method_id' => $remote['id'],
                ],
                [
                    'brand' => $remote['brand'],
                    'last4' => $remote['last4'],
                    'exp_month' => $remote['exp_month'],
                    'exp_year' => $remote['exp_year'],
                ]
            );
        }

        return response()->json([
            'data' => $user->savedPaymentMethods()
                ->orderByDesc('created_at')
                ->get(),
        ]);
    }

    /**
     * Deletes a saved payment method from PayMongo and locally.
     */
    public function destroy(Request $request, SavedPaymentMethod $savedPaymentMethod, PayMongoService $payMongo): JsonResponse
    {
        $user = $request->user();

        if ($savedPaymentMethod->user_id !== $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $customerId = $user->paymongo_customer_id;

        if ($customerId === null) {
            return response()->json(['message' => 'No payment methods on file.'], 404);
        }

        try {
            $payMongo->deletePaymentMethod($customerId, $savedPaymentMethod->paymongo_payment_method_id);
        } catch (ConnectionException|RuntimeException $e) {
            report($e);

            return response()->json([
                'message' => 'Payment gateway unavailable. Please try again.',
            ], 502);
        }

        $savedPaymentMethod->delete();

        return response()->json(['message' => 'Payment method deleted.']);
    }
}
