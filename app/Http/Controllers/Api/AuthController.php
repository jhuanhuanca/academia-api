<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Tenancy\TenantRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request, TenantRegistrationService $registration): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
            'business_name' => ['nullable', 'string', 'max:160'],
            'device_name' => ['nullable', 'string', 'max:80'],
        ]);

        $result = $registration->register([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'business_name' => $data['business_name'] ?? $data['name'],
        ]);

        $mailSent = (bool) ($result['mail_sent'] ?? false);

        return response()->json([
            'pending_approval' => true,
            'mail_sent' => $mailSent,
            'message' => $mailSent
                ? 'Registro recibido. Un administrador debe aprobar tu cuenta antes de que puedas entrar.'
                : 'Registro recibido, pero no se pudo notificar al administrador por email. Contacta a soporte.',
            'user' => [
                'name' => $result['user']->name,
                'email' => $result['user']->email,
                'business_name' => $result['tenant']->name,
            ],
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:80'],
        ]);

        /** @var User|null $user */
        $user = User::query()
            ->where('email', $credentials['email'])
            ->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Credenciales incorrectas.'],
            ]);
        }

        if ($user->approval_status === 'pending' || (! $user->is_active && $user->approval_status !== 'rejected')) {
            throw ValidationException::withMessages([
                'email' => ['Tu cuenta está pendiente de aprobación. Espera el OK del administrador.'],
            ]);
        }

        if ($user->approval_status === 'rejected' || ! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['Tu cuenta fue rechazada o está desactivada. Contacta al administrador.'],
            ]);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        $token = $user->createToken($credentials['device_name'] ?? 'marketluna-web')->plainTextToken;

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => new UserResource($user->load('tenant')),
        ]);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load('tenant'));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Sesión cerrada']);
    }
}
