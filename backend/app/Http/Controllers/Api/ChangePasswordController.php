<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use App\Mail\PasswordChanged;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class ChangePasswordController extends Controller
{
    /**
     * Sends a 6-digit code (email by default, SMS when chosen) that must
     * accompany the password change.
     */
    public function sendCode(Request $request): JsonResponse
    {
        $channel = $request->validate([
            'channel' => ['sometimes', 'string', 'in:email,sms'],
        ])['channel'] ?? 'email';

        $user = $request->user();

        if ($channel === 'sms' && (! is_string($user->phone) || trim($user->phone) === '')) {
            return response()->json([
                'message' => 'Add a phone number in Settings first.',
            ], 422);
        }

        app(OtpService::class)->send($user, OtpService::PASSWORD_CHANGE, $channel);

        return response()->json([
            'message' => $channel === 'sms'
                ? 'Verification code sent to your phone ('.$user->phone.').'
                : 'Verification code sent to your email.',
        ]);
    }

    public function store(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->update(['password' => $request->password]);

        // Revoke every other device's token; the current session stays.
        $currentToken = $user->currentAccessToken();
        $tokens = $user->tokens();
        if ($currentToken !== null) {
            $tokens->where('id', '!=', $currentToken->id);
        }
        $tokens->delete();

        Mail::to($user)->queue(new PasswordChanged($user));

        return response()->json(['message' => 'Password updated.']);
    }
}
