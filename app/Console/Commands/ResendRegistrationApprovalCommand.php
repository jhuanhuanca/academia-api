<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Tenancy\TenantRegistrationService;
use Illuminate\Console\Command;

class ResendRegistrationApprovalCommand extends Command
{
    protected $signature = 'registration:resend-approval {user? : ID o email del usuario pendiente}';

    protected $description = 'Reenvía el email de aprobación de registro al admin';

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

        $this->table(['Clave', 'Valor'], [
            ['Usuario', "#{$user->id} {$user->email}"],
            ['Admin destino', (string) config('services.registration.approval_email')],
            ['MAIL_MAILER', (string) config('mail.default')],
            ['MAIL_HOST', (string) config('mail.mailers.smtp.host')],
            ['MAIL_PORT', (string) config('mail.mailers.smtp.port')],
            ['MAIL_FROM', (string) config('mail.from.address')],
            ['BREVO_API_KEY', config('services.brevo.key') ? 'sí (oculta)' : 'no'],
        ]);

        $result = $registration->resendApprovalEmail($user);

        if (! ($result['ok'] ?? false)) {
            $this->error('No se pudo enviar el email.');
            foreach ($result['errors'] ?? ['Error desconocido'] as $err) {
                $this->line(' - '.$err);
            }
            $this->newLine();
            $this->line('Revisa también: grep -i "aprobación\|Fallo mailer" storage/logs/laravel.log | tail -n 30');

            return self::FAILURE;
        }

        $this->info('Email enviado con mailer: '.($result['mailer'] ?? '?').' → '.($result['to'] ?? ''));

        return self::SUCCESS;
    }
}
