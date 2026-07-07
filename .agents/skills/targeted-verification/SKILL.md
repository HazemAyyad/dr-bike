---
name: targeted-verification
description: Lightweight validation workflow to reduce token and runtime cost. Use after Doctor Bike Laravel or Flutter edits, especially when full builds or full analyze commands are unnecessary or slow.
---

# Targeted Verification

Use the narrowest validation that can catch likely breakage.

## Laravel

- Run `php -l` on changed PHP files.
- For route/config changes, consider `php artisan route:list` or `php artisan config:clear` only when relevant.
- Use focused tinker/database checks only when behavior depends on stored state.

## Flutter

- Run Dart format on changed Dart files when syntax/layout changed.
- Run `flutter analyze` only on edited feature files or folders first.
- Expect full Flutter analysis/build to be slower and sometimes blocked by local tooling.
- Do not run full builds unless packaging, platform integration, or dependency changes require it.

## Reporting

- Say exactly what was validated.
- If a broad check was skipped, say why and what narrower signal passed.
