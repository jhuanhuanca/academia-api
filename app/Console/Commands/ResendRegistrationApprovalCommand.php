<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Tenancy\TenantRegistrationService;
use Illuminate\Console\Command;

class ResendRegistrationApprovalCommand extends Command
{
    protected $signature = 'registration:resend-approval {user? : ID o email del usuario pendiente}';

    protected $description = 'Reenvía el email de aprobación de registro al admin (huancajuan863@gmail.com)';

    public function handle(TenantRegistrationService $registration): int
    {
        $arg = $this->argument('user');

        $query = User::query()->where('approval_status', 'pending')->with('tenant');

        if ($arg) {
            if (ctype_digit((string) $arg)) {
                $query->where('id', (int) $arg);
            } else {
                $query->where('email', strtolower((string) $arg));
            }
        } else {
            $query->latest('id');
        }

        $user = $query->first();
        if (! $user) {
            $this->error('No hay usuario pendiente de aprobación'.($arg ? " ({$arg})" : ''));

            return self::FAILURE;
        }

        $this->info("Reenviando aprobación de #{$user->id} {$user->email} → ".config('services.registration.approval_email'));

        $ok = $registration->resendApprovalEmail($user);

        if (! $ok) {
            $this->error('No se pudo enviar. Revisa MAIL_MAILER=brevo, BREVO_API_KEY y storage/logs/laravel.log');

            return self::FAILURE;
        }

        $this->info('Email enviado correctamente.');

        return self::SUCCESS;
    }
}
