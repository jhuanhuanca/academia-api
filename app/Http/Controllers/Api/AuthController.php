<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Mail\PasswordResetMail;
use App\Models\User;
use App\Services\Tenancy\TenantRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private const LOGIN_MAX_ATTEMPTS = 3;

    private const LOGIN_DECAY_SECONDS = 600; // 10 minutos

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

        $throttleKey = $this->loginThrottleKey($credentials['email'], $request->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, self::LOGIN_MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            throw ValidationException::withMessages([
                'email' => [
                    'Demasiados intentos fallidos. Espera '.$this->formatWait($seconds).' o recupera tu contraseña.',
                ],
            ])->status(429);
        }

        /** @var User|null $user */
        $user = User::query()
            ->where('email', $credentials['email'])
            ->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            RateLimiter::hit($throttleKey, self::LOGIN_DECAY_SECONDS);
            $remaining = max(0, self::LOGIN_MAX_ATTEMPTS - RateLimiter::attempts($throttleKey));

            throw ValidationException::withMessages([
                'email' => [
                    $remaining > 0
                        ? 'Credenciales incorrectas. Te quedan '.$remaining.' intento(s).'
                        : 'Credenciales incorrectas. Cuenta bloqueada temporalmente por 10 minutos.',
                ],
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

        RateLimiter::clear($throttleKey);

        $user->forceFill(['last_login_at' => now()])->save();

        $token = $user->createToken($credentials['device_name'] ?? 'marketluna-web')->plainTextToken;

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => new UserResource($user->load('tenant')),
        ]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
        ]);

        $email = Str::lower($data['email']);
        $throttleKey = 'forgot-password:'.$email.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 3)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            throw ValidationException::withMessages([
                'email' => ['Espera '.$this->formatWait($seconds).' antes de solicitar otro correo.'],
            ])->status(429);
        }

        RateLimiter::hit($throttleKey, 300);

        /** @var User|null $user */
        $user = User::query()->where('email', $data['email'])->first();

        // Respuesta genérica (no filtrar si el email existe)
        $generic = [
            'message' => 'Si el email está registrado, te enviamos un enlace para crear una nueva contraseña.',
        ];

        if (! $user || ! $user->is_active || $user->approval_status !== 'approved') {
            return response()->json($generic);
        }

        $plainToken = Str::random(64);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            [
                'token' => Hash::make($plainToken),
                'created_at' => now(),
            ]
        );

        $frontend = rtrim((string) config('services.frontend_url', config('app.url')), '/');
        $resetUrl = $frontend.'/reset-password?token='.urlencode($plainToken).'&email='.urlencode($user->email);

        try {
            Mail::to($user->email)->send(new PasswordResetMail($resetUrl, $user->name));
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'No se pudo enviar el correo. Revisa la configuración de mail o contacta a soporte.',
            ], 503);
        }

        return response()->json($generic);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'token' => ['required', 'string', 'min:20'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
        ]);

        $row = DB::table('password_reset_tokens')
            ->where('email', $data['email'])
            ->first();

        if (! $row || ! Hash::check($data['token'], $row->token)) {
            throw ValidationException::withMessages([
                'token' => ['El enlace no es válido o ya fue usado.'],
            ]);
        }

        if (! $row->created_at || now()->subMinutes(60)->gt($row->created_at)) {
            DB::table('password_reset_tokens')->where('email', $data['email'])->delete();
            throw ValidationException::withMessages([
                'token' => ['El enlace expiró. Solicita uno nuevo.'],
            ]);
        }

        /** @var User|null $user */
        $user = User::query()->where('email', $data['email'])->first();
        if (! $user) {
            throw ValidationException::withMessages([
                'email' => ['Usuario no encontrado.'],
            ]);
        }

        $user->forceFill([
            'password' => $data['password'],
        ])->save();

        DB::table('password_reset_tokens')->where('email', $data['email'])->delete();
        RateLimiter::clear($this->loginThrottleKey($user->email, $request->ip()));

        $user->tokens()->delete();

        return response()->json([
            'message' => 'Contraseña actualizada. Ya puedes iniciar sesión.',
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

    private function loginThrottleKey(string $email, ?string $ip): string
    {
        return 'login:'.Str::lower($email).'|'.($ip ?: 'unknown');
    }

    private function formatWait(int $seconds): string
    {
        if ($seconds >= 60) {
            return (int) ceil($seconds / 60).' min';
        }

        return $seconds.' s';
    }
}
