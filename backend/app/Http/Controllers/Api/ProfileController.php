<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProfileRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->only('name', 'avatar_id', 'phone');

        if (array_key_exists('phone', $data)) {
            $data['phone'] = $data['phone'] !== null ? trim($data['phone']) : null;
        }

        $user->update($data);

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'avatar_id' => $user->avatar_id,
            'phone' => $user->phone,
        ]);
    }
}
