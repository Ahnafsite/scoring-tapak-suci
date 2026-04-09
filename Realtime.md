# Real-time Scoring Architecture — Reverb & Echo

This document describes the real-time data flow used in the Tapak Suci Scoring System.

## Overview

The system uses **Laravel Reverb** (WebSocket server) and **Laravel Echo** (JS client) to push match state changes from the **Operator** (`FightMatchControl`) to the **Jury** (`FightJury`) pages in real-time—without polling or page refresh.

```
┌──────────────────────┐       broadcast()        ┌──────────────────┐
│  FightMatchControl   │  ───── HTTP POST ──────▶ │ MatchSyncController│
│  (Operator Browser)  │                           │  (Laravel API)    │
└──────────────────────┘                           └────────┬─────────┘
                                                            │
                                                  ActiveMatchUpdated
                                                   (ShouldBroadcast)
                                                            │
                                                   ┌────────▼─────────┐
                                                   │  Laravel Reverb   │
                                                   │  (WebSocket)      │
                                                   └────────┬─────────┘
                                                            │
                                              Channel: match.control
                                              Event: .ActiveMatchUpdated
                                                            │
                                                   ┌────────▼─────────┐
                                                   │   FightJury       │
                                                   │ (Jury Browser x4) │
                                                   │  via Echo.listen  │
                                                   └──────────────────┘
```

## Configuration

### Environment Variables (`.env`)

```dotenv
BROADCAST_CONNECTION=reverb

REVERB_APP_ID=scoring-tapak-suci
REVERB_APP_KEY=scoringtapaksucikey
REVERB_APP_SECRET=scoringtapaksucisecret
REVERB_HOST=127.0.0.1
REVERB_PORT=8080
REVERB_SCHEME=http

VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

### Running Reverb

Locally with PM2:
```bash
# Direct start
php artisan reverb:start

# With PM2 (recommended for production)
pm2 start "php artisan reverb:start" --name reverb-scoring
```

### Frontend Echo Setup (`app.ts`)

```typescript
import { configureEcho } from '@laravel/echo-vue';

configureEcho({
    broadcaster: 'reverb',
});
```

## Event: `ActiveMatchUpdated`

**File:** `app/Events/ActiveMatchUpdated.php`

| Property     | Type          | Description                        |
|-------------|---------------|------------------------------------|
| `match`     | `array`       | Full FightMatch model as array     |

### Channel

| Type   | Name              | Auth Required |
|--------|-------------------|---------------|
| Public | `match.control`   | No            |

### Broadcast Name

`.ActiveMatchUpdated` (dot-prefixed because `broadcastAs()` is used)

### Dispatched From

| Controller Method             | Trigger                           |
|-------------------------------|-----------------------------------|
| `MatchSyncController@updateStatus`  | START / PAUSE / RESUME / KEPUTUSAN / RESET |
| `MatchSyncController@updateRound`   | Round change (1 → 2 → TBH)       |
| `MatchSyncController@syncMatch`     | New match loaded from API         |

### Dispatch Pattern

```php
broadcast(new ActiveMatchUpdated($match->fresh()))->toOthers();
```

`->toOthers()` prevents the broadcasting user (Operator) from receiving the event back on their own page.

## Frontend Listeners

### FightJury.vue

```typescript
// onMounted
const echo = window.Echo;
echo.channel('match.control')
    .listen('.ActiveMatchUpdated', (e) => {
        currentMatch.value = e.match;
    });
```

**Behavior:**
- `isLoading` = `true` when `currentMatch.status !== 'ongoing'`
- Only shows the scoring UI (form + buttons) when status is `ongoing`
- On `not_started` / `paused` / `done` → shows the waiting/logo state

## Status Flow

```
                    START
  not_started ──────────────▶ ongoing
       ▲                        │
       │ RESET            PAUSE │
       │                        ▼
       └───────────────── paused
                             │
                   KEPUTUSAN │
                             ▼
                           done
```

### Status → FightJury Behavior

| Status        | Jury Display                    |
|---------------|---------------------------------|
| `not_started` | Loading (TS Logo + waiting)     |
| `ongoing`     | Scoring UI (form + buttons)     |
| `paused`      | Loading (TS Logo + waiting)     |
| `done`        | Loading (TS Logo + waiting)     |

## Data Persistence

All state changes are **persisted to the database first**, then broadcast. This ensures:

1. When the Jury page is **refreshed**, it loads the latest state from DB via Inertia props
2. When the Operator changes status, the `fight_matches` AND `fight_schedules` tables are both updated
3. The WebSocket event is a **notification layer** on top of persisted data — not the source of truth

## Troubleshooting

| Issue | Solution |
|-------|----------|
| Echo not connecting | Ensure Reverb is running (`php artisan reverb:start`) |
| Events not received | Check `BROADCAST_CONNECTION=reverb` in `.env` |
| CORS issues | Reverb defaults to same-origin; check `REVERB_HOST` |
| Queue not processing | Events use `ShouldBroadcast` — ensure queue worker is running |
