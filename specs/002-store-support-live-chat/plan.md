# Implementation Plan: Online Store Live Support Chat

**Branch**: `main` | **Date**: 2026-10-09 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/002-store-support-live-chat/spec.md`

## Summary

Extend the existing support conversation domain additively for authenticated Store requesters and optional Online Store listing context, expose isolated Store compatibility endpoints, add private Reverb channels with REST recovery and FCM fallback, and update both Flutter clients without breaking employee support.

## Technical Context

**Language/Version**: PHP 8.2.1, Laravel 10.48.29, Dart/Flutter versions pinned by each app

**Primary Dependencies**: Laravel Sanctum, Laravel Reverb, Firebase Messaging, Dio/GetX, `pusher_channels_flutter`

**Storage**: Existing MySQL/MariaDB schema plus additive support columns; local private filesystem for new Store attachments

**Testing**: PHPUnit feature/unit tests, Flutter unit/widget tests, PHP lint, Pint, Dart analyzer

**Target Platform**: Laravel Linux server; Flutter Android/iOS Store and Admin apps

**Project Type**: API plus two mobile applications

**Performance Goals**: Connected message delivery under two seconds for 95% of sends; incremental history retrieval; no steady-state five-second polling

**Constraints**: Dirty worktrees must be preserved; no local database migration/test execution; existing Store compatibility and employee support remain additive and backward compatible

**Scale/Scope**: One support domain, two requester sources, one Store product context, two Flutter clients

## Constitution Check

- **Single source of truth**: PASS; Online Store listings remain authoritative and support stores references/snapshots only.
- **Explicit boundaries**: PASS; Store endpoints use explicit Online Store support routes and source values.
- **Server authority**: PASS; ownership, listing eligibility, status, and context are verified server-side.
- **Backward compatibility**: PASS; schema and payloads are additive with compatibility aliases.
- **Unified identity**: PASS; existing users table and Store token identity are reused.
- **Security**: PASS; owner authorization, private channels, idempotency, private attachments, and signed downloads are required.
- **Permissions/auditability**: PASS; existing permission model and assignment/status history are reused.
- **Testing**: PASS by planned feature and regression tests; local DB execution remains intentionally prohibited.
- **Migration safety**: PASS; nullable/defaulted additive columns and deterministic backfill.
- **Simplicity/reuse**: PASS; existing support models, admin screen, notification infrastructure, and product listing authority are extended.

## Project Structure

```text
F:/laragon/www/doctor-bike/
├── app/Events/Support/
├── app/Http/Controllers/API/Store/
├── app/Models/Support*.php
├── app/Services/Support/
├── database/migrations/
├── routes/api.php
├── routes/api_store.php
└── tests/Feature/Support/

F:/flutter_projects/doctorbike_store/
├── lib/features/support/
├── lib/features/acount/help_support_screen.dart
├── lib/features/product/product_details_screen.dart
└── test/support/

F:/flutter_projects/doctorbike/
├── lib/features/technical_support/
├── lib/core/services/support_realtime_service.dart
└── test/technical_support/
```

**Structure Decision**: Keep the existing three repositories and their auth/network stacks; no shared package is introduced.

## Complexity Tracking

No constitution violations require exceptions.
