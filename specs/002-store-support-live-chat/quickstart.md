# Quickstart Validation: Online Store Live Support Chat

## Server prerequisites

1. Deploy the Laravel code and install Composer dependencies.
2. Run the feature migration on the server only.
3. Configure Reverb credentials, allowed origins, secure reverse proxy, broadcast connection, and a supervised Reverb process.
4. Configure a queue worker when production does not use synchronous queues.

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan reverb:start --host=0.0.0.0 --port=8080
php artisan queue:work --queue=default --tries=3
```

Run Reverb and the queue worker under Supervisor/systemd. The public reverse proxy must forward secure WebSocket traffic to the Reverb port and allow `/api/broadcasting/auth` over HTTPS.

Configure the apps with the same public Reverb endpoint used by the server:

```text
--dart-define=REVERB_APP_KEY=<public-app-key>
--dart-define=REVERB_HOST=<public-websocket-host>
--dart-define=REVERB_PORT=443
--dart-define=REVERB_SCHEME=wss
```

Required Laravel environment values are documented in `.env.example`: `BROADCAST_DRIVER=reverb`, `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, host/port/scheme, and allowed origins.

## Contract scenarios

1. Log in with Store account A and create one general conversation.
2. Start product support for an eligible listing; invoke the same action twice and confirm one open conversation.
3. Log in with Store account B and confirm A's conversation, messages, attachment, and private channel all return 403/404.
4. From authorized Admin support, reply and verify A receives the message immediately while foregrounded and an FCM deep link while backgrounded.
5. Disable networking on Store, send/retry, restore networking, and confirm one ordered message.
6. Close the conversation in Admin and confirm Store sends fail until Admin reopens it.
7. Re-run existing employee-support list, message, attachment, reaction, unread, and status scenarios.

## Safe local validation

- Laravel: PHP syntax checks, Pint on changed files, unit tests that do not access the database, route discovery, and `git diff --check`.
- Flutter Store/Admin: format, targeted analyze, focused controller/widget tests, and `git diff --check`.
- Do not run Doctor Bike local migrations or database-backed tests.
