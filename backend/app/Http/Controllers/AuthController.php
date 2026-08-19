<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Mail\WelcomeNewUser;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->only('email', 'password');

        if (!Auth::attempt($credentials)) {
            return response()->json(['message' => 'Incorrect email or password.'], 401);
        }

        $user = Auth::user();
        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar_id' => $user->avatar_id,
                'phone' => $user->phone,
            ],
        ]);
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => null,
            'email' => $request->email,
            'password' => $request->password,
        ]);

        Mail::to($user)->queue(new WelcomeNewUser($user));

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar_id' => $user->avatar_id,
                'phone' => $user->phone,
            ],
        ], 201);
    }

    public function logout(Request $request): JsonResponse
    {
        $tokenString = $request->bearerToken();

        if ($tokenString) {
            $model = Sanctum::personalAccessTokenModel();
            $token = $model::findToken($tokenString);

            if ($token) {
                $token->delete();
            }
        }

        return response()->json(['message' => 'Logged out']);
    }
}
