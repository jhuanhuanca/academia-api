<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\FlowNode;
use App\Models\KnowledgeItem;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappInstance;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::create([
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
        ]);

        User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Juan Huanca',
            'email' => 'huancajuan863@gmail.com',
            'password' => 'jhuanca1997@-luna',
            'role' => 'owner',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        WhatsappInstance::create([
            'tenant_id' => $tenant->id,
            'name' => 'Principal',
            'evolution_instance' => 'academia-ventas',
            'evolution_apikey' => null,
            'integration' => 'baileys',
            'status' => 'disconnected',
            'webhook_secret' => Str::random(48),
            'meta' => [],
        ]);

        $course = Course::create([
            'tenant_id' => $tenant->id,
            'uuid' => (string) Str::uuid(),
            'title' => 'Curso Demo de Ventas',
            'slug' => 'curso-demo-ventas',
            'description' => 'Curso de ejemplo para validar el flujo de venta automática.',
            'price' => 49.00,
            'currency' => 'USD',
            'delivery_type' => 'link',
            'delivery_payload' => [
                'url' => 'https://ejemplo.com/curso-demo',
                'instructions' => 'Usa este link para acceder al curso.',
            ],
            'is_active' => true,
            'sort_order' => 1,
        ]);

        KnowledgeItem::insert([
            [
                'tenant_id' => $tenant->id,
                'course_id' => $course->id,
                'title' => 'Precio del curso',
                'content' => 'El Curso Demo de Ventas cuesta 49 USD. Incluye acceso inmediato por link.',
                'tags' => json_encode(['precios', 'curso']),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tenant_id' => $tenant->id,
                'course_id' => $course->id,
                'title' => 'Contenido del curso',
                'content' => 'El curso incluye módulos de prospección, guion de venta y cierre por WhatsApp.',
                'tags' => json_encode(['contenido', 'curso']),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tenant_id' => $tenant->id,
                'course_id' => null,
                'title' => 'Soporte',
                'content' => 'Si necesitas ayuda humana, escribe o toca el botón Hablar con alguien.',
                'tags' => json_encode(['soporte']),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $flow = Flow::create([
            'tenant_id' => $tenant->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Venta demo',
            'description' => 'Flujo MVP: saludo → botones → QR → entrega',
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
            'position_x' => 80,
            'position_y' => 200,
        ]);

        $welcome = FlowNode::create([
            'flow_id' => $flow->id,
            'node_key' => 'welcome',
            'type' => 'message',
            'name' => 'Saludo',
            'config' => [
                'text' => '¡Hola! Soy el asistente de Academia Personal. Te puedo ayudar a conocer el curso y comprarlo.',
            ],
            'position_x' => 280,
            'position_y' => 200,
        ]);

        $menu = FlowNode::create([
            'flow_id' => $flow->id,
            'node_key' => 'menu',
            'type' => 'buttons',
            'name' => 'Menú principal',
            'config' => [
                'text' => '¿Qué te interesa?',
                'footer' => 'Academia Personal',
                'buttons' => [
                    ['id' => 'buy', 'label' => 'Quiero el curso'],
                    ['id' => 'price', 'label' => 'Ver precio'],
                    ['id' => 'human', 'label' => 'Hablar con alguien'],
                ],
            ],
            'position_x' => 520,
            'position_y' => 200,
        ]);

        $aiPrice = FlowNode::create([
            'flow_id' => $flow->id,
            'node_key' => 'ai_price',
            'type' => 'ai_reply',
            'name' => 'IA precio/FAQ',
            'config' => [
                'system_hint' => 'Sé breve y comercial. Solo usa knowledge permitido.',
                'knowledge_tags' => ['precios', 'contenido', 'soporte'],
                'allowed_transitions' => ['buy', 'human', 'default'],
                'fallback_transition' => 'human',
                'min_confidence' => 0.65,
            ],
            'position_x' => 760,
            'position_y' => 40,
        ]);

        $sendQr = FlowNode::create([
            'flow_id' => $flow->id,
            'node_key' => 'send_qr',
            'type' => 'send_payment_qr',
            'name' => 'Enviar QR',
            'config' => [
                'course_id' => $course->id,
                'provider' => 'manual_qr',
                'ttl_minutes' => 60,
                'caption' => 'Escanea el QR para pagar. Cuando pagues, avísame o espera la confirmación automática.',
            ],
            'position_x' => 760,
            'position_y' => 200,
        ]);

        $waitPay = FlowNode::create([
            'flow_id' => $flow->id,
            'node_key' => 'wait_payment',
            'type' => 'wait_payment',
            'name' => 'Esperar pago',
            'config' => [
                'timeout_minutes' => 60,
            ],
            'position_x' => 1000,
            'position_y' => 200,
        ]);

        $deliver = FlowNode::create([
            'flow_id' => $flow->id,
            'node_key' => 'deliver',
            'type' => 'deliver_course',
            'name' => 'Entregar curso',
            'config' => [
                'course_id' => $course->id,
                'success_text' => '¡Pago confirmado! Aquí tienes tu acceso al curso.',
            ],
            'position_x' => 1240,
            'position_y' => 200,
        ]);

        $handoff = FlowNode::create([
            'flow_id' => $flow->id,
            'node_key' => 'handoff',
            'type' => 'handoff',
            'name' => 'Agente humano',
            'config' => [
                'text' => 'Te derivo con una persona del equipo. En breve te responden.',
            ],
            'position_x' => 760,
            'position_y' => 380,
        ]);

        $edges = [
            [$start->id, $welcome->id, 'default', ''],
            [$welcome->id, $menu->id, 'default', ''],
            [$menu->id, $sendQr->id, 'button', 'buy'],
            [$menu->id, $aiPrice->id, 'button', 'price'],
            [$menu->id, $handoff->id, 'button', 'human'],
            [$aiPrice->id, $sendQr->id, 'ai_transition', 'buy'],
            [$aiPrice->id, $handoff->id, 'ai_transition', 'human'],
            [$aiPrice->id, $menu->id, 'ai_transition', 'default'],
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
    }
}
