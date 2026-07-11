<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Limpia datos de prueba sin destruir tu cuenta principal / cursos / WhatsApp.
 */
class PurgeTestDataCommand extends Command
{
    protected $signature = 'app:purge-test-data
        {--keep-email=huancajuan863@gmail.com : Email del owner que se conserva}
        {--conversations-only : Solo borra chats/ventas/leads; no borra otros tenants}
        {--force : No pedir confirmación}';

    protected $description = 'Borra usuarios/tenants de prueba y conversaciones, conservando tu cuenta principal';

    public function handle(): int
    {
        $keepEmail = strtolower(trim((string) $this->option('keep-email')));
        $owner = User::query()->where('email', $keepEmail)->first();

        if (! $owner) {
            $this->error("No encontré el usuario a conservar: {$keepEmail}");
            $this->line('Usa: php artisan app:purge-test-data --keep-email=tu@email.com');

            return self::FAILURE;
        }

        $keepTenantId = (int) $owner->tenant_id;
        $otherTenants = Tenant::query()->where('id', '!=', $keepTenantId)->pluck('id')->all();
        $pendingUsers = User::query()
            ->where('approval_status', 'pending')
            ->where('email', '!=', $keepEmail)
            ->count();

        $this->warn('Se conservará:');
        $this->line(" - Usuario: {$owner->email} (tenant #{$keepTenantId})");
        $this->line(' - Sus cursos, flujos, knowledge y WhatsApp');
        $this->newLine();
        $this->warn('Se borrará:');
        if ($this->option('conversations-only')) {
            $this->line(' - Conversaciones, mensajes, leads, ventas y pagos (de todos los tenants)');
        } else {
            $this->line(' - Todos los otros tenants/usuarios de prueba ('.count($otherTenants).')');
            $this->line(' - Conversaciones/ventas/leads de tu tenant también (chats de prueba)');
            $this->line(" - Usuarios pending ajenos: {$pendingUsers}");
        }

        if (! $this->option('force') && ! $this->confirm('¿Continuar?', false)) {
            $this->info('Cancelado.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($keepTenantId, $otherTenants, $keepEmail) {
            if ($this->option('conversations-only')) {
                $this->purgeOperationalData(null);
            } else {
                foreach ($otherTenants as $tenantId) {
                    $this->purgeTenantFully((int) $tenantId);
                }
                // Chats de prueba de tu cuenta
                $this->purgeOperationalData($keepTenantId);
                // Usuarios pending que no sean el owner (por si quedaron en tu tenant)
                User::query()
                    ->where('email', '!=', $keepEmail)
                    ->where('approval_status', 'pending')
                    ->delete();
            }

            if (Schema::hasTable('webhook_events')) {
                DB::table('webhook_events')->delete();
            }
            if (Schema::hasTable('audit_logs')) {
                if ($this->option('conversations-only')) {
                    DB::table('audit_logs')->delete();
                } else {
                    DB::table('audit_logs')->where('tenant_id', '!=', $keepTenantId)->delete();
                    DB::table('audit_logs')->where('tenant_id', $keepTenantId)->delete();
                }
            }
            if (Schema::hasTable('personal_access_tokens')) {
                DB::table('personal_access_tokens')->delete();
            }
        });

        $this->info('Limpieza lista. Tu cuenta principal sigue intacta.');

        return self::SUCCESS;
    }

    private function purgeTenantFully(int $tenantId): void
    {
        $this->purgeOperationalData($tenantId);

        DB::table('knowledge_items')->where('tenant_id', $tenantId)->delete();

        // Quitar FKs a media antes de borrar assets
        if (Schema::hasColumn('courses', 'payment_qr_media_asset_id')) {
            DB::table('courses')->where('tenant_id', $tenantId)->update(['payment_qr_media_asset_id' => null]);
        }

        $flowIds = DB::table('flows')->where('tenant_id', $tenantId)->pluck('id');
        if ($flowIds->isNotEmpty()) {
            DB::table('flows')->whereIn('id', $flowIds)->update(['start_node_id' => null]);
            DB::table('flow_edges')->whereIn('flow_id', $flowIds)->delete();
            DB::table('flow_nodes')->whereIn('flow_id', $flowIds)->delete();
            DB::table('flows')->whereIn('id', $flowIds)->delete();
        }

        DB::table('courses')->where('tenant_id', $tenantId)->delete();
        DB::table('media_assets')->where('tenant_id', $tenantId)->delete();
        DB::table('whatsapp_instances')->where('tenant_id', $tenantId)->delete();
        DB::table('users')->where('tenant_id', $tenantId)->delete();
        DB::table('tenants')->where('id', $tenantId)->delete();
    }

    private function purgeOperationalData(?int $tenantId): void
    {
        $saleQuery = DB::table('sales');
        $leadQuery = DB::table('leads');
        $convQuery = DB::table('conversations');

        if ($tenantId !== null) {
            $saleQuery->where('tenant_id', $tenantId);
            $leadQuery->where('tenant_id', $tenantId);
            $convQuery->where('tenant_id', $tenantId);
        }

        $saleIds = (clone $saleQuery)->pluck('id');
        $convIds = (clone $convQuery)->pluck('id');

        if ($saleIds->isNotEmpty()) {
            $paymentIds = DB::table('payments')->whereIn('sale_id', $saleIds)->pluck('id');
            if ($paymentIds->isNotEmpty() && Schema::hasTable('deliveries')) {
                // deliveries suelen ir por sale_id
            }
            if (Schema::hasTable('deliveries')) {
                DB::table('deliveries')->whereIn('sale_id', $saleIds)->delete();
            }
            DB::table('payments')->whereIn('sale_id', $saleIds)->delete();
            DB::table('sales')->whereIn('id', $saleIds)->delete();
        }

        if ($convIds->isNotEmpty()) {
            DB::table('messages')->whereIn('conversation_id', $convIds)->delete();
            DB::table('conversations')->whereIn('id', $convIds)->delete();
        }

        (clone $leadQuery)->delete();
    }
}
