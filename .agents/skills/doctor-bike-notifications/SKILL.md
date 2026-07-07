---
name: doctor-bike-notifications
description: Doctor Bike notification tracing rules. Use when working with push notifications, SMS, WhatsApp messages, notification logs, admin/device tokens, check notifications, sales order notifications, or delivery questions.
---

# Doctor Bike Notifications

Use this skill to avoid mixing stored notification records with real delivery.

## Core Distinctions

- A database notification record does not prove a push was delivered.
- A log row marked sent does not always prove the external provider accepted delivery.
- Admin push, owner/customer SMS, WhatsApp messages, and in-app status updates are separate channels.
- Always keep recipient, channel, direction, and delivery provider explicit.

## Trace Checklist

- Identify trigger event and exact route/controller/service.
- Identify recipient type: admin, employee, customer, seller, owner, or store user.
- Identify channel: FCM push, SMS, WhatsApp, database notification, or UI refresh.
- Inspect token/phone availability before assuming delivery failure.
- Verify whether notification is sent before or after the local state transition.

## Doctor Bike Notes

- Check notification rules can be direction-aware and channel-aware.
- Owner-facing delivery may rely on phone/SMS when no push token model exists.
- Sales-order status changes may notify admin/employee devices but not necessarily store customers.
