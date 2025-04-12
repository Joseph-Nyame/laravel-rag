<?php

namespace App\Services;

use App\Models\User;
use App\DTOs\AuthDTO;
use App\DTOs\RegularAuthDTO;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Auth\AuthenticationException;

class AuthService
{
    public function register(AuthDTO $dto): array
    {
        if ($dto instanceof RegularAuthDTO) {
            $user = User::create([
                'name' => $dto->name,
                'email' => $dto->email,
                'password' => Hash::make($dto->password),
            ]);

            $token = $user->createToken('rag-service')->plainTextToken;

            return [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
                'token' => $token,
            ];
        }

        throw new \InvalidArgumentException('Unsupported registration type');
    }

    public function login(AuthDTO $dto): array
    {
        if ($dto instanceof RegularAuthDTO) {
            $user = User::where('email', $dto->email)->first();

            if (!$user || !Hash::check($dto->password, $user->password)) {
                throw new AuthenticationException('Invalid credentials');
            }

            $token = $user->createToken('rag-service')->plainTextToken;

            return [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
                'token' => $token,
            ];
        }

        throw new \InvalidArgumentException('Unsupported login type');
    }

    public function logout(Request  $request): void
    {
        $request->user()->currentAccessToken()->delete();
    }
}