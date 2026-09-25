# Shipping changes (W9 / W0)

## How a change reaches production

1. A change lands on `main` on GitHub.
2. CI runs the test suite. When it is green, CI fast-forwards `production`.
3. Laravel Cloud deploys `production` and runs `php artisan migrate --force && php artisan db:seed --force`.

## Two ways onto `main`

| | Auto-push (today) | Ship-watch (recommended) |
|---|---|---|
| What is committed | Everything in the folder, every minute | Only the files of a finished batch |
| Commit message | `auto: sync changes` | The change's real name (e.g. `WP9.7: DB tune-up …`) |
| Half-copied change | Can be pushed and deployed | Waits until every file's checksum matches |
| Your own edits | Pushed within a minute | Stay local until you run `scripts\ship.ps1` |

**Switch over** (once; about a minute):

```
powershell -ExecutionPolicy Bypass -File scripts\ship-setup.ps1
```

This registers the hidden every-minute task `AutnyxShipWatch` and disables `AutnyxAutoPush`.

- **Log:** `_ship\ship.log`.
- **Undo:** `scripts\ship-setup.ps1 -Revert`.

**Your own changes** after switching:

```
scripts\ship.ps1 -Message "What changed"
```

To commit only some files, add `-Paths a,b`.

## Batch format (what Claude writes)

```
_ship\<batch-id>\files\<repo path>   the new content of each file
_ship\<batch-id>\manifest.json       written last
```

`manifest.json` contains:

```
{ "message": "...", "files": [{ "path": "...", "sha256": "..." }], "delete": ["..."] }
```

- Batches ship in name order.
- A batch still incomplete after 30 minutes moves to `_ship\_failed`.
- A shipped batch moves to `_ship\_done`, which is kept for 14 days.

## Repo hygiene (once)

`scripts\repo-hygiene.ps1` stops tracking the following. The files stay on disk.

- generated exports (`Claude outputs\`);
- git bundles and patches;
- the personal auto-push scripts.

Run it without flags to preview, and with `-Apply` to commit and push.
