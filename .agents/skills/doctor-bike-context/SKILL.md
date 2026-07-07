---
name: doctor-bike-context
description: Doctor Bike project map and token-saving orientation. Use before cross-stack Doctor Bike work, especially when a task may involve both the Laravel backend at F:\laragon\www\doctor-bike and the Flutter admin app at F:\flutter_projects\doctorbike.
---

# Doctor Bike Context

Use this skill to avoid rediscovering the project shape on every task.

## Project Map

- Laravel backend: `F:\laragon\www\doctor-bike`.
- Flutter admin/app project: `F:\flutter_projects\doctorbike`.
- Store Flutter project may be separate: `F:\flutter_projects\doctorbike_store`.
- When the user describes app behavior, check whether the change belongs in Flutter, Laravel, or both.
- When the user says not to modify, inspect and answer with evidence only.

## Default Workflow

- Start with the exact feature name, screen, API route, controller, service, model, and log path.
- Prefer `rg` targeted searches over broad file reads.
- Read only the files needed to prove the path.
- Keep backend and Flutter changes coordinated when the behavior crosses the API boundary.
- Preserve unrelated local changes.

## Token-Saving Rules

- Do not reread whole modules when a focused symbol search is enough.
- Prefer current files and route/controller names over general framework explanations.
- Summarize discovered paths once, then reuse that map during the task.
- Validate with the narrowest reliable command before escalating to full builds.
