# Dr Bike WhatsApp Center

The module uses Meta WhatsApp Cloud API. All Meta credentials and API calls stay in Laravel; the Flutter app only calls authenticated Dr Bike endpoints.

## Meta setup

1. Create or select a Meta Business Portfolio and a Meta Developer app.
2. Add the WhatsApp product and connect a WhatsApp Business Account.
3. Copy the phone number ID, business account ID, and a production system-user access token.
4. Configure the webhook callback as `https://YOUR-DOMAIN/api/whatsapp/webhook`.
5. Use the same private verify token in Meta and `WHATSAPP_VERIFY_TOKEN`.
6. Subscribe the webhook to the `messages` field.

## Environment

```dotenv
WHATSAPP_API_VERSION=v23.0
WHATSAPP_ACCESS_TOKEN=your_server_side_access_token
WHATSAPP_PHONE_NUMBER_ID=your_phone_number_id
WHATSAPP_BUSINESS_ACCOUNT_ID=your_business_account_id
WHATSAPP_DISPLAY_PHONE_NUMBER=970594672857
WHATSAPP_VERIFY_TOKEN=a_long_random_private_verify_token
WHATSAPP_HTTP_TIMEOUT=20
```

Run:

```bash
php artisan migrate
php artisan db:seed --class=WhatsAppTemplateSeeder
php artisan optimize:clear
```

The default Arabic records are local Dr Bike drafting templates. They are not approved Meta templates. Any template sent outside the customer-service window must be separately created and approved in WhatsApp Manager with the same name, language and components.

## Test message

Authenticate as an admin and call:

```http
POST /api/whatsapp/test-message
Authorization: Bearer SANCTUM_TOKEN
Content-Type: application/json

{"phone":"9705XXXXXXXX","message":"رسالة تجربة من دكتور بايك"}
```

Phone numbers must use international format without spaces. The sending endpoints are rate limited.

## Media and QR

- Laravel uploads outgoing images and attachments to Meta; Flutter never receives the Meta access token.
- Incoming image, document, audio and video payloads are saved by the webhook and served through authenticated Laravel endpoints.
- Supported outgoing files include images, PDF, Office documents, audio and MP4 up to 16 MB.
- The center displays a `wa.me` QR for `WHATSAPP_DISPLAY_PHONE_NUMBER` and provides an A4 PDF for printing, saving and sharing.

## Production notes

- Use a permanent system-user token, HTTPS, a queue worker, and restricted Meta permissions.
- Never commit `.env`, expose the access token to Flutter, or log credentials.
- Set the app to Live mode after Meta business verification and test-number validation.
- Monitor failed messages and webhook delivery in Meta Developer Console.
- Old chats from the WhatsApp Business handset app are not imported into Cloud API.
- Moving a real number to Cloud API normally means messages are handled by the API/system rather than the normal WhatsApp Business app. Confirm whether Meta coexistence is available and appropriate before migrating a live handset number.
- Webhook POST deliberately acknowledges unknown payloads with HTTP 200 so Meta does not retry unsupported event shapes.
