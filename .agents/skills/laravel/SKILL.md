---
name: laravel
description: Laravel backend architecture rules. Use when modifying PHP files, Laravel controllers, models, services, requests, resources, migrations, routes, jobs, or tests.
---

# Laravel Backend Skill

Identify: Trigger when file extension is `.php` or working in a Laravel workspace.

## Core Rules

- Follow "Skinny Controllers, Fat Models" or isolate business logic into dedicated Action classes.
- Use explicit Form Requests for data validation instead of validating inline inside controllers.
- Prevent N+1 query bugs by aggressively utilizing eager loading (`with()`).
- Leverage native features where appropriate (e.g., Eloquent API Resources, Database Transactions, Job Queues).
- Write Pest or PHPUnit feature tests for all newly added endpoints.
