<?php

/**
 * Prueba local del catálogo SaaS (sin WhatsApp real).
 * Uso (con MySQL XAMPP encendido):
 *   php scripts/test-catalog-flow.php
 */

use App\Models\Course;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\WhatsappInstance;
use App\Services\Tenancy\TenantCatalogBootstrapService;
use App\Services\WhatsApp\ConversationOrchestrator;
use App\Services\WhatsApp\EvolutionClient;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$app->bind(EvolutionClient::class, function () {
    return new class extends EvolutionClient
    {
        public function sendText(string $instance, string $number, string $text): array
        {
            echo "  [sendText] → {$number}: ".mb_substr(str_replace("\n", ' ', $text), 0, 80)."…\n";

            return ['key' => ['id' => 'sim_'.uniqid()]];
        }

        public function sendButtons(
            string $instance,
            string $number,
            string $title,
            string $description,
            array $buttons,
            ?string $footer = null
        ): array {
            return ['key' => ['id' => 'sim_btn_'.uniqid()]];
        }

        public function sendMedia(
            string $instance,
            string $number,
            string $mediaUrl,
            string $mediatype = 'image',
            ?string $caption = null,
            ?string $fileName = null,
            ?string $mimetype = null
        ): array {
            return ['key' => ['id' => 'sim_media_'.uniqid()]];
        }

        public function sendMediaFile(
            string $instance,
            string $number,
            string $binary,
            string $fileName,
            string $mimetype = 'image/jpeg',
            string $mediatype = 'image',
            ?string $caption = null
        ): array {
            return ['key' => ['id' => 'sim_file_'.uniqid()]];
        }
    };
});

echo "=== Catalog SaaS smoke test ===\n";

$tenant = Tenant::query()->firstOrCreate(
    ['slug' => 'saas-catalog-smoke'],
    [
        'uuid' => (string) Str::uuid(),
        'name' => 'SaaS Catalog Smoke',
        'status' => 'active',
        'plan' => 'personal',
        'timezone' => 'America/Lima',
        'settings' => ['brand' => 'SmokeShop', 'ai_name' => 'Luna'],
    ]
);

$wa = WhatsappInstance::query()->firstOrCreate(
    [
        'tenant_id' => $tenant->id,
        'evolution_instance' => 'smoke-catalog',
    ],
    [
        'name' => 'Principal',
        'integration' => 'baileys',
        'status' => 'open',
        'webhook_secret' => Str::random(32),
        'meta' => [],
    ]
);

$boot = app(TenantCatalogBootstrapService::class)->bootstrapIfEmpty($tenant);
echo 'Bootstrap: '.json_encode($boot)."\n";

$products = Course::query()->where('tenant_id', $tenant->id)->orderBy('sort_order')->get();
echo 'Productos: '.$products->pluck('title')->join(', ')."\n";

$orch = app(ConversationOrchestrator::class);
$phone = '+5199999'.random_int(1000, 9999);

echo "\n1) Cliente dice hola ({$phone})\n";
$orch->handleIncoming($wa, [
    'wa_message_id' => 'smoke_'.Str::uuid(),
    'phone_e164' => $phone,
    'wa_name' => 'Smoke',
    'from_me' => false,
    'type' => 'text',
    'body' => 'hola',
    'reply_to' => ltrim($phone, '+'),
    'raw' => [],
]);

echo "\n2) Cliente elige 2 (segundo producto)\n";
$orch->handleIncoming($wa, [
    'wa_message_id' => 'smoke_'.Str::uuid(),
    'phone_e164' => $phone,
    'wa_name' => 'Smoke',
    'from_me' => false,
    'type' => 'text',
    'body' => '2',
    'reply_to' => ltrim($phone, '+'),
    'raw' => [],
]);

$sale = Sale::query()->where('tenant_id', $tenant->id)->latest('id')->first();
if (! $sale) {
    fwrite(STDERR, "FAIL: no se creó Sale\n");
    exit(1);
}

$expected = $products[1]->id ?? null;
if ((int) $sale->course_id !== (int) $expected) {
    fwrite(STDERR, "FAIL: sale.course_id={$sale->course_id} esperado={$expected}\n");
    exit(1);
}

echo "\nOK: venta #{$sale->id} producto #{$sale->course_id} ({$sale->status})\n";
exit(0);
