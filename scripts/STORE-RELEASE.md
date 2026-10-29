# Store staged release and recovery checklist

This is a prepared release procedure, not a record of production deployment.
Production code, configuration, migrations, distribution and version enforcement
require separate authorization. The user deferred phone testing and will build
the APK; no APK build or workflow dispatch is part of this verification.

## Independent candidates

| Stage | Revision / branch | Prerequisite |
| --- | --- | --- |
| Compatible server security, shipping and catalog changes | `09bdc1c8a74e2085514882cba720fc3e094c17ed`, `codex/store-hardening-verified` | Existing Laravel 10 / PHP 8.2 runtime; verified backup and old-client smoke checks |
| Store app | `4c1227da9c5c9d6138a0bd9858f4956ef81f1bed`, `codex/store-hardening` in `dr-bike-store` | Compatible server additions first; user-built APK and physical upgrade acceptance |
| Framework upgrade | `codex/laravel13-store` in `dr-bike` | Separate release: PHP 8.3 with sodium, matching vendor artifact, shared administration acceptance and selected broadcaster/scheduler checks |

Keep the urgent server release independent of the framework upgrade. The
framework branch includes the compatible store changes but is not the initial
server deployment target. Its exact quality-tested revision is recorded in
[LARAVEL13-UPGRADE.md](LARAVEL13-UPGRADE.md).

## Current evidence and unresolved gates

Read-only production inspection on 29 October 2026 located the application at
`/home/u227071417/domains/dr-bike.shop/public_html/dr-bike`. The deployed lock
records Laravel 10.48.29; the web and default CLI use PHP 8.2.35. HTTP health
returned 200. This does not prove a particular candidate has been deployed.

The public Android update-check endpoint was queried for store version 1.0.1,
build 11. It returned success, `latest_build=0`, `minimum_build=0`,
`has_update=false`, `force_update=false`, and an empty URL. No setting was
changed. A release URL is still required; these defaults do not identify the
last APK actually distributed to users.

The retained previous local APK has package `com.mahm.doctor_bike`, version
1.0.0, build 1, minimum SDK 26 and target SDK 36. Its file SHA-256 is
`e518036c6b31059f263d685a1a68d20c71f4a82a006b1d7e97441d84cf345d5c`.
The candidate source declares 1.0.1+11, which exceeds that local APK and the
current server minimum. The last distributed APK's build remains unverified;
do not assume the retained local APK is the newest distributed one.

Expected release certificate SHA-256:
`292e8bc5add9e2da40a4b4a04d3634dbf827fa7ce3a9bad6ea599ab3fcbab293`.
The user-built final APK must match this identity and the package above. Record
its own file hash, version, build, ABI and SDK metadata before distribution.

Automated server and Flutter CI passed; links and tested SHAs are in the upgrade
record. Local database restore verified the supplied 28 October export and
preserved 288 business tables / 273677 rows through the compatibility migration
and representative reads. That isolated restore is not a fresh production
backup or proof of recovery of production uploads and environment files.

The remaining acceptance evidence is:

- Physical Android update without uninstalling: existing cart, locale, session
  migration, offline logout/reconnect, notification and deep-link delivery,
  guest and signed-in checkout, plus Arabic/English/Hebrew accessibility in both
  themes. User deferred this work; do not manufacture a pass from widget tests.
- Release-device Crashlytics test in the intended Firebase project, with no
  personal data, tokens or request bodies in the event. Debug/mock success is
  insufficient. Apple device/environment validation remains pending for iOS.
- Hosting PHP 8.3 and sodium for web, Composer, scheduler and workers before
  deploying the framework candidate. Do not switch the live Laravel 10 runtime
  as a standalone preparatory change: its locked mPDF release targets PHP 8.2.
- Existing hPanel scheduler entries and recent run logs. `crontab -l` being
  unavailable over SSH proves neither success nor absence. The latest observed
  cron rows and backup-file timestamps were from 20 October when checked on
  29 October; fresh execution and backup evidence are required.
- Preserve the existing Pusher broadcaster: its hosted TLS WebSocket connection
  and connection-established event passed a read-only check. Controlled private
  authorization and application event delivery remain pending. A local Reverb
  delivery test also passed, but deploying Reverb and its proxy/worker requires a
  separate decision to change the broadcaster.
- Authorized production backup/restore verification, APK URL, limited pilot,
  48-hour observation, and separately approved expansion/version enforcement.

## Backup and deployment sequence after authorization

1. Re-inspect the exact application root, Git revision, local modifications and
   migration ledger immediately before release. Record deployment time and
   prevent overlapping release or restore operations.
2. Back up the current code revision, its `composer.lock` and working vendor
   artifact. Preserve the web PHP selection and the exact scheduler/worker
   commands. Keep the backups outside the public web root with restricted access.
3. Back up the production database consistently, and separately preserve `.env`,
   the current `APP_KEY`, `storage`, uploaded files, storage links and any
   non-repository operational assets. Do not print credentials in commands or
   logs. Confirm archive readability, hashes, available space and retention of
   the previous known-good set.
4. Restore that release's backup in an isolated environment. Verify schema and
   row fingerprints, representative accounting/inventory/order reads, uploads,
   environment preservation and the previous application's ability to boot.
   Record results; a successfully created archive alone is insufficient.
5. Prepare the exact candidate dependency artifact from its own lock file.
   Confirm platform requirements using the actual release PHP binary and its
   enabled extensions. For Laravel 13, align website and worker PHP as part of
   the reviewed release; the tested process prefix is
   `/opt/alt/php83/usr/bin/php -d extension=sodium`, which does not configure
   website PHP on its own.
6. Deploy the approved compatible server stage first. Keep `.env`, `APP_KEY`,
   storage/uploads and business records intact. Apply only individually
   authorized migrations; do not run `migrate:fresh`, blanket rollbacks or seed
   operations on production. No migration execution is delegated by this plan.
7. Rebuild the intended application caches and restart affected workers under
   the release runtime. Verify guest browsing, existing account access,
   authorization boundaries, shipping errors and replay-safe checkout in a
   controlled acceptance environment; real financial orders require explicit
   test arrangements. Smoke-test the shared administration paths for pricing,
   stock, finance, reports, PDF, Excel and uploaded media.
8. After the user's APK acceptance, publish to a small pilot group using a
   stable HTTPS link. Verify the downloaded bytes, package, build and signing
   certificate. Keep force-update disabled until the pilot and recovery path
   are accepted. Record pilot start/end, affected app/server revisions and
   observed outcomes. Advance the existing minimum-build mechanism only through
   a separately authorized settings change.

## Pilot observation and rollback

For 48 hours after the actual pilot starts, record authentication errors, shipping
quote failures, checkout/replay outcomes, catalog latency and sanitized crashes.
Correlate client and server with `X-Request-ID`; never collect tokens, passwords,
addresses, order contents or private account payloads as monitoring evidence.
Use the existing two-second synthetic catalog gate as test evidence only;
capture the real production baseline before judging a latency regression.

Stop pilot expansion immediately for unauthorized account access, lost carts,
incorrect shipping charges, duplicate orders or financial/stock inconsistency.
Record the symptom, request ID, time and affected revision. A retry after a
timeout must reuse the original checkout request identifier.

For server recovery, restore the previous code together with its own lock/vendor
artifact and compatible runtime. Rebuild caches and restart workers. Keep
business records, `.env`, `APP_KEY`, storage and uploaded files. Do not restore
an old database over new transactions or reverse unrelated migrations as a code
rollback; any required data repair/recovery needs its own reviewed procedure.
The additive fresh-catalog compatibility migration intentionally removes no
legacy data on rollback.

For app recovery, preserve the same package and signing key and use a build
number higher than the failed distributed release. Android normally rejects a
lower-version replacement. Keep server compatibility while a recovery APK is
built and tested by the user; do not instruct customers to uninstall and lose
their local data.

The release is accepted only after all relevant device, hosting, backup/restore,
pilot and monitoring records exist. Preparing this checklist does not close G09.
