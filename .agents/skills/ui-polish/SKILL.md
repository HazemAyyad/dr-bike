---
name: ui-polish
description: UI polish skill for Doctor Bike screens. Use when improving Flutter screens, web templates, forms, lists, filters, dialogs, cards, empty states, loading states, or visual consistency.
---

# UI Polish

Use this skill to make interfaces feel cleaner without changing the business flow.

## Rules

- Keep Doctor Bike operational screens dense, clear, and easy to scan.
- Match nearby spacing, colors, button styles, search patterns, and app bars.
- Prefer reusable widgets only when they reduce real duplication.
- Add loading, empty, error, disabled, and success states where the workflow needs them.
- Avoid decorative redesigns that make daily admin work slower.

## Flutter Focus

- Prevent overflow on small screens.
- Keep controls reachable and labels readable in Arabic and English.
- Keep search/filter behavior aligned with the controller state.
- Use responsive constraints instead of hardcoded dimensions where content can vary.

## Review Before Done

- Can the user complete the main action quickly?
- Does the screen preserve current filters/view mode?
- Does the UI show what changed after save/cancel/status actions?
