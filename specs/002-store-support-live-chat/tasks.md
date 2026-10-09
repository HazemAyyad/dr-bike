# Tasks: Online Store Live Support Chat

**Input**: Design documents from `/specs/002-store-support-live-chat/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/

## Phase 1: Setup

- [X] T001 Add compatible Laravel Reverb and Flutter Pusher-protocol dependencies in `composer.json`, `F:/flutter_projects/doctorbike_store/pubspec.yaml`, and `F:/flutter_projects/doctorbike/pubspec.yaml`
- [X] T002 Configure broadcast provider, Reverb connection, environment examples, and private channel registration in `config/app.php`, `config/broadcasting.php`, `.env.example`, and `routes/channels.php`

## Phase 2: Foundational

- [X] T003 Add forward-safe support schema migration in `database/migrations/2026_10_09_000004_extend_support_conversations_for_online_store_chat.php`
- [X] T004 Extend support models and relationships in `app/Models/SupportConversation.php`, `app/Models/SupportMessage.php`, and `app/Models/SupportMessageAttachment.php`
- [X] T005 Add shared support payload, authorization, idempotency, attachment, and event services under `app/Services/Support/`
- [X] T006 Add private broadcast events and authorization tests under `app/Events/Support/` and `tests/Feature/Support/`

## Phase 3: User Story 1 - General Store support chat

- [X] T007 [P] [US1] Add Store support contract tests in `tests/Feature/Support/StoreSupportConversationTest.php`
- [X] T008 [US1] Implement Store support endpoints in `app/Http/Controllers/API/Store/StoreSupportConversationController.php` and `routes/api_store.php`
- [X] T009 [US1] Add Store/support push notification routing in Laravel notification services and Store notification payloads
- [X] T010 [P] [US1] Add Store support models/repository/realtime controller under `F:/flutter_projects/doctorbike_store/lib/features/support/`
- [X] T011 [US1] Implement Store support center, new general conversation, conversation history, optimistic send/retry, unread, and reconnect UI under `F:/flutter_projects/doctorbike_store/lib/features/support/`
- [X] T012 [US1] Wire Store routes, Help & Support entry, FCM deep link, and focused tests in `F:/flutter_projects/doctorbike_store/lib/` and `F:/flutter_projects/doctorbike_store/test/support/`

## Phase 4: User Story 2 - Product-context support

- [X] T013 [P] [US2] Add product-context ownership and reuse tests in `tests/Feature/Support/StoreProductSupportConversationTest.php`
- [X] T014 [US2] Validate authoritative listing context and snapshot behavior in the Store support service/controller
- [X] T015 [US2] Add “Ask support about this product” and context card/deep link behavior in `F:/flutter_projects/doctorbike_store/lib/features/product/product_details_screen.dart` and Store support UI
- [X] T016 [US2] Add product-context Store widget/controller tests in `F:/flutter_projects/doctorbike_store/test/support/`

## Phase 5: User Story 3 - Admin management

- [X] T017 [P] [US3] Add staff permission, source filter, needs-reply, payload, and employee-regression tests under `tests/Feature/Support/`
- [X] T018 [US3] Extend `app/Http/Controllers/API/SupportConversationController.php` for Store source, requester authorization, realtime events, and notification replies
- [X] T019 [P] [US3] Extend Admin support data models/service and add realtime client in `F:/flutter_projects/doctorbike/lib/features/technical_support/` and `lib/core/services/`
- [X] T020 [US3] Implement Admin Store/employee tabs, needs-reply/assignment filters, requester/product cards, live updates, and reconnect recovery in `F:/flutter_projects/doctorbike/lib/features/technical_support/presentation/technical_support_screen.dart`
- [X] T021 [US3] Add Admin focused service/widget tests in `F:/flutter_projects/doctorbike/test/technical_support/`

## Phase 6: Polish and validation

- [X] T022 Add attachment signed-download route, throttles, closed-state enforcement, and security regression tests
- [X] T023 Run Laravel syntax/Pint/unit/route checks without local DB execution and update `quickstart.md` with server migration/Reverb commands
- [X] T024 Run targeted Flutter format/analyze/tests in both apps and `git diff --check` in all repositories
- [X] T025 Review staged-scope candidates and verify unrelated dirty files remain preserved; do not commit or push without explicit request

## Dependencies & Execution Order

- Setup precedes foundational work; foundational work blocks all stories.
- US1 supplies the shared Store chat path used by US2.
- US3 can begin after foundational backend contracts but finishes after US1 message payloads stabilize.
- Polish requires all stories.

## Independent Test Criteria

- **US1**: Exchange a general message Store-to-Admin and Admin-to-Store with owner isolation, idempotent retry, realtime foreground delivery, background notification, and reconnect recovery.
- **US2**: Open an eligible product conversation twice and observe one thread with server-derived listing/product context in both apps.
- **US3**: Authorized support filters, opens, assigns, replies, and closes a Store conversation while an unauthorized employee is denied and an employee-support regression remains compatible.

## Implementation Strategy

Deliver the shared secure conversation domain first, then general Store chat, product context, Admin workflow, and cross-stack validation. All tasks use the required checkbox, task ID, story label where applicable, and concrete file paths.
