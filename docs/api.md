# Read-Only REST API

`api.php` exposes published tournament data as JSON. It's a plain-PHP front
controller with no framework and no new dependencies — it reuses the same
`DB_read_functions.php` layer the rest of the app is built on.

**GET only.** There is no write API. Every other HTTP method gets a 405.

## Base URL and versioning

```
/api.php/v1/<resource>
```

The version segment is required. Unrecognized or missing versions return 404,
as does any unrecognized route.

## Response envelope

Success:
```json
{ "data": ... }
```

Error:
```json
{ "error": { "code": "not_found", "message": "Event not found" } }
```

| Status | Meaning |
|---|---|
| 200 | Success (including empty collections) |
| 400 | Malformed query parameter (e.g. `?groupSet=abc`, unknown `?groupType=`) |
| 404 | Unknown route/version, nonexistent resource, or an **unpublished** event/tournament/match (see below — 404, not 403, so existence isn't revealed) |
| 405 | Non-GET method (`Allow: GET` header included) |
| 500 | Internal/database error (see "Error handling" below) |

## Endpoints

| Route | Notes |
|---|---|
| `GET /v1/events` | Published or archived events only. `?limit=` (int, default unlimited). |
| `GET /v1/events/{id}` | Event detail + publication flags (`rosterPublished`, `schedulePublished`, `matchesPublished`, `isArchived`). |
| `GET /v1/events/{id}/tournaments` | All tournaments at the event, keyed by tournamentID. |
| `GET /v1/events/{id}/participants` | Full event roster (all tournaments combined). |
| `GET /v1/tournaments/{id}` | Tournament detail + format flags (`isPools`, `isBrackets`, `isTeams`, `isResultsOnly`, `isFinalized`). |
| `GET /v1/tournaments/{id}/participants` | Roster keyed by rosterID, including `name`. |
| `GET /v1/tournaments/{id}/pools` | `?groupSet=` (int, default 1). |
| `GET /v1/tournaments/{id}/pool-matches` | `?groupSet=` (int, default 1). Nested by groupID then matchID. |
| `GET /v1/tournaments/{id}/brackets` | `{ elimType, brackets: { <bracketType>: { groupID, numFighters, matches: {...} } } }`. Empty when no bracket exists yet. |
| `GET /v1/tournaments/{id}/standings` | `?groupSet=` (int, default 1), `?groupType=pool\|finals` (default `pool`). |
| `GET /v1/matches/{id}` | Full match detail (fighters, scores, schools, tournament context). |
| `GET /v1/matches/{id}/exchanges` | The scored exchange log for a match. |

All IDs are path segments and must be positive integers, e.g.
`/api.php/v1/tournaments/42/pools`.

## Visibility (publication gating)

The API has no login — it always runs with every permission flag off. Every
resource is gated on the event's existing publication predicates
(`isEventPublished`, `isRosterPublished`, `isMatchesPublished`), the same
flags event organizers set from the admin UI. An unpublished, hidden, or
nonexistent event/tournament/match returns a plain 404 — the response never
distinguishes "doesn't exist" from "exists but isn't public."

Archived events are always treated as published (this matches the rest of
the app's behavior, not something specific to the API).

## Data shape notes

- All values coming out of MySQL are **strings**, including numbers and
  booleans-as-0/1 — the API passes them through as-is rather than guessing
  types. If you need `4` instead of `"4"`, cast on the client side.
- Collections keyed by ID (e.g. `/events`, `/events/{id}/tournaments`) are
  JSON **objects**, not arrays — even when empty (`{}`, never `[]`), so you
  can rely on `Object.keys()`/`Object.values()` regardless of count.
- `/tournaments/{id}/pool-matches` and `/tournaments/{id}/brackets` nest
  match data by groupID/bracketLevel/bracketPosition, matching the
  underlying app's grouping rather than a flat match list.

## Known limitations

- **`?groupType=finals` on `/standings`** can return an empty result for
  double-elimination tournaments. The underlying app function
  (`getTournamentStandings()`'s finals branch) looks up bracket info by a
  `'winner'`/`'loser'` string key, but `getBracketInformation()` actually
  keys brackets by bracket number — a pre-existing mismatch in the app, not
  introduced by this API. It fails safe (empty result, not an error).
- **No rate limiting.** Deferred deliberately — this endpoint only serves
  data that's already public via the normal web pages, so the exposure is
  DB load, not secrecy. If it needs to be added, MySQL is this app's only
  cross-request shared state (no APCu/Redis), so a limiter would need a new
  table; see the project's implementation notes for a ready design.

## Error handling

A database error, or any other fatal, is caught by a shutdown handler and
converted into a `500 {"error":{"code":"internal_error",...}}` — you should
never see raw HTML or a partial response from this API, even when the
underlying `mysqlQuery()` helper hits its own `die()`-on-error path.

## Adding a new endpoint

1. Add a handler function to `includes/api/handlers.php`. It receives the
   route's captured IDs as an ordered array and must end by calling
   `apiRespond($data)` or `apiError($status, $code, $message)`.
2. Add its route to the table in `api.php`.
3. Gate it with the existing `apiRequire*Published()` / `apiRequireTournamentEvent()`
   / `apiRequireMatchEvent()` helpers rather than querying publication flags
   directly.
4. Prefer a `DB_read_functions.php` function that takes the ID as an
   explicit parameter over one that falls back to `$_SESSION` — the API
   bootstrap (`includes/api_bootstrap.php`) seeds a stateless session, so a
   session-fallback read would silently return the wrong (empty) data for a
   real request.
