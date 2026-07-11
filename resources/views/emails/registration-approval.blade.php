<x-mail::message>
# Nueva solicitud de registro

Alguien quiere crear una cuenta en **{{ config('app.name') }}**. Debes **aprobar** o **rechazar** antes de que pueda entrar.

**Nombre:** {{ $applicant->name }}  
**Email:** {{ $applicant->email }}  
**Negocio:** {{ $tenant?->name ?? '—' }}  
**Slug:** {{ $tenant?->slug ?? '—' }}  
**Fecha:** {{ $applicant->created_at?->timezone(config('app.timezone'))->format('d/m/Y H:i') }}

<x-mail::button :url="$approveUrl" color="success">
Aprobar usuario
</x-mail::button>

<x-mail::button :url="$rejectUrl" color="error">
Rechazar usuario
</x-mail::button>

Si no reconoces esta solicitud, recházala.

Saludos,<br>
{{ config('app.name') }}
</x-mail::message>
