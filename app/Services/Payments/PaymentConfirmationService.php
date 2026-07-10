<?php

namespace App\Services\Payments;

use App\Mail\PaymentReceiptReviewMail;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowNode;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\User;
use App\Models\WhatsappInstance;
use App\Services\WhatsApp\ConversationOrchestrator;
use App\Services\WhatsApp\WhatsAppMediaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class PaymentConfirmationService
{
    public function __construct(
        private readonly WhatsAppMediaService $mediaService,
        private readonly ConversationOrchestrator $orchestrator,
    ) {
    }

    /**
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    public function handleReceiptSubmission(
        WhatsappInstance $instance,
        Conversation $conversation,
        FlowNode $currentNode,
        string $phone,
        array $incoming
    ): array {
        $payment = $this->resolvePendingPayment($conversation);
        if (! $payment) {
            $this->orchestrator->sendQuickReply(
                $instance,
                $conversation,
                $phone,
                'No encontré un pago pendiente. Si necesitas ayuda, escribe "humano".',
                $currentNode->id
            );

            return ['ok' => false, 'reason' => 'no_pending_payment'];
        }

        if ($payment->receipt_submitted_at && $payment->status === 'awaiting_review') {
            $this->orchestrator->sendQuickReply(
                $instance,
                $conversation,
                $phone,
                'Ya recibimos tu comprobante. Te confirmamos el acceso muy pronto.',
                $currentNode->id
            );

            return ['ok' => true, 'action' => 'receipt_already_submitted'];
        }

        $type = (string) ($incoming['type'] ?? 'text');
        $isMedia = in_array($type, ['image', 'document'], true);
        $hasSimulatedReceipt = ! empty(data_get($incoming, 'raw.receipt_base64'))
            || ! empty(data_get($incoming, 'receipt_base64'));

        if (! $isMedia && ! $hasSimulatedReceipt) {
            $this->orchestrator->sendQuickReply(
                $instance,
                $conversation,
                $phone,
                'Para validar tu pago, envíame una *foto o captura del comprobante* por aquí.',
                $currentNode->id
            );

            return ['ok' => true, 'action' => 'receipt_image_required'];
        }

        $media = $this->mediaService->storeReceiptFromIncoming($instance, $incoming);
        if (! $media) {
            $this->orchestrator->sendQuickReply(
                $instance,
                $conversation,
                $phone,
                'Recibí tu mensaje pero no pude leer la imagen. ¿Puedes reenviar el comprobante?',
                $currentNode->id
            );

            return ['ok' => false, 'reason' => 'media_download_failed'];
        }

        $token = Str::random(64);
        $payment->update([
            'receipt_media_asset_id' => $media->id,
            'receipt_submitted_at' => now(),
            'status' => 'awaiting_review',
            'confirmation_token' => $token,
            'confirmation_expires_at' => now()->addHours(48),
        ]);

        $sale = $payment->sale()->with(['lead', 'course'])->first();
        if ($sale) {
            $sale->update(['status' => 'awaiting_confirmation']);
        }

        $this->notifyOwnersByEmail($instance->tenant_id, $payment->fresh(['sale.lead', 'sale.course', 'receiptMedia']));
        $this->orchestrator->sendQuickReply(
            $instance,
            $conversation,
            $phone,
            '✅ Recibimos tu comprobante. En cuanto lo validemos te enviamos el acceso al curso por aquí.',
            $currentNode->id
        );

        return [
            'ok' => true,
            'action' => 'receipt_submitted',
            'payment_id' => $payment->id,
            'media_asset_id' => $media->id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function confirmPayment(Payment $payment, ?string $token = null): array
    {
        if ($payment->status === 'paid') {
            return ['ok' => true, 'action' => 'already_paid'];
        }

        if ($token !== null) {
            if (! $payment->confirmation_token || ! hash_equals($payment->confirmation_token, $token)) {
                return ['ok' => false, 'reason' => 'invalid_token'];
            }
            if ($payment->confirmation_expires_at && $payment->confirmation_expires_at->isPast()) {
                return ['ok' => false, 'reason' => 'token_expired'];
            }
        }

        if (! in_array($payment->status, ['awaiting_review', 'pending'], true)) {
            return ['ok' => false, 'reason' => 'invalid_status'];
        }

        return DB::transaction(function () use ($payment) {
            $payment->update([
                'status' => 'paid',
                'paid_at' => now(),
                'confirmation_token' => null,
            ]);

            $sale = Sale::query()
                ->with(['conversation.whatsappInstance', 'lead', 'course'])
                ->findOrFail($payment->sale_id);

            $sale->update([
                'status' => 'paid',
                'paid_at' => now(),
            ]);

            $result = $this->orchestrator->deliverAfterPaymentConfirmation($sale);

            return array_merge(['ok' => true, 'action' => 'confirmed_and_delivered'], $result);
        });
    }

    private function resolvePendingPayment(Conversation $conversation): ?Payment
    {
        $paymentId = data_get($conversation->context, 'payment_id');
        if ($paymentId) {
            $payment = Payment::query()->find($paymentId);
            if ($payment && in_array($payment->status, ['pending', 'awaiting_review'], true)) {
                return $payment;
            }
        }

        $saleId = data_get($conversation->context, 'sale_id');
        if ($saleId) {
            return Payment::query()
                ->where('sale_id', $saleId)
                ->whereIn('status', ['pending', 'awaiting_review'])
                ->latest('id')
                ->first();
        }

        return null;
    }

    private function notifyOwnersByEmail(int $tenantId, Payment $payment): void
    {
        $users = User::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->get();

        if ($users->isEmpty()) {
            Log::warning('No hay usuarios activos para notificar comprobante', [
                'tenant_id' => $tenantId,
                'payment_id' => $payment->id,
            ]);

            return;
        }

        $confirmUrl = url('/confirmar-pago/'.$payment->id.'?token='.$payment->confirmation_token);

        foreach ($users as $user) {
            try {
                Mail::to($user->email)->send(new PaymentReceiptReviewMail($payment, $user, $confirmUrl));
            } catch (Throwable $e) {
                Log::error('Error enviando email de comprobante', [
                    'payment_id' => $payment->id,
                    'email' => $user->email,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
