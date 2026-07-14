<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappInstance;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateTenantCommand extends Command
{
    protected $signature = 'tenant:create
        {name : Nombre del negocio / academia}
        {email : Email de login del dueño}
        {password : Contraseña del dueño}
        {--slug= : Slug único (opcional)}
        {--whatsapp= : Nombre de instancia Evolution (único)}
        {--owner= : Nombre del dueño}';

    protected $description = 'Crea un tenant + usuario owner + instancia WhatsApp aislada';

    public function handle(): int
    {
        $name = trim((string) $this->argument('name'));
        $email = strtolower(trim((string) $this->argument('email')));
        $password = (string) $this->argument('password');
        $ownerName = trim((string) ($this->option('owner') ?: $name));
        $slug = Str::slug((string) ($this->option('slug') ?: $name));

        if ($slug === '') {
            $this->error('Slug inválido. Usa --slug=mi-negocio');

            return self::FAILURE;
        }

        if (Tenant::query()->where('slug', $slug)->exists()) {
            $this->error("Ya existe un tenant con slug: {$slug}");

            return self::FAILURE;
        }

        if (User::query()->where('email', $email)->exists()) {
            $this->error("Ya existe un usuario con email: {$email}");

            return self::FAILURE;
        }

        $waName = (string) ($this->option('whatsapp') ?: ('wa-'.$slug));
        $waName = Str::slug($waName);
        if ($waName === '') {
            $waName = 'wa-'.Str::lower(Str::random(6));
        }

        if (WhatsappInstance::query()->where('evolution_instance', $waName)->exists()) {
            $this->error("Ya existe una instancia Evolution llamada: {$waName}");
            $this->line('Usa otro nombre: --whatsapp=cliente-juan');

            return self::FAILURE;
        }

        $tenant = Tenant::create([
            'uuid' => (string) Str::uuid(),
            'name' => $name,
            'slug' => $slug,
            'status' => 'active',
            'plan' => 'personal',
            'timezone' => 'America/Caracas',
            'settings' => [
                'default_payment_provider' => 'manual_qr',
                'locale' => 'es',
                'brand' => $name,
                'ai_name' => 'Luna',
            ],
        ]);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => $ownerName,
            'email' => $email,
            'password' => $password,
            'role' => 'owner',
            'is_active' => true,
            'approval_status' => 'approved',
            'approved_at' => now(),
            'email_verified_at' => now(),
        ]);

        $instance = WhatsappInstance::create([
            'tenant_id' => $tenant->id,
            'name' => 'Principal',
            'evolution_instance' => $waName,
            'evolution_apikey' => null,
            'integration' => 'meta_cloud',
            'status' => 'disconnected',
            'webhook_secret' => Str::random(48),
            'meta' => [],
        ]);

        $this->newLine();
        $this->info('Tenant creado correctamente');
        $this->table(
            ['Campo', 'Valor'],
            [
                ['Tenant ID', $tenant->id],
                ['Negocio', $tenant->name],
                ['Slug', $tenant->slug],
                ['Usuario', $user->name],
                ['Email login', $user->email],
                ['Password', $password],
                ['WhatsApp', 'meta_cloud (Cloud API)'],
            ]
        );
        $this->newLine();
        $this->line('Siguiente:');
        $this->line('1) El usuario entra al panel con su email/password');
        $this->line('2) Va a WhatsApp → activa Cloud API con Phone Number ID + token Meta');
        $this->line('3) Crea su curso, sube su QR de pago y publica su flujo');
        $this->line('4) Sus ventas quedan solo en su tenant (no ve las de otros)');

        return self::SUCCESS;
    }
}
