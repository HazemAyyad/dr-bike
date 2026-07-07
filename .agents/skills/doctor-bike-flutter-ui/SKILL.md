---
name: doctor-bike-flutter-ui
description: Doctor Bike Flutter app UI and architecture rules. Use for Dart widgets, GetX controllers, screens, routes, API clients, models, responsive layouts, task screens, admin flows, and app-side debugging in F:\flutter_projects\doctorbike.
---

# Doctor Bike Flutter UI

Use this skill for Doctor Bike Flutter app work.

## Architecture Rules

- Keep widgets lean and move business logic into controllers/services.
- Preserve GetX route and binding patterns already used by the feature.
- Keep models and response parsing aligned with the backend response shape.
- Avoid duplicate fetches from pre-navigation, screen init, repeated taps, and refresh hooks.

## UI Rules

- Match nearby screen patterns for search, filters, tabs, app bars, task cards, and action menus.
- Make loading, empty, error, and post-save refresh behavior explicit.
- Prevent RenderFlex overflow and preserve Arabic/English labels.
- Use `const` constructors where practical.
- Prefer targeted responsive fixes over broad redesigns.

## Validation

- Format changed Dart files.
- Run focused `flutter analyze` on edited files/folders when possible.
- If local tooling blocks broad analysis, report the narrow validation that did run.
