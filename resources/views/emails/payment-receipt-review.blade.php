<x-mail::message>
# Nuevo comprobante de pago

Hola {{ $user->name }},

Un cliente envió un comprobante para validar en **MarketLuna**.

**Curso:** {{ $course?->title ?? '—' }}  
**Monto:** {{ $payment->amount }} {{ $payment->currency }}  
**Cliente:** {{ $lead?->name ?: ($lead?->phone_e164 ?? '—') }}  
**Teléfono:** {{ $lead?->phone_e164 ?? '—' }}  
**Referencia pago:** {{ $payment->idempotency_key }}

La imagen del comprobante va adjunta a este correo.

<x-mail::button :url="$confirmUrl">
Confirmar pago y enviar curso
</x-mail::button>

Al confirmar, el sistema enviará automáticamente el acceso al curso por WhatsApp.

Si no reconoces este pago, ignora este correo.

Saludos,<br>
{{ config('app.name') }}
</x-mail::message>
