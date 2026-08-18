<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendOtpSms;
use App\Mail\PasswordResetOtp;
use App\Models\User;
use App\Services\SmsService;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use SensitiveParameter;

class ForgotPasswordController extends Controller
{
    /**
     * Sends a 6-digit reset code (the broker's token, single-use, 15-minute
     * expiry) by email (default) or SMS. Always responds with the same generic
     * message so an existing account can't be enumerated.
     *
     * When SMS is requested but the account has no phone number, delivery
     * silently falls back to email — no double-send, and the generic response
     * keeps account existence hidden either way.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'channel' => ['sometimes', 'string', 'in:email,sms'],
        ]);

        $channel = $request->input('channel', 'email');

        Password::broker()->sendResetLink(
            $request->only('email'),
            function (CanResetPassword $user, #[SensitiveParameter] string $token) use ($channel): void {
                $useSms = $channel === 'sms'
                    && $user instanceof User
                    && is_string($user->phone)
                    && trim($user->phone) !== '';

                if ($useSms) {
                    SendOtpSms::dispatch($user->phone, $token, SmsService::OTP_MESSAGE);

                    return;
                }

                Mail::to($user)->queue(new PasswordResetOtp($user, $token));
            },
        );

        return response()->json([
            'message' => 'If an account exists for that email, a verification code is on its way.',
        ]);
    }
}
