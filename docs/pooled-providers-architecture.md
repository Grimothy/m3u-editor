# Pooled Providers Architecture

This document describes the architecture of the Provider Profiles (pooled providers) system in m3u-editor, how its components relate to each other, and where to look when typical problems occur.

---

## Table of Contents

1. [System Overview](#system-overview)
2. [Component Map](#component-map)
3. [Data Flow Diagrams](#data-flow-diagrams)
4. [Redis Key Architecture](#redis-key-architecture)
5. [Concurrency & Locking Model](#concurrency--locking-model)
6. [Webhook Event System](#webhook-event-system)
7. [Stream Pooling Subsystem](#stream-pooling-subsystem)
8. [Where Symptoms Manifest](#where-symptoms-manifest)

---

## System Overview

Provider Profiles allows a single m3u-editor playlist to distribute viewer connections across multiple IPTV accounts (profiles). This multiplies the available concurrent streams without requiring separate playlists per account.

The system has three main responsibilities:

| Responsibility | Owner |
|---|---|
| Profile selection (which account serves this stream) | m3u-editor (ProfileService) |
| Connection count tracking (how many streams per account) | Redis (managed by ProfileService) |
| Stream pooling (sharing one transcode between multiple clients) | m3u-proxy + Redis |

---

## Component Map

```
┌─────────────────────────────────────────────────────────────────────┐
│                         CLIENT DEVICES                              │
│   (Kodi, VLC, Smart TV, Phone, etc. — any HLS/TS-compatible player) │
└───────────────────┬─────────────────────────────────────────────────┘
                    │  HTTP stream request
                    ▼
┌─────────────────────────────────────────────────────────────────────┐
│                         M3U-EDITOR                                  │
│                                                                     │
│  ┌──────────────────────────────────────────────────────────────┐   │
│  │  M3uProxyApiController                                       │   │
│  │  ├─ channel()       → resolves playlist + stream profile     │   │
│  │  └─ handleWebhook() → receives stream_stopped events         │   │
│  └─────────────────────────┬────────────────────────────────────┘   │
│                             │                                       │
│  ┌──────────────────────────▼────────────────────────────────────┐  │
│  │  M3uProxyService                                              │  │
│  │  ├─ getChannelUrl()              main orchestration           │  │
│  │  ├─ findExistingPooledStream()   pool reuse check             │  │
│  │  └─ buildTranscodeStreamUrl()    returns playback URL         │  │
│  └──────────────────────────┬────────────────────────────────────┘  │
│                             │                                       │
│  ┌──────────────────────────▼────────────────────────────────────┐  │
│  │  ProfileService                                               │  │
│  │  ├─ selectAndReserveProfile()   atomic lock + allocation      │  │
│  │  ├─ finalizeReservation()       placeholder → real stream     │  │
│  │  ├─ decrementConnections()      stream ended cleanup          │  │
│  │  ├─ reconcileFromProxy()        correct stale counts          │  │
│  │  └─ getPoolStatus()             dashboard display             │  │
│  └──────────────────────────┬────────────────────────────────────┘  │
│                             │                                       │
│  ┌──────────────────────────▼────────────────────────────────────┐  │
│  │  Database (PostgreSQL)                                        │  │
│  │  ├─ playlists              (profiles_enabled flag)            │  │
│  │  ├─ playlist_profiles      (credentials, max_streams, etc.)   │  │
│  │  └─ channels               (source URL for transformation)    │  │
│  └───────────────────────────────────────────────────────────────┘  │
│                                                                     │
│  ┌───────────────────────────────────────────────────────────────┐  │
│  │  Redis (m3u-editor DB 0-5)                                    │  │
│  │  ├─ playlist_profile:{id}:connections  (active count)         │  │
│  │  ├─ playlist_profile:{id}:streams      (set of stream IDs)    │  │
│  │  ├─ stream:{id}:profile_id             (stream→profile)       │  │
│  │  ├─ channel_stream:{ch}:{uuid}         (channel→stream)       │  │
│  │  └─ stream:{id}:channel                (stream→channel)       │  │
│  └───────────────────────────────────────────────────────────────┘  │
└──────────────────────────────┬──────────────────────────────────────┘
                               │ HTTP API calls (stream creation/query)
                               │ ◄──── webhooks (stream_stopped events)
                               ▼
┌─────────────────────────────────────────────────────────────────────┐
│                         M3U-PROXY                                   │
│                                                                     │
│  ┌───────────────────────────────────────────────────────────────┐  │
│  │  Stream Manager                                               │  │
│  │  ├─ /streams           (create/list streams)                  │  │
│  │  ├─ /streams/by-metadata  (pool lookup query)                 │  │
│  │  └─ /streams/{id}      (stream details)                       │  │
│  └──────────────────────────┬────────────────────────────────────┘  │
│                             │                                       │
│  ┌──────────────────────────▼────────────────────────────────────┐  │
│  │  FFmpeg Process Pool                                          │  │
│  │  Each transcoded stream:                                      │  │
│  │  ├─ One FFmpeg process per active channel                     │  │
│  │  ├─ Multiple clients receive the same output                  │  │
│  │  └─ 10-second grace period after last client disconnects      │  │
│  └──────────────────────────┬────────────────────────────────────┘  │
│                             │                                       │
│  ┌──────────────────────────▼────────────────────────────────────┐  │
│  │  Redis (m3u-proxy DB 6)                                       │  │
│  │  Stream metadata including:                                   │  │
│  │  ├─ provider_profile_id  (which account serves this stream)   │  │
│  │  ├─ original_channel_id  (requested channel, for pool match)  │  │
│  │  └─ original_playlist_uuid                                    │  │
│  └───────────────────────────────────────────────────────────────┘  │
└──────────────────────────────┬──────────────────────────────────────┘
                               │  pull stream from provider
                               ▼
┌─────────────────────────────────────────────────────────────────────┐
│                    IPTV PROVIDER(S)                                  │
│   Account 1 (Profile 0)   Account 2 (Profile 1)   Account 3 ...     │
└─────────────────────────────────────────────────────────────────────┘
```

---

## Data Flow Diagrams

### New Stream Request (No Existing Pool)

```
Client Request
     │
     ▼
M3uProxyApiController.channel()
     │
     ▼
M3uProxyService.getChannelUrl()
     │
     ├─[1]─► Check Redis channel→stream cache
     │            └─ MISS: continue
     │
     ├─[2]─► findExistingPooledStream()
     │        └─ Query proxy /streams/by-metadata
     │            └─ MISS: continue
     │
     ├─[3]─► ProfileService.selectAndReserveProfile()
     │        ├─ Acquire per-playlist Redis lock
     │        ├─ Check channel not already reserved
     │        ├─ Iterate profiles in priority order
     │        ├─ Find first profile with available capacity
     │        ├─ Atomically increment connection count
     │        ├─ Write reservation key (30s TTL)
     │        └─ Release lock
     │
     ├─[4]─► Create transcoded stream in m3u-proxy
     │        └─ POST /streams with metadata incl. provider_profile_id
     │
     ├─[5]─► ProfileService.finalizeReservation()
     │        ├─ Replace reservation ID with real stream ID
     │        └─ Update channel→stream key (24h TTL)
     │
     └─[6]─► Return stream URL to client
```

### Pool Reuse (Existing Stream)

```
Client Request
     │
     ▼
M3uProxyService.getChannelUrl()
     │
     ├─[1]─► Check Redis channel→stream cache
     │            └─ HIT: return existing stream URL immediately ✅
     │                 (fast path — no proxy API call)
     │
     ├─[2]─► findExistingPooledStream()  (if cache missed)
     │        └─ Query proxy /streams/by-metadata
     │            └─ HIT: return existing stream URL ✅
     │                 (bypasses capacity check entirely)
     │
     └─ Skip profile selection and stream creation
```

### Stream Ended Flow

```
Last client disconnects from m3u-proxy
     │
     ▼
m3u-proxy 10s grace period
     │
     ▼ (no new clients)
m3u-proxy stops FFmpeg process
     │
     ▼
m3u-proxy sends POST /api/m3u-proxy/webhook
  { "event_type": "stream_stopped", "stream_id": "...", "data": { "metadata": { "provider_profile_id": "..." } } }
     │
     ▼
M3uProxyApiController.handleWebhook()
     │
     ▼
ProfileService.decrementConnections()
     ├─ Lua script: atomic decrement-if-positive
     ├─ Delete stream→profile mapping
     ├─ Delete stream from profile's stream set
     ├─ Delete stream→channel reverse mapping
     └─ Delete channel→stream key (only if still pointing to this stream)
```

---

## Redis Key Architecture

Redis is the single source of truth for live connection state. The database (`playlist_profiles.max_streams`) stores configuration; Redis stores the real-time counts.

```
┌────────────────────────────────────────────────────────────────────┐
│ KEY TYPE               │ FORMAT                          │ TTL     │
├────────────────────────┼─────────────────────────────────┼─────────┤
│ Connection count       │ playlist_profile:{id}:connections│ 24h    │
│ Profile stream set     │ playlist_profile:{id}:streams    │ 24h    │
│ Stream→profile mapping │ stream:{stream_id}:profile_id    │ 24h    │
│ Channel→stream mapping │ channel_stream:{ch_id}:{pl_uuid} │ 24h    │
│ Stream→channel mapping │ stream:{stream_id}:channel       │ 24h    │
│ Pending reservation    │ channel_stream:{ch_id}:{pl_uuid} │ 30s    │
│ Reservation reverse    │ stream:reservation:{id}:channel  │ 30s    │
│ Profile select lock    │ profile_select_lock:playlist:{id}│ 2s     │
└────────────────────────┴─────────────────────────────────┴─────────┘
```

**Key relationships:**

```
playlist_profile:1:connections   ←── integer count: how many streams profile 1 is serving
playlist_profile:1:streams       ←── set: { "stream-abc", "stream-xyz" }
stream:stream-abc:profile_id     ←── "1"  (profile 1 serves stream-abc)
stream:stream-abc:channel        ←── "456:playlist-uuid-here"
channel_stream:456:playlist-uuid ←── "stream-abc"  (channel 456 → stream-abc)
```

**Reservation lifecycle (TOCTOU prevention):**

```
Before stream created:
  channel_stream:456:uuid  = "reservation:a1b2c3"  [TTL=30s]
  stream:reservation:a1b2c3:channel = "456:uuid"   [TTL=30s]

After stream created (finalizeReservation):
  channel_stream:456:uuid  = "stream-real-id-here" [TTL=24h]
  stream:stream-real-id-here:channel = "456:uuid"  [TTL=24h]
```

---

## Concurrency & Locking Model

### The TOCTOU Problem

Without locking, two simultaneous requests can both see "1 slot available" and both allocate it, causing over-provisioning:

```
Request A: reads count=4, max=5 → capacity available
Request B: reads count=4, max=5 → capacity available
Request A: increments → count=5
Request B: increments → count=6  ← OVER LIMIT ❌
```

### The Solution: Per-Playlist Redis Lock

```
Request A: acquires lock
Request A: reads count=4, allocates slot → count=5
Request A: releases lock

Request B: acquires lock (after A releases)
Request B: reads count=5, max=5 → NO CAPACITY
Request B: releases lock → returns 503 (or waits for reconcile)
```

The lock key is `profile_select_lock:playlist:{id}` with a 2-second timeout. Both selection and increment happen **inside** the lock to ensure atomicity.

### Channel Reuse Detection (Inside the Lock)

To handle the window between "reservation created" and "stream visible in proxy", the lock check also looks for a pending channel reservation:

```
Request A: acquires lock → allocates slot → sets channel_stream key → releases lock
Request B: acquires lock → sees channel_stream key → skips allocation → releases lock
Request B: waits → calls findExistingPooledStream() → joins existing stream
```

### Decrement Safety (Lua Script)

Decrements use a Lua script to prevent the count from going negative (e.g. if a duplicate `stream_stopped` webhook fires):

```lua
local current = tonumber(redis.call('get', KEYS[1]) or 0)
if current > 0 then
    return redis.call('decr', KEYS[1])
end
return -1  -- signals "already at zero"
```

---

## Webhook Event System

Connection counts are maintained via webhook events from m3u-proxy:

```
m3u-proxy Event     │ m3u-editor Action
────────────────────┼──────────────────────────────────────────────
stream_started      │ Cache invalidation only (count already set at reservation)
client_connected    │ Cache invalidation only
client_disconnected │ Cache invalidation only
stream_stopped      │ ProfileService::decrementConnections()
                    │   → Redis count decrement
                    │   → Redis key cleanup
```

**Why only `stream_stopped` triggers a decrement:**
- The connection is allocated at reservation time (before stream_started)
- The count must decrease exactly once per stream lifecycle
- Using `stream_stopped` (rather than client_disconnected) ensures this regardless of how many clients connected/disconnected

**Critical dependency:** If the `stream_stopped` webhook does not reach m3u-editor, the Redis count will never decrease. This is the most common source of "stuck" connection counts. See [Troubleshooting Guide](pooled-providers-troubleshooting.md#connections-never-decrement--counts-keep-climbing).

---

## Stream Pooling Subsystem

Stream pooling is a complementary feature that works alongside provider profiles:

```
Without Pooling:
  User 1 → proxy → FFmpeg #1 → Provider (1 connection)
  User 2 → proxy → FFmpeg #2 → Provider (2 connections)  ← may hit limit

With Pooling:
  User 1 → proxy → FFmpeg #1 → Provider (1 connection)
  User 2 → proxy ──────────┘               ← shares FFmpeg #1
  User 3 → proxy ──────────┘               ← shares FFmpeg #1
```

### Pool Match Criteria

For a new client to join an existing pool, **all** of the following must match:

| Criterion | Redis/Proxy field |
|---|---|
| Same original channel | `original_channel_id` in stream metadata |
| Same original playlist | `original_playlist_uuid` in stream metadata |
| Same transcoding profile | `profile_id` in stream metadata |
| Stream still active | `is_active: true` from proxy |
| Transcoding enabled | `transcoding: "true"` in stream metadata |

**Provider profile ID is intentionally NOT a match criterion.** Multiple users can share a stream even if they would theoretically be assigned to different provider profiles — the stream is already running and consuming only one provider connection, so there is no need to route them to a "matching" profile.

### Pooling Lookup Flow

```
1. Fast path: Check Redis channel→stream key
   └─ If present and not a reservation → return stream URL immediately

2. Slow path: Query proxy /streams/by-metadata?field=id&value={channel_id}
   └─ Inspect each result for matching criteria
   └─ If match found → return stream URL (bypasses capacity check)

3. Miss: Proceed to profile selection and stream creation
```

---

## Where Symptoms Manifest

This table maps common symptoms to the component most likely responsible:

| Symptom | Most Likely Location | What to Check |
|---------|---------------------|---------------|
| HTTP 503 "all profiles at max" | Redis connection counts | Compare Redis count to actual proxy streams; run `reconcileFromProxy()` |
| Connection count never decreasing | Webhook delivery | Check proxy logs for webhook send errors; check m3u-editor can be reached from proxy |
| Multiple FFmpeg processes for same channel | Stream pooling miss | Verify transcoding is enabled; check pool lookup logs in m3u-editor |
| Profile shows 1 max stream even with provider limit set higher | Provider info not fetched | Click **Test** on the profile to fetch live data |
| Streams work but wrong account credentials are used | URL transformation | Check channel URL matches standard Xtream format; check profile URL is fully qualified |
| High latency on stream start | Proxy API query | Check network between editor and proxy; check Redis is reachable |
| "Failed to acquire profile selection lock" | Redis lock / Redis health | Check Redis latency; check for Redis memory pressure |
| Pool status widget shows 0 connections but streams are active | Webhook not configured | Proxy's stream_stopped events aren't reaching editor |
| Profiles randomly cycling instead of filling in order | Priority misconfiguration | Verify profiles are ordered correctly by priority field (0=first) |
| Streams work but Custom Playlist channels ignore profiles | Source playlist identification | Ensure the source playlist (not Custom Playlist) has profiles_enabled |

### Typical Failure Paths

**Path A — No webhook connectivity:**
```
Stream created → connection count incremented (Redis)
Stream ends   → no webhook received
               → Redis count stays high
               → next request sees "no capacity"
               → 503 error ← user-visible failure
```

**Path B — Redis unavailable:**
```
Stream request arrives
  → channel cache check fails (Redis error)
  → fallback: query proxy API for pool
  → pool not found
  → profile selection: getConnectionCount() returns 0 (Redis error)
  → system always sees "capacity available"
  → all profiles get allocated
  → provider starts rejecting connections
  → streams fail at provider level ← user-visible failure
```

**Path C — Race condition (rapid channel switching):**
```
User switches channel
  → new stream created, count incremented
  → old stream stops, decrement webhook in flight
  → decrement fires before webhook arrives
  → count may transiently exceed limit
  → next request triggers reconcileFromProxy()
  → self-corrects ← usually invisible to user
```

**Path D — Provider info not fetched:**
```
Profile added, not tested
  → max_streams = null → effective_max_streams defaults to 1
  → only 1 stream allowed per profile
  → 503 on 2nd viewer ← user-visible failure
  Fix: click Test or run RefreshPlaylistProfiles job
```

---

## Related Documentation

- [Provider Profiles User Guide](pooled-providers.md)
- [Pooled Providers Troubleshooting](pooled-providers-troubleshooting.md)
- [Stream Pooling Technical Details](stream-pooling.md)
- [M3U Proxy Integration Guide](m3u-proxy-integration.md)
