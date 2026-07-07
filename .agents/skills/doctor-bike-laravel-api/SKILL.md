---
name: doctor-bike-laravel-api
description: Doctor Bike Laravel API implementation rules. Use for PHP, routes, controllers, services, models, resources, migrations, jobs, notifications, validation, or endpoint changes in F:\laragon\www\doctor-bike.
---

# Doctor Bike Laravel API

Use this skill for backend changes in the Doctor Bike Laravel project.

## Implementation Rules

- Keep controllers thin; move business logic to services, actions, models, or jobs when logic grows.
- Validate with Form Requests when adding or reshaping endpoints, unless the local pattern clearly uses another approach.
- Use eager loading for relation-heavy responses.
- Wrap multi-step state transitions in transactions when partial updates would be harmful.
- Keep API response shapes compatible with the Flutter app.

## Debug Rules

- Start from `routes/` and trace into the real controller method.
- Follow service classes before changing model behavior.
- Check logs and database state after identifying the relevant request.
- Distinguish stored records, computed response fields, and external provider delivery.

## Validation

- Run `php -l` on changed PHP files.
- Add/update focused tests when adding new endpoint behavior or risky state transitions.
