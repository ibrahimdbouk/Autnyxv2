# Autnyx Teams app package

This is the small Teams app customers install so their users can receive Autnyx
**activity-feed notifications**. It is NOT part of the Laravel deploy — it is a
zip you (or the customer's Teams admin) upload to Teams.

## What it does
- Declares the `autnyxAlert` activity type used by Graph `sendActivityNotification`
  (the `templateText` `{alert}` is filled by the notification's `templateParameters`).
- Links the Teams app to the Autnyx Entra app via `webApplicationInfo.id` so the
  activity feed is authorized for this app.

## To build the package
1. Set `id` to a new GUID for the Teams app (generate once, keep it stable).
2. Set `webApplicationInfo.id` to the Autnyx Entra app **client id** (Application ID).
3. Add two PNG icons in this folder:
   - `color.png` — 192×192, full colour.
   - `outline.png` — 32×32, transparent, single-colour.
4. Zip the three files (`manifest.json`, `color.png`, `outline.png`) at the **root**
   of the zip (no enclosing folder). That zip is the app package.

## To install (customer tenant)
- Teams Admin Center → Manage apps → Upload, or sideload via Teams → Apps → Manage
  your apps → Upload a custom app. Users must have the app installed (personal
  scope) to receive activity-feed pings.

See `claude/teams-notifications.md` for the full onboarding runbook (Entra app,
admin consent, permissions).
