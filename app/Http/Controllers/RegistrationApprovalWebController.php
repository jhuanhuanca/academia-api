<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Tenancy\TenantRegistrationService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RegistrationApprovalWebController extends Controller
{
    public function __invoke(Request $request, User $user, TenantRegistrationService $service): View
    {
        $token = (string) $request->query('token', '');
        $action = strtolower((string) $request->query('action', 'approve'));

        $result = $action === 'reject'
            ? $service->reject($user, $token)
            : $service->approve($user, $token);

        if ($result['ok'] ?? false) {
            $already = in_array($result['reason'] ?? '', ['already_approved', 'already_rejected'], true);
            $message = match (true) {
                $action === 'reject' && $already => 'Esta solicitud ya estaba rechazada.',
                $action === 'reject' => 'Usuario rechazado. No podrá iniciar sesión.',
                $already => 'Esta solicitud ya estaba aprobada.',
                default => 'Usuario aprobado. Ya puede iniciar sesión en el panel.',
            };

            return view('registration.approval-result', [
                'success' => true,
                'message' => $message,
                'applicant' => $user->fresh(['tenant']),
                'action' => $action,
            ]);
        }

        $message = match ($result['reason'] ?? 'unknown') {
            'invalid_token' => 'El enlace no es válido o ya fue usado.',
            'token_expired' => 'El enlace expiró (7 días). Pide al usuario que se registre de nuevo o aprueba desde el servidor.',
            'already_approved' => 'Esta solicitud ya fue aprobada.',
            'already_rejected' => 'Esta solicitud ya fue rechazada.',
            default => 'No se pudo procesar la solicitud.',
        };

        return view('registration.approval-result', [
            'success' => false,
            'message' => $message,
            'applicant' => $user->load('tenant'),
            'action' => $action,
        ]);
    }
}
