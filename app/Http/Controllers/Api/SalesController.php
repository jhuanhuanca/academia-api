<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\Payment;
use App\Services\Payments\PaymentConfirmationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $sales = Sale::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with([
                'lead:id,name,phone_e164',
                'course:id,title',
                'payments' => fn ($q) => $q->latest()->with('receiptMedia:id,path,disk,mime'),
            ])
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return response()->json(['data' => $sales]);
    }

    public function confirmPayment(
        Request $request,
        Sale $sale,
        PaymentConfirmationService $service
    ): JsonResponse {
        abort_if($request->user()->tenant_id !== $sale->tenant_id, 404);

        $payment = Payment::query()
            ->where('sale_id', $sale->id)
            ->whereIn('status', ['awaiting_review', 'pending'])
            ->latest('id')
            ->first();

        if (! $payment) {
            return response()->json(['message' => 'No hay pago pendiente de confirmación'], 422);
        }

        $result = $service->confirmPayment($payment);

        if (! ($result['ok'] ?? false)) {
            return response()->json([
                'message' => 'No se pudo confirmar el pago',
                'reason' => $result['reason'] ?? 'unknown',
            ], 422);
        }

        return response()->json([
            'data' => $sale->fresh(['lead', 'course', 'payments.receiptMedia']),
            'result' => $result,
        ]);
    }
}
