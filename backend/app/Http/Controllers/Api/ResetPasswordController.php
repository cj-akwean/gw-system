<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class ResetPasswordController extends Controller
{
    /**
     * Resets a forgotten password with the emailed 6-digit code. All tokens
     * are revoked (forgot-password sessions are anonymous).
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'otp' => ['required', 'string', 'size:6'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::min(8)],
        ]);

        $status = Password::broker()->reset(
            [
                'email' => $request->email,
                'token' => $request->otp,
                'password' => $request->password,
            ],
            function (User $user) use ($request): void {
                $user->forceFill([
                    $user->getAuthPasswordName() => Hash::make($request->password),
                ])->save();

                $user->tokens()->delete();
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'otp' => 'That code is invalid or has expired.',
            ]);
        }

        return response()->json(['message' => 'Password reset. You can now sign in.']);
    }
}
