<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$instance = App\Models\WhatsappInstance::first();
if (! $instance) {
    echo "No whatsapp instance\n";
    exit(1);
}

$orchestrator = app(App\Services\WhatsApp\ConversationOrchestrator::class);
$phone = '+584129999002';
$receipt = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

$orchestrator->handleIncoming($instance, [
    'wa_message_id' => 't1',
    'phone_e164' => $phone,
    'wa_name' => 'Test',
    'from_me' => false,
    'type' => 'text',
    'body' => 'hola',
    'raw' => ['simulated' => true],
]);

$orchestrator->handleIncoming($instance, [
    'wa_message_id' => 't2',
    'phone_e164' => $phone,
    'wa_name' => 'Test',
    'from_me' => false,
    'type' => 'button_reply',
    'body' => 'buy',
    'button_id' => 'buy',
    'raw' => ['simulated' => true],
]);

$r3 = $orchestrator->handleIncoming($instance, [
    'wa_message_id' => 't3',
    'phone_e164' => $phone,
    'wa_name' => 'Test',
    'from_me' => false,
    'type' => 'image',
    'body' => '[imagen]',
    'raw' => ['simulated' => true, 'receipt_base64' => $receipt],
]);

$sale = App\Models\Sale::latest()->first();
$payment = App\Models\Payment::latest()->first();

echo json_encode([
    'r3' => $r3,
    'sale_status' => $sale?->status,
    'payment_status' => $payment?->status,
    'has_receipt' => (bool) $payment?->receipt_media_asset_id,
    'token' => $payment?->confirmation_token ? 'yes' : 'no',
], JSON_PRETTY_PRINT)."\n";

$service = app(App\Services\Payments\PaymentConfirmationService::class);
$confirm = $service->confirmPayment($payment);
echo json_encode(['confirm' => $confirm], JSON_PRETTY_PRINT)."\n";

echo 'sale_after='.$sale?->fresh()?->status."\n";
