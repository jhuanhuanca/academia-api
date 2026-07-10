<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\Payments\PaymentConfirmationService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PaymentConfirmationWebController extends Controller
{
    public function __invoke(Request $request, Payment $payment, PaymentConfirmationService $service): View
    {
        $token = (string) $request->query('token', '');
        $result = $service->confirmPayment($payment, $token !== '' ? $token : null);

        if ($result['ok'] ?? false) {
            return view('payments.confirm-result', [
                'success' => true,
                'message' => 'El pago fue confirmado correctamente.',
            ]);
        }

        $message = match ($result['reason'] ?? 'unknown') {
            'invalid_token' => 'El enlace de confirmación no es válido.',
            'token_expired' => 'El enlace de confirmación expiró. Confirma desde el dashboard.',
            'invalid_status' => 'Este pago ya no puede confirmarse desde este enlace.',
            default => 'No se pudo confirmar el pago.',
        };

        return view('payments.confirm-result', [
            'success' => false,
            'message' => $message,
        ]);
    }
}
