<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\PasswordResetOtp;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use SensitiveParameter;

class ForgotPasswordController extends Controller
{
    /**
     * Emails a 6-digit reset code (the broker's token, single-use, 15-minute
     * expiry). Always responds with the same generic message so an existing
     * account can't be enumerated.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        Password::broker()->sendResetLink(
            $request->only('email'),
            function (CanResetPassword $user, #[SensitiveParameter] string $token): void {
                Mail::to($user)->queue(new PasswordResetOtp($user, $token));
            },
        );

        return response()->json([
            'message' => 'If an account exists for that email, a verification code is on its way.',
        ]);
    }
}
