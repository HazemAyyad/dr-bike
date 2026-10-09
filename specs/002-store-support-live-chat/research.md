# Research: Online Store Live Support Chat

## Decision: Extend the existing support domain

**Rationale**: The current Laravel models already own conversations, messages, attachments, reactions, unread counts, status, assignment, notifications, and the Admin support UI. Additive requester-source and product-context fields preserve one support inbox and existing employee conversations.

**Alternatives considered**: Separate Store support tables were rejected because they duplicate message, attachment, permission, and staff workflows.

## Decision: Keep Store compatibility routes separate

**Rationale**: Store Sanctum tokens resolve to `StoreUser`, while the current staff controller assumes an employee relationship. Dedicated Store endpoints prevent mass-assignment and authorization ambiguity while sharing domain models and services.

**Alternatives considered**: Sending Store traffic to `/api/support/*` was rejected because it risks breaking existing staff assumptions.

## Decision: Laravel Reverb with Pusher protocol clients

**Rationale**: The installed PHP 8.2.1 and Laravel 10.48.29 satisfy Reverb's supported baseline. Reverb supplies private channels without a commercial realtime dependency, and the official Flutter Pusher client speaks the same protocol.

**Alternatives considered**: Five-second polling is not live and adds load; Pusher Cloud introduces an avoidable service dependency; custom sockets add unjustified security and operations work.

## Decision: REST remains authoritative; realtime is invalidation/delivery

**Rationale**: Messages are stored through authenticated REST transactions. Broadcast events contain serialized committed records. Reconnect recovery uses monotonic message IDs so socket loss never loses durable state.

**Alternatives considered**: Writing through client WebSocket events was rejected because it weakens validation, idempotency, and auditability.

## Decision: Private attachments with temporary signed downloads

**Rationale**: Store support can contain customer/product images. Private disk storage and expiring signed download URLs avoid public permanent media paths while remaining usable by both Flutter apps.

**Alternatives considered**: Existing public attachment storage is retained only for backward compatibility with old employee-support messages.
