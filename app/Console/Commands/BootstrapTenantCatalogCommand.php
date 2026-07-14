<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Tenancy\TenantCatalogBootstrapService;
use Illuminate\Console\Command;

class BootstrapTenantCatalogCommand extends Command
{
    protected $signature = 'tenant:bootstrap-catalog {tenant? : ID o slug del tenant} {--all : Todos los tenants activos}';

    protected $description = 'Crea productos demo + flujo catálogo si el tenant está vacío (SaaS local)';

    public function handle(TenantCatalogBootstrapService $bootstrap): int
    {
        $query = Tenant::query();

        if ($this->option('all')) {
            $query->where('status', 'active');
        } elseif ($arg = $this->argument('tenant')) {
            $query->where(function ($q) use ($arg) {
                $q->where('id', $arg)->orWhere('slug', $arg);
            });
        } else {
            $this->error('Indica {tenant} o usa --all');

            return self::FAILURE;
        }

        $tenants = $query->get();
        if ($tenants->isEmpty()) {
            $this->warn('No se encontró ningún tenant.');

            return self::FAILURE;
        }

        foreach ($tenants as $tenant) {
            $result = $bootstrap->bootstrapIfEmpty($tenant);
            $this->line(sprintf(
                'Tenant #%d %s → %s (products=%s flow=%s)',
                $tenant->id,
                $tenant->slug,
                ($result['skipped'] ?? false) ? 'omitido' : 'ok',
                $result['products'] ?? '-',
                $result['flow_id'] ?? '-'
            ));
        }

        return self::SUCCESS;
    }
}
