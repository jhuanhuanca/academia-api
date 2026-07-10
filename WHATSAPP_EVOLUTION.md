# WhatsApp + Evolution — MarketLuna

## Arquitectura

```
WhatsApp  ↔  Evolution API (:8080)
                │ webhook POST
                ▼
         academia-api /api/webhooks/evolution
                │ Job ProcessEvolutionWebhook
                ▼
         ConversationOrchestrator
           ├─ lead + conversation + messages
           ├─ FlowRunner (botones / edges)
           ├─ LunaClient (nodos ai_reply)
           └─ EvolutionClient.sendText / sendButtons
```

## Variables (academia-api/.env)

```
EVOLUTION_BASE_URL=http://127.0.0.1:8080
EVOLUTION_API_KEY=marketluna-evolution-key-change-me
EVOLUTION_INSTANCE=academia-ventas
EVOLUTION_WEBHOOK_URL=http://host.docker.internal:8000/api/webhooks/evolution
```

> En local, `host.docker.internal` permite que el contenedor Evolution llegue a Laravel en Windows/Mac.
> Si usas Linux, cambia por la IP del host o un túnel (ngrok).

## Levantar Evolution (local)

```bash
cd evolution-api-main
docker compose -f docker-compose.local.yaml up -d
```

API: http://127.0.0.1:8080  
La API key del compose coincide con `EVOLUTION_API_KEY`.

## Endpoints MarketLuna

| Método | Ruta | Uso |
|--------|------|-----|
| POST | `/api/webhooks/evolution` | Webhook público |
| GET | `/api/whatsapp/health` | ¿Evolution responde? |
| POST | `/api/whatsapp-instances/{id}/connect` | Crear/conectar + QR |
| GET | `/api/whatsapp-instances/{id}/qr` | Último QR guardado |
| GET | `/api/whatsapp-instances/{id}/refresh` | Estado de sesión |
| POST | `/api/whatsapp-instances/{id}/simulate-inbound` | Probar flujo sin WA |
| POST | `/api/whatsapp-instances/{id}/test-send` | Enviar texto real |

## Flujo de prueba sin celular

1. API + Luna arriba (`:8000`, `:8001`)
2. Login dashboard
3. WhatsApp → **Simular inbound** (`hola`) → debe crear conversación y llegar a menú
4. Simular `button_id=buy` → crea sale `pending_payment`

## Flujo real

1. Evolution Docker up
2. En UI: **Conectar Evolution (QR)** y escanear
3. Escribe al número conectado
4. Webhook → orquestador → respuestas por Evolution

## Confirmación de pagos (comprobante manual)

1. Cliente en nodo `wait_payment` envía **foto del comprobante** por WhatsApp.
2. API guarda imagen, marca venta `awaiting_confirmation` y envía **email** al usuario del tenant.
3. Email incluye comprobante adjunto + botón **Confirmar pago y enviar curso**.
4. Al confirmar (email o dashboard **Ventas**), el sistema avanza `payment_paid` y entrega el curso por WhatsApp.

### Variables email (Gmail ejemplo)

```
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=tu@gmail.com
MAIL_PASSWORD=contraseña-de-aplicacion
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=tu@gmail.com
MAIL_FROM_NAME=MarketLuna
```

En local puedes usar `MAIL_MAILER=log` y revisar `storage/logs/laravel.log`.

### Probar sin celular

1. WhatsApp → **Simular compra + comprobante**
2. Ventas → ver comprobante → **Confirmar pago y entregar**
3. O abrir enlace del email: `/confirmar-pago/{id}?token=...`

## Notas

- Baileys (QR) = MVP. Cloud API oficial = producción.
- `sendButtons` hace fallback a texto si Evolution/Baileys no soporta el payload.
- El cobro QR actual es **manual_qr** (texto con referencia). Imagen EMV/pasarela = siguiente iteración.
