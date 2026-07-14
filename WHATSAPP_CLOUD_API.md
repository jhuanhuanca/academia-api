# WhatsApp Cloud API (Meta) — canal principal de LunaMarket / ApaFlow

**Recomendado:** solo Cloud API. No despliegues Evolution/Baileys en el VPS nuevo.

## Qué ya está en el código

| Pieza | Ruta |
|-------|------|
| Cliente Graph | `app/Services/WhatsApp/MetaCloudClient.php` |
| Messenger | `MetaCloudMessenger` + `WhatsAppMessengerResolver` |
| Webhook | `GET/POST /api/webhooks/meta` |
| Normalizer | `MetaCloudIncomingNormalizer` |
| UI | Panel → WhatsApp (solo Cloud API) |

Las instancias nuevas nacen con `integration=meta_cloud`.

## Credenciales Meta

1. [Meta for Developers](https://developers.facebook.com/) → App → producto **WhatsApp**.
2. Anota: **Phone Number ID**, **WABA ID**, **Access Token**, **App Secret**.
3. Elige un **Verify Token** tuyo (string largo).

## Variables `.env` (API)

```env
META_WA_ACCESS_TOKEN=EAAB...
META_WA_PHONE_NUMBER_ID=1234567890
META_WA_WABA_ID=9876543210
META_WA_APP_SECRET=tu_app_secret
META_WA_VERIFY_TOKEN=cambia-este-verify-token-largo
META_WA_GRAPH_VERSION=v21.0
META_WA_ALLOW_UNSIGNED=false
FRONTEND_URL=https://apaflow.shop
```

## Webhook en Meta

```text
https://api.apaflow.shop/api/webhooks/meta
```

- Verify token = `META_WA_VERIFY_TOKEN`
- Suscribir **`messages`**

## Activar en el panel

1. Login → **WhatsApp**
2. Phone Number ID + token (o ya en `.env`)
3. **Activar Cloud API** → estado `open`
4. Escribe al número oficial desde otro celular

## Deploy droplet (sin Evolution)

```bash
cd /var/www/marketluna/academia-api
# .env con META_WA_* (sin Docker Evolution)
php artisan migrate --force
php artisan config:cache
sudo supervisorctl restart marketluna-worker:*
```

No hace falta `docker compose ... evolution` ni `main.apaflow.shop`.

## Local / QA

Usa **Simular inbound** en el panel mientras no tengas Meta.

## Notas

- Botones nativos Cloud API (máx. 3).
- En producción configura `META_WA_APP_SECRET`.
- Ventana 24 h de WhatsApp: fuera de ella necesitas plantillas HSM.
