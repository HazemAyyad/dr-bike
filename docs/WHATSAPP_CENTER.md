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
WHATSAPP_WELCOME_ENABLED=true
WHATSAPP_WELCOME_COOLDOWN_HOURS=24
WHATSAPP_WELCOME_MESSAGE="أهلًا بك في د. بايك 👋\nتم استلام رسالتك وسيقوم أحد الموظفين بالرد عليك قريبًا."
WHATSAPP_WELCOME_MENU_ENABLED=true
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
- The Flutter conversation plays voice notes and videos inline, records and sends voice notes, and sends camera/gallery videos.
- Voice notes are recorded as MP4/AAC for WhatsApp compatibility and displayed with an inline waveform.
- Swipe or long-press a message to reply. Incoming and outgoing reply context is preserved using Meta message IDs.
- The conversation menu can share selected products. Synced products are sent as a native WhatsApp catalog list; unsynced products fall back to a formatted text list.
- While an admin composes text, Laravel sends Meta's typing indicator using the latest inbound message ID. This is best-effort and never blocks sending.
- A welcome reply is sent after the first inbound message and at most once per configured cooldown period. A cache lock prevents duplicate welcomes from simultaneous inbound messages.
- When enabled, the welcome is followed by a native WhatsApp interactive list for products, maintenance, inquiries, or contacting an employee.
- Outgoing bubbles identify the employee who replied; automatic messages are labeled `الرد التلقائي`.
- Employees can hide any message from their own view without deleting the stored message or affecting other employees.
- If Meta supplies a deleted/revoked inbound-message event with the original message ID, the stored copy remains visible with a customer-deleted warning. Cloud API does not provide an endpoint for deleting a sent message from the customer's phone.
- WhatsApp Cloud API does not expose the customer's live typing state to the webhook, so the admin app cannot reliably display that the customer is typing.
- The center displays a `wa.me` QR for `WHATSAPP_DISPLAY_PHONE_NUMBER` and provides an A4 PDF for printing, saving and sharing.

## Production notes

- Use a permanent system-user token, HTTPS, a queue worker, and restricted Meta permissions.
- Never commit `.env`, expose the access token to Flutter, or log credentials.
- Set the app to Live mode after Meta business verification and test-number validation.
- Monitor failed messages and webhook delivery in Meta Developer Console.
- Old chats from the WhatsApp Business handset app are not imported into Cloud API.
- Moving a real number to Cloud API normally means messages are handled by the API/system rather than the normal WhatsApp Business app. Confirm whether Meta coexistence is available and appropriate before migrating a live handset number.
- Webhook POST deliberately acknowledges unknown payloads with HTTP 200 so Meta does not retry unsupported event shapes.
