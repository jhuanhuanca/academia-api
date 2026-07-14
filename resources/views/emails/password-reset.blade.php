<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <title>Recuperar contraseña</title>
</head>
<body style="margin:0;padding:24px;background:#e4e4e7;font-family:Georgia,serif;color:#003754;">
  <table width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:#fff;border-radius:16px;padding:28px;">
    <tr>
      <td>
        <h1 style="margin:0 0 12px;font-size:22px;">Hola {{ $userName }}</h1>
        <p style="margin:0 0 16px;line-height:1.5;color:#5a6a72;">
          Recibimos una solicitud para restablecer la contraseña de tu cuenta LunaMarket.
          Pulsa el botón (válido 60 minutos):
        </p>
        <p style="margin:24px 0;">
          <a href="{{ $resetUrl }}"
             style="display:inline-block;background:#003754;color:#fff;text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:700;">
            Crear nueva contraseña
          </a>
        </p>
        <p style="margin:0 0 8px;font-size:13px;color:#5a6a72;word-break:break-all;">
          Si el botón no funciona, copia este enlace:<br />
          <a href="{{ $resetUrl }}" style="color:#009093;">{{ $resetUrl }}</a>
        </p>
        <p style="margin:20px 0 0;font-size:12px;color:#9bb8c2;">
          Si no pediste este cambio, ignora este correo. Tu contraseña no se modifica.
        </p>
      </td>
    </tr>
  </table>
</body>
</html>
