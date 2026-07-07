---
name: flutter
description: Flutter UI/UX and architecture workflow rules. Use when modifying Dart files, Flutter widgets, screens, controllers, state management, or pubspec.yaml.
---

# Flutter Development Workflow

Identify: Trigger when file extension is `.dart` or `pubspec.yaml` is modified.

## Core Rules

- Enforce strict separation of UI and business logic (e.g., BLoC, Riverpod, or Signals).
- Keep widgets atomic, lean, and extracted into smaller reusable components.
- Adhere strictly to the `flutter_lints` ruleset.
- Optimize rendering by using `const` constructors aggressively wherever applicable.
- Build fully responsive designs leveraging `LayoutBuilder` or `MediaQuery` instead of hardcoded point sizes.
