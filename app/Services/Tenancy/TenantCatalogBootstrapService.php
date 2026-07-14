<?php

namespace App\Services\Tenancy;

use App\Models\Course;
use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\FlowNode;
use App\Models\KnowledgeItem;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Prepara un tenant nuevo para vender: productos demo + flujo catálogo publicado.
 * Pensado para SaaS de negocio-a-negocio (sin Meta Cloud aún).
 */
class TenantCatalogBootstrapService
{
    /**
     * @return array{ok:bool,skipped?:bool,reason?:string,products?:int,flow_id?:int}
     */
    public function bootstrapIfEmpty(Tenant $tenant): array
    {
        $hasProducts = Course::query()->where('tenant_id', $tenant->id)->exists();
        $hasFlow = Flow::query()->where('tenant_id', $tenant->id)->exists();

        if ($hasProducts && $hasFlow) {
            return ['ok' => true, 'skipped' => true, 'reason' => 'already_bootstrapped'];
        }

        return DB::transaction(function () use ($tenant, $hasProducts, $hasFlow) {
            $products = $hasProducts
                ? Course::query()->where('tenant_id', $tenant->id)->where('is_active', true)->orderBy('sort_order')->get()
                : collect($this->createDemoProducts($tenant));

            $flowId = null;
            if (! $hasFlow) {
                $flowId = $this->createCatalogFlow($tenant, $products)->id;
            }

            return [
                'ok' => true,
                'skipped' => false,
                'products' => $products->count(),
                'flow_id' => $flowId,
            ];
        });
    }

    /**
     * @return list<Course>
     */
    public function createDemoProducts(Tenant $tenant): array
    {
        $brand = (string) data_get($tenant->settings, 'brand', $tenant->name);

        $defs = [
            [
                'title' => 'Producto Starter',
                'slug' => 'producto-starter',
                'description' => 'Producto de entrada para probar el catálogo del bot.',
                'price' => 19.00,
                'sort_order' => 1,
                'url' => 'https://ejemplo.com/producto-starter',
            ],
            [
                'title' => 'Producto Pro',
                'slug' => 'producto-pro',
                'description' => 'Oferta principal con más valor percibido.',
                'price' => 49.00,
                'sort_order' => 2,
                'url' => 'https://ejemplo.com/producto-pro',
            ],
            [
                'title' => 'Producto Premium',
                'slug' => 'producto-premium',
                'description' => 'Paquete premium / ticket alto.',
                'price' => 99.00,
                'sort_order' => 3,
                'url' => 'https://ejemplo.com/producto-premium',
            ],
        ];

        $created = [];
        foreach ($defs as $def) {
            $course = Course::create([
                'tenant_id' => $tenant->id,
                'uuid' => (string) Str::uuid(),
                'title' => $def['title'],
                'slug' => $def['slug'].'-'.$tenant->id,
                'description' => $def['description'],
                'price' => $def['price'],
                'currency' => 'USD',
                'delivery_type' => 'link',
                'delivery_payload' => [
                    'url' => $def['url'],
                    'instructions' => 'Usa este link para acceder a tu compra.',
                ],
                'is_active' => true,
                'sort_order' => $def['sort_order'],
            ]);
            $created[] = $course;

            KnowledgeItem::create([
                'tenant_id' => $tenant->id,
                'course_id' => $course->id,
                'title' => 'Info: '.$course->title,
                'content' => "{$course->title} cuesta {$course->price} {$course->currency}. {$course->description} Marca: {$brand}.",
                'tags' => ['precios', 'productos', 'catalogo'],
                'is_active' => true,
            ]);
        }

        KnowledgeItem::create([
            'tenant_id' => $tenant->id,
            'course_id' => null,
            'title' => 'Soporte',
            'content' => 'Si necesitas ayuda humana, escribe humano o elige Hablar con alguien.',
            'tags' => ['soporte'],
            'is_active' => true,
        ]);

        return $created;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Course>|list<Course>  $products
     */
    public function createCatalogFlow(Tenant $tenant, $products): Flow
    {
        $brand = (string) data_get($tenant->settings, 'brand', $tenant->name);
        $products = collect($products)->take(6)->values();

        $flow = Flow::create([
            'tenant_id' => $tenant->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Venta por catálogo',
            'description' => 'Saludo → catálogo de productos → cobro → entrega',
            'status' => 'published',
            'version' => 1,
            'is_default' => true,
            'published_at' => now(),
        ]);

        $start = FlowNode::create([
            'flow_id' => $flow->id,
            'node_key' => 'start',
            'type' => 'start',
            'name' => 'Inicio',
            'config' => [],
            'position_x' => 60,
            'position_y' => 200,
        ]);

        $welcome = FlowNode::create([
            'flow_id' => $flow->id,
            'node_key' => 'welcome',
            'type' => 'message',
            'name' => 'Saludo',
            'config' => [
                'text' => "¡Hola! Soy el asistente de {$brand}. Te muestro el catálogo para que elijas qué quieres comprar.",
            ],
            'position_x' => 260,
            'position_y' => 200,
        ]);

        $menu = FlowNode::create([
            'flow_id' => $flow->id,
            'node_key' => 'catalog',
            'type' => 'buttons',
            'name' => 'Catálogo',
            'config' => [
                'text' => "📦 *Catálogo {$brand}*\nElige el número del producto:",
                'footer' => $brand,
                'source' => 'courses',
                'buttons' => [],
            ],
            'position_x' => 500,
            'position_y' => 200,
        ]);

        $sendQr = FlowNode::create([
            'flow_id' => $flow->id,
            'node_key' => 'send_qr',
            'type' => 'send_payment_qr',
            'name' => 'Cobro',
            'config' => [
                // null = usa el producto elegido en el catálogo (context)
                'course_id' => null,
                'provider' => 'manual_qr',
                'ttl_minutes' => 60,
                'caption' => '¡Excelente elección! Aquí tienes los datos de pago.',
                'send_qr_image' => true,
            ],
            'position_x' => 760,
            'position_y' => 200,
        ]);

        $waitPay = FlowNode::create([
            'flow_id' => $flow->id,
            'node_key' => 'wait_payment',
            'type' => 'wait_payment',
            'name' => 'Esperar pago',
            'config' => ['timeout_minutes' => 60],
            'position_x' => 1000,
            'position_y' => 200,
        ]);

        $deliver = FlowNode::create([
            'flow_id' => $flow->id,
            'node_key' => 'deliver',
            'type' => 'deliver_course',
            'name' => 'Entregar',
            'config' => [
                'course_id' => null,
                'success_text' => '¡Pago confirmado! Aquí tienes tu acceso:',
            ],
            'position_x' => 1240,
            'position_y' => 200,
        ]);

        $handoff = FlowNode::create([
            'flow_id' => $flow->id,
            'node_key' => 'handoff',
            'type' => 'handoff',
            'name' => 'Humano',
            'config' => [
                'text' => 'Te derivo con una persona del equipo. En breve te responden.',
            ],
            'position_x' => 760,
            'position_y' => 380,
        ]);

        $edges = [
            [$start->id, $welcome->id, 'default', ''],
            [$welcome->id, $menu->id, 'default', ''],
            // Catálogo dinámico (course_123) → mismo cobro
            [$menu->id, $sendQr->id, 'button', 'catalog'],
            [$menu->id, $handoff->id, 'button', 'human'],
            [$sendQr->id, $waitPay->id, 'default', ''],
            [$waitPay->id, $deliver->id, 'payment_paid', ''],
            [$waitPay->id, $sendQr->id, 'payment_expired', ''],
            [$waitPay->id, $handoff->id, 'payment_failed', ''],
        ];

        foreach ($edges as [$from, $to, $type, $key]) {
            FlowEdge::create([
                'flow_id' => $flow->id,
                'from_node_id' => $from,
                'to_node_id' => $to,
                'trigger_type' => $type,
                'trigger_key' => $key,
                'priority' => 0,
            ]);
        }

        $flow->update(['start_node_id' => $start->id]);

        return $flow->fresh();
    }
}
