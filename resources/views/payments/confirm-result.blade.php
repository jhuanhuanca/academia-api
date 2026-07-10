<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Confirmación de pago — MarketLuna</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #fffbf4; color: #5c0500; margin: 0; padding: 2rem; }
        .card { max-width: 520px; margin: 0 auto; background: #fff; border: 1px solid #f0e2d8; border-radius: 16px; padding: 1.5rem; }
        h1 { font-size: 1.35rem; margin: 0 0 0.75rem; color: #860800; }
        p { line-height: 1.5; }
        .ok { color: #067a76; font-weight: 700; }
        .err { color: #860800; font-weight: 700; }
        a { color: #088cff; }
    </style>
</head>
<body>
    <div class="card">
        @if ($success)
            <h1 class="ok">Pago confirmado</h1>
            <p>{{ $message }}</p>
            <p>El cliente recibirá el acceso al curso por WhatsApp en unos segundos.</p>
        @else
            <h1 class="err">No se pudo confirmar</h1>
            <p>{{ $message }}</p>
            <p>Puedes confirmar desde el dashboard en <strong>Ventas</strong>.</p>
        @endif
        <p><a href="{{ config('app.frontend_url', config('app.url')) }}">Volver a MarketLuna</a></p>
    </div>
</body>
</html>
