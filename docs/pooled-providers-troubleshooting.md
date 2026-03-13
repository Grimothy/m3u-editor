# Pooled Providers Troubleshooting Guide

This guide covers common problems encountered when using Provider Profiles (pooled providers) with m3u-proxy.

---

## Table of Contents

1. [Quick Diagnostics Checklist](#quick-diagnostics-checklist)
2. [Setup & Configuration Issues](#setup--configuration-issues)
3. [Connection Limit Issues](#connection-limit-issues)
4. [Stream Pooling Issues](#stream-pooling-issues)
5. [Redis / Connection Count Issues](#redis--connection-count-issues)
6. [Proxy Communication Issues](#proxy-communication-issues)
7. [Credential & URL Issues](#credential--url-issues)
8. [Performance Issues](#performance-issues)
9. [Diagnostic Commands](#diagnostic-commands)
10. [Log Reference](#log-reference)

---

## Quick Diagnostics Checklist

Before diving into specific issues, work through this checklist:

- [ ] Is the m3u-proxy container running? (`docker ps | grep m3u-proxy`)
- [ ] Is the proxy reachable from m3u-editor? (`php artisan m3u-proxy:status`)
- [ ] Do `M3U_PROXY_TOKEN` and `API_TOKEN` match between editor and proxy?
- [ ] Is Redis running and reachable from both editor and proxy?
- [ ] Is `ENABLE_REDIS_POOLING=true` set in the proxy container?
- [ ] Has the webhook URL been configured so the proxy can POST back to the editor?
- [ ] Have all profiles been tested (click **Test** button) to detect max connections?
- [ ] Is the playlist type set to **Xtream API** (not plain M3U)?

---

## Setup & Configuration Issues

### Provider Profiles section not visible

**Symptom:** The "Provider Profiles" section does not appear in the playlist edit form.

**Cause:** Provider Profiles only works with **Xtream API** type playlists.

**Fix:**
1. Edit your playlist
2. Check the playlist type — it must be **Xtream API**
3. Plain M3U playlists (`.m3u` / `.m3u8` files) do not support profiles

---

### "Provider Profiles require proxy to be enabled"

**Symptom:** Validation error when trying to enable profiles; proxy toggle becomes forced on.

**Cause:** The m3u-proxy is not configured or the token is missing.

**Fix:**
1. Ensure `M3U_PROXY_HOST` points to a reachable proxy instance
2. Ensure `M3U_PROXY_TOKEN` is set and non-empty
3. The toggle for "Enable Proxy" on the playlist will automatically be enabled — leave it on
4. Run `php artisan m3u-proxy:status` to verify connectivity

---

### Primary profile shows Max Streams = 1

**Symptom:** After enabling profiles, the primary profile shows a max of 1 connection even though the provider allows more.

**Cause:** Provider info has not been fetched yet, so the system defaults to 1 as a safe fallback.

**Fix:**
1. Click **Test** next to the primary profile — this fetches current provider limits
2. Or wait for the background `RefreshPlaylistProfiles` job to run
3. Set **Max Streams** manually if the auto-detect continues to fail

---

### Additional profiles not saving

**Symptom:** New profiles added in the repeater disappear after saving.

**Cause:** Validation errors (missing required fields) can silently discard new entries.

**Fix:**
1. Ensure **Username** and **Password** are filled for each profile
2. Click the **Test** button on each new profile before saving — this confirms valid credentials
3. Check browser console for Livewire errors

---

## Connection Limit Issues

### HTTP 503 — "All provider profiles have reached their maximum stream limit"

**Symptom:** Clients receive a 503 error when trying to connect.

**Cause:** All enabled profiles are at full capacity according to their Redis connection counts.

**Diagnosis:**
1. Check the Pool Status widget on the playlist — are counts unexpectedly high?
2. Check active streams in m3u-proxy:
   ```bash
   curl -H "X-API-Token: your-token" http://your-proxy:8085/m3u-proxy/streams
   ```
3. Compare proxy active stream count vs Redis count (see [Stale Redis counts](#stale-redis-counts-connections-show-as-used-but-streams-have-ended) below)

**Fix — Genuine capacity:**
- Wait for active streams to end
- Add more profiles (more IPTV accounts)
- Increase Max Streams on existing profiles if your provider allows it

**Fix — Stale counts:**
- Use the reconcile command or restart the proxy (see [Reconciling stale counts](#reconciling-stale-redis-counts))

---

### Connections never decrement — counts keep climbing

**Symptom:** Pool status shows more active connections than actual viewers. Count only goes up, never down.

**Root cause:** The `stream_stopped` webhook from m3u-proxy is not reaching m3u-editor.

**Why it matters:** The system decrements Redis connection counts only when it receives the `stream_stopped` webhook. If the proxy can't reach the editor's webhook endpoint, counts accumulate indefinitely.

**Diagnosis:**
```bash
# Check proxy logs for webhook delivery attempts
docker logs m3u-proxy | grep -i webhook

# Check m3u-editor logs for received webhooks
docker exec -it m3u-editor tail -100 /var/www/html/storage/logs/laravel.log | grep webhook
```

**Fix:**
1. Ensure the proxy is configured with the correct editor webhook URL
2. Test network connectivity between proxy and editor containers:
   ```bash
   docker exec -it m3u-proxy curl http://m3u-editor:36400/api/m3u-proxy/webhook
   ```
3. Check that Docker containers are on the same network (`docker network inspect m3u-network`)
4. After fixing webhooks, reconcile the current counts (see below)

---

### Stale Redis counts — connections show as used but streams have ended

**Symptom:** Pool status shows connections in use, but no one is actually watching.

**Causes:**
- Proxy restarted without sending `stream_stopped` events
- m3u-editor restarted while streams were active
- Network partition between proxy and editor during stream cleanup
- Rapidly switching channels before the decrement webhook fires (race condition — usually self-corrects)

**Fix — Manual reconcile via proxy comparison:**

The system automatically reconciles before returning a 503 (calls `reconcileFromProxy()`). If this doesn't help, force a reconcile:

```bash
# Check proxy active streams and compare to Redis
docker exec -it m3u-editor php artisan tinker

# In tinker:
$playlist = App\Models\Playlist::find(YOUR_PLAYLIST_ID);
App\Services\ProfileService::reconcileFromProxy($playlist);
```

**Fix — Manual Redis reset (last resort):**

```bash
docker exec -it m3u-editor php artisan tinker

# In tinker — reset ALL tracking for a specific profile:
$profile = App\Models\PlaylistProfile::find(YOUR_PROFILE_ID);
App\Services\ProfileService::resetConnectionTracking($profile);
```

> ⚠️ Use `resetConnectionTracking()` only when you are certain no streams are actually active for that profile.

---

### Connections count as "2" for a single viewer

**Symptom:** Each stream appears to consume 2 connection slots.

**Cause:** This can happen if the stream creation failed after the slot was reserved, and both the reservation and the real stream were counted.

**Fix:**
- This is usually self-correcting within 30 seconds (reservation has a 30s TTL)
- If persistent, check m3u-editor logs for `stream creation failed` errors, which indicate that `cancelReservation()` may not have been called

---

## Stream Pooling Issues

### Multiple FFmpeg processes for the same channel

**Symptom:** `ps aux | grep ffmpeg` on the proxy server shows multiple FFmpeg processes all transcoding the same channel.

**Cause:** Stream pooling is not working — each client is creating a new stream instead of reusing the existing one.

**Requirements for pooling to work:**
1. Transcoding must be enabled (a StreamProfile must be assigned to the playlist)
2. The same channel ID, playlist UUID, and StreamProfile ID must match
3. The existing stream must still be active (has at least one connected client)

**Diagnosis:**
```bash
# Query for pooled streams matching a specific channel ID
curl -H "X-API-Token: your-token" \
  "http://your-proxy:8085/m3u-proxy/streams/by-metadata?field=id&value=12345&active_only=true"
```

**Check m3u-editor logs:**
```
# These messages indicate pool reuse is working:
"Reusing existing transcoded stream via channel cache (fast path)"
"Reusing existing pooled transcoded stream (bypassing capacity check)"

# This message indicates a new stream is being created:
"Creating transcoded stream with failover tracking"
```

**Common fixes:**
- Verify transcoding is enabled on the playlist (a StreamProfile is assigned)
- Ensure Redis is reachable from m3u-editor (pooling requires Redis)
- If using Custom Playlists, ensure the source playlist has profiles enabled

---

### Pool reuse not working across provider failovers

**Symptom:** When Channel A from Provider 1 fails over to Channel B from Provider 2, subsequent clients don't join the existing transcoded stream.

**Cause:** The pool matching checks `original_channel_id` and `original_playlist_uuid` (the originally requested channel), not the actual failover channel. If these aren't stored in stream metadata, matching fails.

**Diagnosis:** Check stream metadata via the proxy API:
```bash
curl -H "X-API-Token: your-token" \
  "http://your-proxy:8085/m3u-proxy/streams/by-metadata?field=id&value=CHANNEL_ID&active_only=true"
```

Look for `original_channel_id` and `original_playlist_uuid` in the metadata. These should match what new clients are requesting.

---

### Transcoding streams not shared — "Playlist has reached its maximum stream limit"

**Symptom:** Multiple clients watching the same channel each trigger a new stream creation, and the playlist limit is hit.

**Cause:** Pooling only works with **transcoded** streams. Direct streams (no StreamProfile) cannot be shared.

**Fix:**
- Assign a StreamProfile to the playlist to enable transcoding
- If you intentionally don't want transcoding, increase the playlist stream limit

---

## Redis / Connection Count Issues

### Redis connection errors in logs

**Symptom:** Log entries like `"Failed to get connection count for profile"` or `"Failed to increment connections"`.

**Cause:** m3u-editor cannot reach Redis.

**Diagnosis:**
```bash
# Test Redis from inside m3u-editor container
docker exec -it m3u-editor redis-cli -h ${REDIS_HOST:-localhost} -p ${REDIS_PORT:-6379} ping

# Check Redis container is running
docker ps | grep redis
```

**Fix:**
1. Ensure Redis container is running: `docker-compose up -d redis`
2. Check `REDIS_HOST` environment variable points to the correct hostname
3. Ensure both m3u-editor and Redis are on the same Docker network

---

### Profile selection lock times out

**Symptom:** Log warning: `"Failed to acquire profile selection lock"` and streams fail silently.

**Cause:** The per-playlist Redis lock (2 second timeout) could not be acquired — either Redis is slow/unavailable or another request is holding the lock longer than expected.

**Fix:**
1. Check Redis latency: `redis-cli -h localhost ping` should respond in < 1ms
2. Check for Redis memory pressure: `redis-cli INFO memory`
3. If Redis is overloaded, increase `--maxmemory` or upgrade the Redis instance

---

### Reconciling stale Redis counts

Use this procedure when Redis counts are out of sync with actual active streams:

```bash
docker exec -it m3u-editor php artisan tinker
```

```php
// Compare Redis counts to proxy active streams
$playlist = App\Models\Playlist::where('name', 'like', '%YourPlaylistName%')->first();

// Get current pool status (from Redis)
$status = App\Services\ProfileService::getPoolStatus($playlist);
print_r($status);

// Reconcile against proxy (corrects Redis counts to match proxy active streams)
App\Services\ProfileService::reconcileFromProxy($playlist);

// Check again after reconcile
$status = App\Services\ProfileService::getPoolStatus($playlist);
print_r($status);
```

---

## Proxy Communication Issues

### Proxy not reachable from m3u-editor

**Symptom:** `php artisan m3u-proxy:status` shows error, or stream requests fail with connection errors.

**Diagnosis:**
```bash
# Check proxy container is running
docker ps | grep m3u-proxy

# Test from inside m3u-editor
docker exec -it m3u-editor curl http://m3u-proxy:8085/health

# Check network
docker network inspect m3u-network
```

**Fix:**
1. Ensure `M3U_PROXY_HOST` matches the container hostname (e.g. `m3u-proxy`)
2. Ensure `M3U_PROXY_PORT` matches the proxy's `PORT` setting
3. Verify both containers are on the same Docker network

---

### Authentication errors (401/403) from proxy

**Symptom:** Proxy API calls return 401 or 403 errors.

**Fix:**
```bash
# Verify tokens match
docker exec -it m3u-editor env | grep M3U_PROXY_TOKEN
docker exec -it m3u-proxy env | grep API_TOKEN

# They must be identical
```

If they don't match, update the environment variable and restart the affected container.

---

### Webhooks not being received by m3u-editor

**Symptom:** Connections never decrement; proxy logs show webhook delivery failures.

**Cause:** The proxy cannot reach m3u-editor's webhook endpoint at `POST /api/m3u-proxy/webhook`.

**Diagnosis:**
```bash
# Test from proxy to editor
docker exec -it m3u-proxy curl -X POST \
  -H "Content-Type: application/json" \
  -d '{"event_type":"test"}' \
  http://m3u-editor:36400/api/m3u-proxy/webhook

# Check for firewall or routing issues
docker exec -it m3u-proxy ping m3u-editor
```

**Fix:**
1. Confirm both containers are on the same Docker network
2. Confirm the editor's `APP_URL` is reachable from within the proxy container
3. Check that the webhook route is not blocked by any middleware

---

## Credential & URL Issues

### Profile test fails — "Invalid credentials or provider unavailable"

**Symptom:** Clicking **Test** on a profile returns an error.

**Diagnosis:**
- Try the credentials manually in a browser: `http://PROVIDER_URL/player_api.php?username=USER&password=PASS`
- Check if the provider's API endpoint is responsive

**Common causes:**
- Typo in username, password, or URL
- Account expired or suspended
- Provider is temporarily down
- Using a different URL format than expected (must be Xtream API compatible)

---

### URL transformation not working — streams authenticate with wrong credentials

**Symptom:** Streams from secondary profiles fail with authentication errors, even though the profile test passes.

**Cause:** The URL transformation regex requires the original channel URL to follow standard Xtream format:
```
http://domain:port/(live|series|movie)/username/password/stream_id
```

If the channel URL doesn't match this pattern, the transformation silently falls back to the original URL (wrong credentials).

**Diagnosis:**
Check the channel URL stored in the database:
```bash
docker exec -it m3u-editor php artisan tinker
```
```php
$channel = App\Models\Channel::find(CHANNEL_ID);
echo $channel->url;
```

If the URL structure is non-standard (e.g. different path format), the regex pattern won't match.

**Fix:**
- Ensure the playlist was imported from a standard Xtream API provider
- Non-standard URL structures from M3U imports may not support URL transformation

---

### Profile with custom URL ignores the URL and uses playlist URL instead

**Cause:** The URL stored in the profile must include the full base URL including protocol and port. A URL like `provider.com` (without `http://`) may fail to match.

**Fix:** Ensure the profile URL is fully qualified: `http://provider.com:8080`

---

## Performance Issues

### High latency on first stream request with profiles enabled

**Symptom:** The first stream request takes several seconds when profiles are enabled.

**Cause:** The system queries m3u-proxy's `/streams/by-metadata` endpoint on every new stream request when no channel cache entry exists.

**Expected behavior:** Subsequent requests for the same channel are served from the Redis channel-stream cache with no HTTP call to the proxy (fast path).

**Mitigation:**
- Ensure Redis is running — without Redis, the cache path is bypassed
- Ensure low network latency between m3u-editor and m3u-proxy (same Docker host is ideal)

---

### Profile selection taking more than 2 seconds

**Symptom:** Log warning: `"Failed to acquire profile selection lock"` causing stream failures.

**Cause:** Profile selection uses a per-playlist Redis lock with a 2-second timeout. If Redis is slow or another process holds the lock, new requests may time out.

**Fix:**
- Check Redis health: `redis-cli INFO stats | grep blocked_clients`
- Ensure Redis has adequate memory headroom
- Consider dedicated Redis instance for high-load deployments

---

### Provider info not updating — pool status shows stale data

**Symptom:** Max streams field shows old values; provider expiry dates are out of date.

**Cause:** Provider info is fetched by the `RefreshPlaylistProfiles` background job. If the queue is not processing, this data won't update.

**Fix:**
```bash
# Check queue worker status
docker exec -it m3u-editor supervisorctl status queue-worker

# Manually trigger a refresh
docker exec -it m3u-editor php artisan tinker
```
```php
$playlist = App\Models\Playlist::find(YOUR_PLAYLIST_ID);
App\Services\ProfileService::refreshAllProfiles($playlist);
```

---

## Diagnostic Commands

### Check proxy status and connectivity

```bash
docker exec -it m3u-editor php artisan m3u-proxy:status
```

### View active streams

```bash
# List all active streams
curl -H "X-API-Token: your-token" http://your-proxy:8085/m3u-proxy/streams

# List streams for a specific channel
curl -H "X-API-Token: your-token" \
  "http://your-proxy:8085/m3u-proxy/streams/by-metadata?field=id&value=12345&active_only=true"
```

### Check Redis pool state

```bash
docker exec -it m3u-editor php artisan tinker
```
```php
$playlist = App\Models\Playlist::find(ID);
print_r(App\Services\ProfileService::getPoolStatus($playlist));
```

### Reset a profile's connection count

```bash
docker exec -it m3u-editor php artisan tinker
```
```php
$profile = App\Models\PlaylistProfile::find(PROFILE_ID);

// Only if no streams are actually active for this profile
App\Services\ProfileService::resetConnectionTracking($profile);
```

### Reconcile all profiles against proxy state

```bash
docker exec -it m3u-editor php artisan tinker
```
```php
$playlist = App\Models\Playlist::find(ID);
App\Services\ProfileService::reconcileFromProxy($playlist);
```

### Check Redis keys for a profile

```bash
docker exec -it m3u-proxy-redis redis-cli

# Check connection count
GET playlist_profile:PROFILE_ID:connections

# List active stream IDs for a profile
SMEMBERS playlist_profile:PROFILE_ID:streams

# Check which profile a stream is using
GET stream:STREAM_ID:profile_id

# Check channel→stream mapping
GET channel_stream:CHANNEL_ID:PLAYLIST_UUID
```

### Inspect proxy logs

```bash
# External proxy
docker logs m3u-proxy -f --tail 200

# Embedded proxy
docker exec -it m3u-editor tail -200 /var/www/html/storage/logs/m3u-proxy.log
```

### Inspect m3u-editor application logs

```bash
docker exec -it m3u-editor tail -200 /var/www/html/storage/logs/laravel.log

# Filter for profile-related log entries
docker exec -it m3u-editor grep -i "profile\|pool\|provider" \
  /var/www/html/storage/logs/laravel.log | tail -100
```

---

## Log Reference

### m3u-editor Logs

| Message | Level | Meaning |
|---------|-------|---------|
| `Reusing existing transcoded stream via channel cache (fast path)` | DEBUG | Pool reuse via Redis cache — no proxy query needed |
| `Reusing existing pooled transcoded stream (bypassing capacity check)` | DEBUG | Pool reuse found via proxy metadata query |
| `Selected profile for streaming` | INFO | A provider profile was selected for a new stream |
| `No profiles with capacity available for playlist` | WARNING | All profiles are at capacity |
| `Failed to acquire profile selection lock` | WARNING | Redis lock timeout — Redis may be slow |
| `Channel reuse detected inside lock — skipping profile allocation` | DEBUG | Concurrent request for same channel; reuse path |
| `Incremented connections for profile` | INFO | New stream allocated to a profile |
| `Decremented connections for profile` | INFO | Stream ended; connection freed |
| `Attempted to decrement connections ... but count was already 0` | WARNING | Decrement called on empty counter — may indicate duplicate webhook |
| `Quick reconciled profile connection count` | DEBUG | `reconcileFromProxy()` corrected a drift |
| `Received m3u-proxy webhook` | INFO | Webhook received from proxy (event_type shows which event) |
| `Decremented profile connections via webhook` | DEBUG | Webhook-triggered decrement succeeded |
| `Error handling profile connection tracking` | ERROR | Webhook handler exception — check profile ID |

### m3u-proxy Logs

| Message | Level | Meaning |
|---------|-------|---------|
| `Client XYZ registered to transcoded stream ABC` | INFO | Client joined a pooled stream |
| `Transcoded stream ABC now has N connected clients` | INFO | Pool size increased |
| `Last client disconnected from stream ABC, starting 10s grace period` | INFO | Stream will stop in 10s if no new clients join |
| `Grace period elapsed for stream ABC, stopping FFmpeg process` | INFO | Stream cleanup triggered |
| `Sending stream_stopped webhook` | INFO/DEBUG | Proxy notifying editor to decrement connections |
| `Webhook delivery failed` | ERROR | Proxy cannot reach editor — check network config |

---

## Related Documentation

- [Provider Profiles User Guide](pooled-providers.md)
- [Stream Pooling Technical Details](stream-pooling.md)
- [M3U Proxy Integration Guide](m3u-proxy-integration.md)
- [Pooled Providers Architecture](pooled-providers-architecture.md)
