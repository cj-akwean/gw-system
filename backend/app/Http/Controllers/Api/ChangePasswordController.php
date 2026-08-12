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
     * Emails a 6-digit code that must accompany the password change.
     */
    public function sendCode(Request $request): JsonResponse
    {
        app(OtpService::class)->send($request->user(), OtpService::PASSWORD_CHANGE);

        return response()->json(['message' => 'Verification code sent to your email.']);
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
