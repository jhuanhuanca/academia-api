<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Aprobación de registro — {{ config('app.name') }}</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f4f7fb; color: #0a1f4d; margin: 0; padding: 2rem; }
        .card { max-width: 560px; margin: 0 auto; background: #fff; border: 1px solid #d9e3f2; border-radius: 16px; padding: 1.5rem; }
        h1 { font-size: 1.35rem; margin: 0 0 0.75rem; }
        p { line-height: 1.5; }
        .ok { color: #1f6b3a; font-weight: 700; }
        .err { color: #8a1c2e; font-weight: 700; }
        .meta { background: #f7f9fc; border-radius: 12px; padding: 0.9rem 1rem; margin: 1rem 0; font-size: 0.95rem; }
        a { color: #0a3494; }
    </style>
</head>
<body>
    <div class="card">
        @if ($success)
            <h1 class="ok">{{ $action === 'reject' ? 'Solicitud rechazada' : 'Solicitud aprobada' }}</h1>
        @else
            <h1 class="err">No se pudo procesar</h1>
        @endif

        <p>{{ $message }}</p>

        @if ($applicant)
            <div class="meta">
                <div><strong>Nombre:</strong> {{ $applicant->name }}</div>
                <div><strong>Email:</strong> {{ $applicant->email }}</div>
                <div><strong>Negocio:</strong> {{ $applicant->tenant?->name ?? '—' }}</div>
            </div>
        @endif

        <p><a href="{{ config('services.frontend_url', config('app.url')) }}">Ir al panel</a></p>
    </div>
</body>
</html>
