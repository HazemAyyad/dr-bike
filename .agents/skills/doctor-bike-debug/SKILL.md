---
name: doctor-bike-debug
description: End-to-end debugging workflow for Doctor Bike bugs. Use for broken flows, spinners, failed saves, wrong statuses, missing data, duplicate requests, or behavior that differs between Flutter and Laravel.
---

# Doctor Bike Debug

Use this skill when the question is "why did this happen?" or "why did it not happen?"

## Trace Order

- Reproduce the user's exact role, account, screen, action, and platform.
- Trace Flutter screen/controller/request payload first when the symptom is in the app.
- Trace Laravel route/controller/service/model/query next.
- Check database state and logs only after the request path is known.
- Separate legacy task flows from v2 template/occurrence flows.

## Common Guardrails

- Do not blame rate limits, cache, or backend state until request tracing proves it.
- Count duplicate request sources before changing backend logic.
- For employee tasks, check dashboard payloads, task details, edit paths, and occurrence IDs separately.
- For status bugs, verify whether the visible status, stored status, and notification status are the same thing.

## Output

- Explain the actual path found.
- Name the failing file/function/API when possible.
- If fixing, keep the change narrow and verify the touched path only.
