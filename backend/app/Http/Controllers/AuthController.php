<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\GoogleAuthRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Mail\WelcomeNewUser;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
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

    public function google(GoogleAuthRequest $request): JsonResponse
    {
        $response = Http::get('https://oauth2.googleapis.com/tokeninfo', [
            'id_token' => $request->credential,
        ]);

        if (!$response->successful()) {
            return response()->json(['message' => 'Invalid Google sign-in. Please try again.'], 401);
        }

        $info = $response->json();

        // The token must have been minted for OUR OAuth client, not another app.
        if (($info['aud'] ?? null) !== config('services.google.client_id')) {
            return response()->json(['message' => 'Invalid Google sign-in. Please try again.'], 401);
        }

        // Google only verified the email if email_verified is true (tokeninfo
        // returns it as the string "true"/"false").
        if (!filter_var($info['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return response()->json(['message' => 'Your Google email is not verified.'], 401);
        }

        $sub = $info['sub'];
        $email = strtolower($info['email'] ?? '');
        $name = $info['name'] ?? null;

        $user = User::where('google_id', $sub)->first();

        if (!$user) {
            $existing = User::where('email', $email)->first();

            if ($existing) {
                return response()->json([
                    'message' => 'An account with this email already exists. Please log in with your email and password.',
                ], 409);
            }

            $user = User::updateOrCreate(
                ['google_id' => $sub],
                ['name' => $name, 'email' => $email, 'password' => null]
            );
        }

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
