# Real-time Scoring Architecture — Reverb & Echo

This document describes the real-time data flow used in the Tapak Suci Scoring System.

## Overview

The system uses **Laravel Reverb** (WebSocket server) and **Laravel Echo** (JS client) to push match state changes from the **Operator** (`FightMatchControl`) to the **Jury** (`FightJury`) pages in real-time — and vice versa for score submissions.

Two dedicated channels are used to separate concerns:

| Channel         | Direction             | Event                  | Purpose                          |
|-----------------|-----------------------|------------------------|----------------------------------|
| `match.status`  | Operator → Jury       | `ActiveMatchUpdated`   | Match state (status/round/sync)  |
| `match.score`   | Jury → Operators/etc  | `JuryScoreUpdated`     | Score/punishment input by jury   |

```
                          match.status Channel
┌──────────────────────┐  ─────────────────────────────▶  ┌──────────────────┐
│  FightMatchControl   │                                   │   FightJury (x4) │
│  (Operator Browser)  │  ◀─────────────────────────────  │  (Jury Browsers) │
└──────────────────────┘          match.score Channel      └──────────────────┘
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

## Two-Way Architecture

The WebSocket communication flows in both directions to keep Operators and Juries perfectly in sync.

```
┌──────────────────────┐   match.control Channel   ┌──────────────────┐
│  FightMatchControl   │ ◀──────────────┐          │ FightJury (x4)   │
│  (Operator Browser)  │                │          │ (Jury Browsers)  │
└───────────┬──────────┘                │          └─────────┬────────┘
            │                           │                    │
        syncMatch()                     │               submitScore()
       updateStatus()           .JuryScoreUpdated        deleteScore()
       updateRound()                    │                    │
            │                           │                    │
┌───────────▼──────────┐                │          ┌─────────▼────────┐
│ MatchSyncController  │                │          │JuryScoreController│
└───────────┬──────────┘                │          └─────────┬────────┘
            │                           │                    │
   .ActiveMatchUpdated──────────────────┘                    │
            │                                                │
            └───────────────▶ Laravel Reverb ◀───────────────┘
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

## Event: `JuryScoreUpdated`

**File:** `app/Events/JuryScoreUpdated.php`

| Property     | Type          | Description                        |
|-------------|---------------|------------------------------------|
| `partaiId`  | `int`         | ID of the current match            |
| `corner`    | `string`      | 'blue' or 'yellow'                 |
| `roundNum`  | `int`         | Round number scored                |
| `juryNum`   | `int`         | ID (1-4) of the acting jury        |
| `scoreDetail`| `array/null` | Detail object ingested/deleted.    |
| `recap`     | `array/null`  | Full updated recap for that round. |

### Broadcast Name

`.JuryScoreUpdated`

### Dispatched From

| Controller Method             | Trigger                           |
|-------------------------------|-----------------------------------|
| `JuryScoreController@storeScore`  | Any Jury clicks Score/Punishment |
| `JuryScoreController@deleteScore` | Any Jury clicks Delete           |

### Behavior on Client
When `JuryScoreUpdated` is broadcasted back to `FightJury`:
- Points are dynamically pushed/spliced into reactive local arrays without reloading the page.
- Total amounts automatically reflect new values via the updated `recap` broadcast.

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
