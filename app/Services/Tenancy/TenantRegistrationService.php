<?php

namespace App\Services\Tenancy;

use App\Mail\RegistrationApprovalMail;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappInstance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class TenantRegistrationService
{
    /**
     * Crea tenant + owner inactivo + WhatsApp. Queda pendiente de aprobación admin.
     *
     * @param  array{name:string,email:string,password:string,business_name?:string}  $data
     * @return array{tenant:Tenant,user:User,whatsapp:WhatsappInstance}
     */
    public function register(array $data): array
    {
        $email = strtolower(trim($data['email']));
        $name = trim($data['name']);
        $businessName = trim((string) ($data['business_name'] ?? $name));

        if (User::query()->where('email', $email)->exists()) {
            throw ValidationException::withMessages([
                'email' => ['Este email ya tiene una cuenta. Inicia sesión o usa otro email.'],
            ]);
        }

        $slug = $this->uniqueSlug($businessName);
        $token = Str::random(64);

        $result = DB::transaction(function () use ($email, $name, $businessName, $slug, $data, $token) {
            $tenant = Tenant::create([
                'uuid' => (string) Str::uuid(),
                'name' => $businessName,
                'slug' => $slug,
                'status' => 'pending',
                'plan' => 'personal',
                'timezone' => 'America/Caracas',
                'settings' => [
                    'default_payment_provider' => 'manual_qr',
                    'locale' => 'es',
                    'brand' => $businessName,
                    'ai_name' => 'Luna',
                ],
            ]);

            $user = User::create([
                'tenant_id' => $tenant->id,
                'name' => $name,
                'email' => $email,
                'password' => $data['password'],
                'role' => 'owner',
                'is_active' => false,
                'approval_status' => 'pending',
                'approval_token' => $token,
                'approval_token_expires_at' => now()->addDays(7),
                'email_verified_at' => null,
            ]);

            $waName = $this->uniqueWhatsappInstance($slug, $tenant->id);

            $whatsapp = WhatsappInstance::create([
                'tenant_id' => $tenant->id,
                'name' => 'Principal',
                'evolution_instance' => $waName,
                'evolution_apikey' => null,
                'integration' => 'baileys',
                'status' => 'disconnected',
                'webhook_secret' => Str::random(48),
                'meta' => [],
            ]);

            return [
                'tenant' => $tenant,
                'user' => $user->load('tenant'),
                'whatsapp' => $whatsapp,
            ];
        });

        $this->notifyAdminForApproval($result['user']);

        return $result;
    }

    /**
     * @return array{ok:bool,reason?:string,user?:User}
     */
    public function approve(User $user, string $token): array
    {
        if ($user->approval_status === 'approved' && $user->is_active) {
            return ['ok' => true, 'user' => $user, 'reason' => 'already_approved'];
        }

        $check = $this->validateToken($user, $token);
        if (! ($check['ok'] ?? false)) {
            return $check;
        }

        DB::transaction(function () use ($user) {
            $user->forceFill([
                'is_active' => true,
                'approval_status' => 'approved',
                'approved_at' => now(),
                'rejected_at' => null,
                'approval_token' => null,
                'approval_token_expires_at' => null,
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();

            $user->tenant?->forceFill(['status' => 'active'])->save();
        });

        return ['ok' => true, 'user' => $user->fresh(['tenant'])];
    }

    /**
     * @return array{ok:bool,reason?:string,user?:User}
     */
    public function reject(User $user, string $token): array
    {
        if ($user->approval_status === 'rejected') {
            return ['ok' => true, 'user' => $user, 'reason' => 'already_rejected'];
        }

        $check = $this->validateToken($user, $token);
        if (! ($check['ok'] ?? false)) {
            return $check;
        }

        DB::transaction(function () use ($user) {
            $user->forceFill([
                'is_active' => false,
                'approval_status' => 'rejected',
                'rejected_at' => now(),
                'approval_token' => null,
                'approval_token_expires_at' => null,
            ])->save();

            $user->tenant?->forceFill(['status' => 'suspended'])->save();
        });

        return ['ok' => true, 'user' => $user->fresh(['tenant'])];
    }

    private function notifyAdminForApproval(User $user): void
    {
        $adminEmail = (string) config('services.registration.approval_email', 'huancajuan863@gmail.com');
        if ($adminEmail === '' || ! filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            Log::error('REGISTRATION_APPROVAL_EMAIL inválido; no se envió aviso de registro', [
                'user_id' => $user->id,
            ]);

            return;
        }

        $token = (string) $user->approval_token;
        $approveUrl = url('/aprobar-registro/'.$user->id.'?token='.$token.'&action=approve');
        $rejectUrl = url('/aprobar-registro/'.$user->id.'?token='.$token.'&action=reject');

        try {
            Mail::to($adminEmail)->send(new RegistrationApprovalMail($user->load('tenant'), $approveUrl, $rejectUrl));
            Log::info('Email de aprobación de registro enviado', [
                'user_id' => $user->id,
                'to' => $adminEmail,
            ]);
        } catch (Throwable $e) {
            Log::error('Error enviando email de aprobación de registro', [
                'user_id' => $user->id,
                'to' => $adminEmail,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array{ok:bool,reason?:string}
     */
    private function validateToken(User $user, string $token): array
    {
        if ($token === '' || ! is_string($user->approval_token) || ! hash_equals($user->approval_token, $token)) {
            // Si ya fue procesado, el token se borra: informar estado actual
            if (in_array($user->approval_status, ['approved', 'rejected'], true) && $user->approval_token === null) {
                return [
                    'ok' => false,
                    'reason' => $user->approval_status === 'approved' ? 'already_approved' : 'already_rejected',
                ];
            }

            return ['ok' => false, 'reason' => 'invalid_token'];
        }

        if ($user->approval_token_expires_at && $user->approval_token_expires_at->isPast()) {
            return ['ok' => false, 'reason' => 'token_expired'];
        }

        return ['ok' => true];
    }

    private function uniqueSlug(string $businessName): string
    {
        $base = Str::slug($businessName);
        if ($base === '') {
            $base = 'negocio';
        }

        $slug = $base;
        $i = 1;
        while (Tenant::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i;
            $i++;
        }

        return $slug;
    }

    private function uniqueWhatsappInstance(string $slug, int $tenantId): string
    {
        $base = 't'.$tenantId.'-'.Str::limit($slug, 40, '');
        $base = Str::slug($base) ?: ('t'.$tenantId.'-wa');
        $name = $base;
        $i = 1;

        while (WhatsappInstance::query()->where('evolution_instance', $name)->exists()) {
            $name = $base.'-'.$i;
            $i++;
        }

        return $name;
    }
}
