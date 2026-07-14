<?php

namespace Database\Seeders;

/*use App\Models\Tenant;*/
use App\Models\User;
/*use App\Models\WhatsappInstance;*/
use App\Services\Tenancy\TenantCatalogBootstrapService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
       /* $tenant = Tenant::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Academia Personal',
            'slug' => 'academia-personal',
            'status' => 'active',
            'plan' => 'personal',
            'timezone' => 'America/Caracas',
            'settings' => [
                'default_payment_provider' => 'manual_qr',
                'locale' => 'es',
                'brand' => 'MarketLuna',
                'ai_name' => 'Luna',
            ],
        ]);*/

        User::create([
            'tenant_id' => 1,
            'name' => 'Juan Huanca',
            'email' => 'jhuanca617@gmail.com',
            'password' => 'jhuanca1997@-luna',
            'role' => 'owner',
            'is_active' => true,
            'approval_status' => 'approved',
            'approved_at' => now(),
            'email_verified_at' => now(),
        ]);

       /* WhatsappInstance::create([
            'tenant_id' => 1,
            'name' => 'Principal',
            'evolution_instance' => 'academia-ventas',
            'evolution_apikey' => null,
            'integration' => 'baileys',
            'status' => 'disconnected',
            'webhook_secret' => Str::random(48),
            'meta' => [],
        ]);*/

        /*app(TenantCatalogBootstrapService::class)->bootstrapIfEmpty($tenant);*/
    }
}
