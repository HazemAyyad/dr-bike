# Feature Specification: Online Store Live Support Chat

**Feature Branch**: `main`

**Created**: 2026-10-09

**Status**: Ready

**Input**: Store users can open a live support conversation from any product or start a general support conversation; authorized staff manage and reply from the Admin app.

## User Scenarios & Testing

### User Story 1 - Start and continue a Store support conversation (Priority: P1)

An authenticated Store user opens a general support conversation, sends messages, sees replies without manually refreshing, and can resume the conversation later.

**Why this priority**: This is the minimum complete support experience.

**Independent Test**: Log in as a Store user, create a general conversation, exchange messages with an authorized support user, close and reopen the Store app, and verify the history and unread state remain correct.

**Acceptance Scenarios**:

1. **Given** an authenticated active Store user, **When** the user starts a general conversation with a non-empty message, **Then** the conversation is created and appears in both Store and Admin inboxes.
2. **Given** an open conversation on both apps, **When** either party sends a message, **Then** the other open app displays it without manual refresh and the background app receives a notification.
3. **Given** a failed or repeated network submission, **When** the client retries the same message, **Then** the message appears exactly once.

---

### User Story 2 - Ask support about a product (Priority: P1)

A Store user starts support from a product detail page and both parties retain a clear, durable reference to the authoritative Store listing and its product.

**Why this priority**: Product-context conversations are the primary requested commerce workflow.

**Independent Test**: Open an eligible product, start a support conversation, and verify Store and Admin show the same product context and can return to product details.

**Acceptance Scenarios**:

1. **Given** an eligible Store listing, **When** the user selects “Ask support about this product,” **Then** the new or existing open conversation includes that listing's server-verified product context.
2. **Given** a listing identifier that is missing or not eligible, **When** a client attempts to create a product conversation, **Then** creation fails without exposing unrelated product data.
3. **Given** an already open conversation for the same user and listing, **When** the entry action is used again, **Then** that conversation is resumed instead of duplicated.

---

### User Story 3 - Manage Store conversations in Admin (Priority: P1)

Authorized support staff distinguish Store and employee conversations, find conversations needing replies, assign ownership, reply, and close or reopen the conversation without losing the existing employee-support flow.

**Why this priority**: Store conversations have no value unless staff can respond safely and efficiently.

**Independent Test**: Grant Store-support permission to one employee, verify only authorized staff can list and reply to Store conversations, and verify the existing employee conversation path still works.

**Acceptance Scenarios**:

1. **Given** an authorized support user, **When** the user opens the support inbox, **Then** source, requester, product context, unread state, status, and assignee are visible.
2. **Given** an unauthorized employee, **When** the user attempts to access a Store conversation directly, **Then** access is denied.
3. **Given** an existing employee-support conversation, **When** the feature is deployed, **Then** its list, message, reaction, unread, and status behavior remains compatible.

### Edge Cases

- The Store user becomes blocked, archived, or loses a valid token while connected.
- A product is unpublished or deleted after the conversation is created.
- Messages arrive out of order or the realtime connection reconnects after missing events.
- A conversation is closed while a client is composing or retrying a message.
- An attachment is missing, expired, too large, or has an unsupported type.
- The same account opens the same conversation on multiple devices.

## Requirements

### Functional Requirements

- **FR-001**: The system MUST allow an authenticated, unblocked Store user to create and list only that user's support conversations.
- **FR-002**: A conversation MUST be either general or associated with one authoritative Online Store listing.
- **FR-003**: Product context MUST be validated and derived by the server; client-supplied product names, images, prices, or ownership decisions MUST NOT be trusted.
- **FR-004**: Re-entering support for the same listing MUST resume an existing open or pending conversation for that requester.
- **FR-005**: Store users and authorized support staff MUST exchange text and supported image attachments.
- **FR-006**: Retried message requests with the same client message identity MUST be idempotent.
- **FR-007**: New messages, conversation changes, and read state MUST update active clients in realtime with reconnection recovery.
- **FR-008**: Background or closed mobile apps MUST receive a push notification that deep-links to the owned conversation.
- **FR-009**: Store users MUST NOT read, modify, subscribe to, or download another requester's conversation data.
- **FR-010**: Admin access MUST use a distinct Online Store Support permission while administrators retain access.
- **FR-011**: Support staff MUST filter Store conversations, see conversations needing a reply, assign a responder, and change status.
- **FR-012**: Existing employee-support conversations and clients MUST remain backward compatible.
- **FR-013**: Product context MUST remain readable from a stored snapshot if the listing later becomes unavailable.
- **FR-014**: Attachments MUST be served through expiring authorized access and MUST NOT expose raw private storage paths.
- **FR-015**: Closed conversations MUST reject new messages until an authorized support user reopens them.

### Key Entities

- **Support Conversation**: A support thread, its requester source and identity, optional product context, status, assignment, unread counts, and timestamps.
- **Support Message**: One idempotent message sent by a Store requester, employee requester, support user, or system.
- **Support Attachment**: A private image or supported file belonging to one support message.
- **Product Context Snapshot**: A durable display snapshot tied to an authoritative Online Store listing and product identity.
- **Realtime Subscription**: An authorized, temporary connection scoped to one conversation or the staff inbox.

## Success Criteria

### Measurable Outcomes

- **SC-001**: A logged-in Store user can open a general or product conversation and send the first message in under one minute.
- **SC-002**: While both apps are connected, 95% of new messages become visible to the other party within two seconds.
- **SC-003**: Replaying the same send request never produces more than one stored message.
- **SC-004**: All tested attempts to access another user's conversation, attachment, or live channel are denied.
- **SC-005**: Existing employee-support regression scenarios continue to pass after deployment.
- **SC-006**: A disconnected client restores every missed message after reconnection without duplicates or reordering.

## Assumptions

- Store chat requires login; anonymous visitors continue using the existing contact methods.
- Version one supports text and image attachments; voice, video, and agent-presence status are outside the required launch scope.
- Store conversations use the existing support domain and staff inbox instead of creating a second support source of truth.
- Firebase remains the background notification mechanism; realtime transport is used only while clients are connected.
- Database migrations are created locally but executed only by the user on the target server.
