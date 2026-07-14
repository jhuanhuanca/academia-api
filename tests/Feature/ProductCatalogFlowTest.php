<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lead;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\WhatsappInstance;
use App\Services\Tenancy\TenantCatalogBootstrapService;
use App\Services\WhatsApp\ConversationOrchestrator;
use App\Services\WhatsApp\EvolutionClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class ProductCatalogFlowTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private WhatsappInstance $instance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(EvolutionClient::class, function ($mock) {
            $mock->shouldReceive('sendText')->andReturn(['key' => ['id' => 'txt_'.Str::random(6)]]);
            $mock->shouldReceive('sendButtons')->andReturn(['key' => ['id' => 'btn_'.Str::random(6)]]);
            $mock->shouldReceive('sendMedia')->andReturn(['key' => ['id' => 'media_'.Str::random(6)]]);
            $mock->shouldReceive('sendMediaFile')->andReturn(['key' => ['id' => 'file_'.Str::random(6)]]);
        });

        $this->tenant = Tenant::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Negocio Demo',
            'slug' => 'negocio-demo-'.Str::lower(Str::random(4)),
            'status' => 'active',
            'plan' => 'personal',
            'timezone' => 'America/Lima',
            'settings' => [
                'brand' => 'Negocio Demo',
                'ai_name' => 'Luna',
                'default_payment_provider' => 'manual_qr',
            ],
        ]);

        $this->instance = WhatsappInstance::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Principal',
            'evolution_instance' => 'test-'.Str::lower(Str::random(6)),
            'integration' => 'baileys',
            'status' => 'open',
            'webhook_secret' => Str::random(32),
            'meta' => [],
        ]);

        app(TenantCatalogBootstrapService::class)->bootstrapIfEmpty($this->tenant);
    }

    public function test_bootstrap_creates_three_products_and_catalog_flow(): void
    {
        $this->assertSame(3, Course::query()->where('tenant_id', $this->tenant->id)->count());
        $this->assertDatabaseHas('flows', [
            'tenant_id' => $this->tenant->id,
            'name' => 'Venta por catálogo',
            'status' => 'published',
            'is_default' => 1,
        ]);
    }

    public function test_choosing_option_1_creates_sale_for_first_product(): void
    {
        $products = Course::query()
            ->where('tenant_id', $this->tenant->id)
            ->orderBy('sort_order')
            ->get();

        $this->assertCount(3, $products);

        $orchestrator = app(ConversationOrchestrator::class);
        $phone = '+51911110001';

        $orchestrator->handleIncoming($this->instance, [
            'wa_message_id' => 'm1_'.Str::uuid(),
            'phone_e164' => $phone,
            'wa_name' => 'Cliente A',
            'from_me' => false,
            'type' => 'text',
            'body' => 'hola',
            'reply_to' => '51911110001',
            'raw' => [],
        ]);

        $result = $orchestrator->handleIncoming($this->instance, [
            'wa_message_id' => 'm2_'.Str::uuid(),
            'phone_e164' => $phone,
            'wa_name' => 'Cliente A',
            'from_me' => false,
            'type' => 'text',
            'body' => '1',
            'reply_to' => '51911110001',
            'raw' => [],
        ]);

        $this->assertTrue(($result['ok'] ?? false) || isset($result['path']) || isset($result['executed']));

        $sale = Sale::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('course_id', $products[0]->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($sale, 'Debe crearse venta del producto #1');
        $this->assertSame('pending_payment', $sale->status);
    }

    public function test_choosing_option_2_creates_sale_for_second_product(): void
    {
        $products = Course::query()
            ->where('tenant_id', $this->tenant->id)
            ->orderBy('sort_order')
            ->get();

        $orchestrator = app(ConversationOrchestrator::class);
        $phone = '+51911110002';

        $orchestrator->handleIncoming($this->instance, [
            'wa_message_id' => 'm3_'.Str::uuid(),
            'phone_e164' => $phone,
            'wa_name' => 'Cliente B',
            'from_me' => false,
            'type' => 'text',
            'body' => 'hola',
            'reply_to' => '51911110002',
            'raw' => [],
        ]);

        $orchestrator->handleIncoming($this->instance, [
            'wa_message_id' => 'm4_'.Str::uuid(),
            'phone_e164' => $phone,
            'wa_name' => 'Cliente B',
            'from_me' => false,
            'type' => 'text',
            'body' => '2',
            'reply_to' => '51911110002',
            'raw' => [],
        ]);

        $sale = Sale::query()
            ->where('tenant_id', $this->tenant->id)
            ->whereHas('lead', fn ($q) => $q->where('phone_e164', $phone))
            ->latest('id')
            ->first();

        $this->assertNotNull($sale);
        $this->assertSame($products[1]->id, $sale->course_id);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
