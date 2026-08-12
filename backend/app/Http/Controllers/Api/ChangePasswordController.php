<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use App\Mail\PasswordChanged;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;

class ChangePasswordController extends Controller
{
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
