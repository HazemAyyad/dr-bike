---
name: grill-me
description: Strict review mode for Doctor Bike changes. Use when asked to review, audit, double-check, or "grill" code, UI, architecture, API behavior, or a proposed fix before delivery.
---

# Grill Me

Use this skill to find risks before the user does.

## Review Rules

- Lead with bugs, regressions, missing edge cases, and missing verification.
- Be direct and specific.
- Reference files, functions, routes, or screens instead of generic advice.
- Check whether the change fits the existing Doctor Bike patterns.
- Look for hidden cross-stack impact between Flutter and Laravel.

## Focus Areas

- Incorrect request payloads or response parsing.
- Duplicate network calls.
- Status transitions that update UI but not storage, or storage but not UI.
- Notification logs that do not prove delivery.
- N+1 queries or missing eager loading.
- Flutter widgets that mix business logic, overflow on mobile, or skip loading/error states.

## Output

- Findings first, ordered by severity.
- Then open questions.
- Then short summary or verification notes.
