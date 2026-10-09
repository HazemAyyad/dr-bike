# Data Model: Online Store Live Support Chat

## SupportConversation additions

- `source`: string, required, default `employee`, allowed `employee|online_store`.
- `requester_user_id`: nullable foreign key to `users`, null on delete; required for new Store conversations.
- `online_store_listing_id`: nullable foreign key to `online_store_listings`, null on delete; required only for product context.
- `context_type`: string, required, default `general`, allowed `general|product`.
- `context_snapshot`: nullable JSON containing server-derived `listing_id`, `product_id`, localized names, primary image path, and model.
- `requester_unread_count`: unsigned integer, default zero.
- `first_support_response_at`: nullable timestamp.
- `last_requester_message_at`: nullable timestamp.
- `last_support_message_at`: nullable timestamp.
- Existing `employee_id` becomes nullable. Existing `employee_unread_count` remains as a compatibility alias for employee-source clients during rollout.

Indexes: `(source,status,last_message_at)`, `(requester_user_id,status)`, `(requester_user_id,online_store_listing_id,status)`.

## SupportMessage additions

- `client_message_id`: nullable UUID string.
- `sender_type`: adds `store_customer` to existing employee/support/system values.
- Unique `(support_conversation_id, client_message_id)` when a client identity is present.

## SupportMessageAttachment behavior

- New Store-source attachments use disk `local` and non-public paths.
- Payloads expose a short-lived signed download URL, never the private path.
- Existing public employee-support attachments remain readable for compatibility.

## State transitions

- `open -> pending`: support is waiting for requester or has triaged the conversation.
- `open|pending -> closed`: authorized support closes the conversation.
- `closed -> open`: authorized support reopens the conversation.
- Store requesters cannot change status or send while closed.

## Ownership

- Store requester: authenticated token user ID equals `requester_user_id`, source is `online_store`, and user is active/unblocked.
- Employee requester: existing employee ID ownership rules remain unchanged.
- Support staff: administrator or employee with the relevant support permission.
