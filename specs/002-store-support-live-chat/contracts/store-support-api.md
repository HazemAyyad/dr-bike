# Store Support API Contract

All Store endpoints require a valid Store Bearer token and reject blocked or archived users.

## Conversations

- `GET /OnlineStore/Support/Conversations?status=&before_id=&per_page=` lists only the authenticated requester's Store conversations.
- `POST /OnlineStore/Support/Conversations` accepts `context_type`, optional `listing_id`, `subject`, `message`, `client_message_id`, and optional image attachments.
- `GET /OnlineStore/Support/Conversations/{conversation}?before_id=&after_id=&per_page=` returns an owned conversation plus ordered messages.
- `POST /OnlineStore/Support/Conversations/{conversation}/Messages` accepts `message`, `client_message_id`, and optional image attachments.
- `POST /OnlineStore/Support/Conversations/{conversation}/Read` clears requester unread count.
- `GET /OnlineStore/Support/Conversations/UnreadCount` returns unread conversation and message totals.

Successful message payloads contain stable `id`, `client_message_id`, sender identity/type, body, message type, signed attachments, and ISO-8601 creation time.

## Staff compatibility API additions

- `GET /api/support/conversations` adds optional `source=employee|online_store` and `needs_reply=true` filters.
- Existing response fields remain; additive requester and product-context fields are returned.
- Existing show/send/read/status routes accept Store-source conversations only for authorized staff.

## Realtime channels

- Private conversation channel: `support.conversation.{conversationId}`.
- Private staff inbox channel: `support.inbox`.
- Events: `.support.message.created`, `.support.conversation.updated`, `.support.conversation.read`.
- Conversation subscription requires owner or authorized support access; inbox subscription requires authorized support access.

## Errors

- `401`: missing, invalid, expired, blocked, or archived Store identity.
- `403`: authenticated actor does not own or manage the resource/channel.
- `404`: conversation, listing, message, or attachment is absent or deliberately hidden by ownership rules.
- `409`: idempotency identity conflicts with different content.
- `422`: invalid context, empty message, unsupported attachment, or closed conversation.
- `429`: create/send throttling exceeded.
