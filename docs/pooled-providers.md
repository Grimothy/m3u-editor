# Provider Profiles: User Guide

Pool multiple IPTV accounts from the same provider to multiply your available connections and support more simultaneous viewers.

**Important**: Provider Profiles is designed for **pooling multiple accounts from the same IPTV provider**. You can use different servers from that provider, but mixing completely different providers may cause issues.

## Table of Contents

1. [Requirements](#requirements)
2. [Overview](#overview)
3. [Who Should Use Provider Profiles?](#who-should-use-provider-profiles)
4. [How It Works](#how-it-works)
5. [Setting Up Provider Profiles](#setting-up-provider-profiles)
6. [Using Multiple Server URLs](#using-multiple-server-urls)
7. [Managing Your Profiles](#managing-your-profiles)
8. [Understanding Pool Status](#understanding-pool-status)
9. [Connection Management](#connection-management)
10. [Configuration & Settings](#configuration--settings)
11. [Best Practices](#best-practices)
12. [Future Enhancements](#future-enhancements)
13. [Frequently Asked Questions](#frequently-asked-questions)

---

## Requirements

Before enabling Provider Profiles, ensure:

- ✅ **Proxy mode is enabled** - Required for accurate connection tracking
- ✅ **M3U_PROXY_HOST and M3U_PROXY_TOKEN are configured** - Provider Profiles require the m3u-proxy service
- ✅ **Playlist is Xtream API type** - Profiles only work with Xtream playlists, not plain M3U files
- ✅ **Multiple accounts from the same provider** - You need additional IPTV accounts to pool

**Why Proxy is Required:**
- Tracks active connections in real-time via Redis
- Enables stream pooling (multiple viewers sharing one connection)
- Manages automatic profile selection based on capacity
- Handles credential transformation for different accounts

---

## Overview

**Provider Profiles** allow users to pool multiple Xtream API accounts within a single playlist. This solves the common problem of connection limits by distributing load across multiple accounts.

### Problem Being Solved

Many IPTV providers limit concurrent connections:
- "1 connection per account" or "5 connections per account"
- A household with multiple users/devices quickly hits the limit
- Each user connection consumes a provider connection

**Solution**: Pool multiple accounts to multiply available connections.

### Example Scenario

**Without Profiles:**
```
Account 1: max 2 connections
User watching TV: 1 connection
User on phone: 1 connection
User on tablet: BLOCKED ❌ (limit reached)
```

**With Profiles (2 accounts):**
```
Account 1: max 2 connections → User TV: 1
Account 2: max 2 connections → User phone: 1, User tablet: 1
Total: 4 users can watch simultaneously ✅
```

---

## Who Should Use Provider Profiles?

### You Need Profiles If:

- You're hitting connection limits ("max connections reached" errors)
- Multiple family members/devices watch simultaneously
- You have multiple accounts from the same provider
- You want redundancy with backup accounts/servers

### You DON'T Need Profiles If:

- You rarely have more than 1-2 concurrent viewers
- Your provider's connection limit is sufficient
- You only have one IPTV account
- You're satisfied with current performance

### Quick Example

**Family Scenario:**
```
Without Profiles (1 account, max 2 connections):
✓ Dad watching TV (1 connection)
✓ Mom watching tablet (1 connection)
✗ Kid watching phone - BLOCKED ❌

With Profiles (2 accounts pooled, max 4 connections):
✓ Dad watching TV (uses Account 1)
✓ Mom watching tablet (uses Account 1)
✓ Kid watching phone (uses Account 2) ✅
✓ Room for one more! (uses Account 2)
```

---

## How It Works

### The Basics

Provider Profiles pools multiple IPTV accounts into a single playlist:

1. **Primary Profile** - Automatically created from your playlist's Xtream credentials
2. **Additional Profiles** - Extra accounts you add manually
3. **Automatic Selection** - System picks an account with available capacity
4. **Priority Order** - Profiles tried in order (priority 0 first, then 1, 2, etc.)

### What Each Profile Includes

- **Username & Password** - Xtream account credentials
- **Provider URL** (optional) - Different server from same provider (leave blank to use playlist URL)
- **Max Streams** - Connection limit (auto-detected or manually set)
- **Priority** - Selection order (lower = tried first)
- **Enabled/Disabled** - Toggle to activate/deactivate

### Stream Pooling (Bonus Feature!)

When multiple people watch the **same channel** with transcoding enabled, they can share a single provider connection:

```
5 family members watching the same football game
= Only 1 provider connection used
= All 5 share the same transcoded stream
= Maximum efficiency!
```

This leaves more connections available for watching different channels.

### Full Request Lifecycle

#### 1. User Requests a Channel

```
Client device sends a stream request
    ↓
m3u-editor receives the request
    ↓
Determines the source Playlist and StreamProfile
    ↓
Checks if profiles_enabled on the playlist
```

#### 2. Pool Reuse Check (Fast Path)

Before selecting a new profile, the system checks whether an existing pooled stream can be reused:

```
Check Redis channel→stream cache
    ↓ (cache hit)
Return existing stream URL immediately (no HTTP call to proxy)

    ↓ (cache miss)
Query m3u-proxy /streams/by-metadata for active streams matching:
  - Channel ID
  - Playlist UUID
  - StreamProfile ID
  - Transcoding enabled
    ↓ (match found)
Return existing stream URL (bypasses profile capacity check)
    ↓ (no match)
Continue to profile selection
```

#### 3. Atomic Profile Selection & Reservation

To prevent race conditions (TOCTOU), the system uses a per-playlist Redis lock:

```
Acquire per-playlist lock (max 2 second wait)
    ↓
Check if channel already has a pending reservation
    ↓ (already reserved)
Release lock → wait for proxy to confirm → reuse stream
    ↓ (no reservation)
Iterate profiles in priority order
    ↓
Find first profile where Redis count < max_streams
    ↓
Atomically increment connection count (reservation ID)
Mark channel as "pending" with short 30s TTL
Release lock
    ↓ (no profile found)
Call reconcileFromProxy() to correct stale counts → retry once
    ↓ (still no profile)
Return HTTP 503 "All provider profiles at maximum"
```

#### 4. Stream Creation

```
Send stream creation request to m3u-proxy
  - Includes provider_profile_id in metadata
  - Includes original_channel_id and original_playlist_uuid
    ↓
m3u-proxy returns real stream ID
    ↓
ProfileService::finalizeReservation()
  - Replaces reservation ID with real stream ID in Redis
  - Upgrades channel→stream mapping to real stream ID (24h TTL)
    ↓
Return stream URL to client
```

#### 5. Stream Ends

```
User stops watching
    ↓
m3u-proxy detects no clients (10s grace period)
    ↓
m3u-proxy sends stream_stopped webhook to m3u-editor
    ↓
ProfileService::decrementConnections($profile, $streamId)
    ├─ Atomically decrement count (Lua script prevents going negative)
    ├─ Delete stream→profile mapping from Redis
    ├─ Delete stream→channel reverse mapping
    └─ Clear channel→stream key (only if still pointing to this stream)
    ↓
Next stream request can use that capacity
```

---

## Setting Up Provider Profiles

### Step 1: Enable Provider Profiles

1. Edit your playlist
2. Scroll to "Provider Profiles" section
3. Toggle "Enable Provider Profiles" to **ON**
4. Click **Save**

**Note:** If proxy mode isn't already enabled on your playlist, it will automatically be enabled when you turn on Provider Profiles. This is required for accurate connection tracking.

Your primary account is automatically created as the first profile.

### Step 2: Add Additional Accounts

Click **Add Profile** and fill in:

**Profile Name** (optional)  
Friendly name like "Backup Account" or "US Server"

**Provider URL** (optional)  
- Leave blank = uses same URL as playlist
- Enter URL = uses different server from same provider

**Username** (required)  
Your IPTV account username

**Password** (required)  
Your IPTV account password

**Max Streams** (optional)  
Leave blank to auto-detect, or set a manual limit

**Priority** (default: auto-assigned)  
Lower numbers tried first (0, 1, 2...)

**Enabled** (default: ON)  
Toggle to activate this profile

### Step 3: Test the Profile

**Always test before saving!**

1. Click **Test** button next to the profile
2. System verifies credentials and detects max connections
3. Review the results
4. Click **Save** when ready

---

## Using Multiple Server URLs

### When to Use Different URLs

**Important**: Provider Profiles is designed for **the same provider with multiple accounts**. The `url` field allows different servers/endpoints from that same provider, not different providers entirely.

### Use Cases

1. **Regional Server Failover**: Provider has multiple regional servers
   - Primary: `iptv1.provider.com:80` (Europe)
   - Backup 1: `iptv2.provider.com:80` (Americas)
   - Backup 2: `iptv3.provider.com:80` (Asia)

2. **Different Server Endpoints**: Same provider, different entry points
   - Primary: `provider.com:80` (main)
   - Secondary: `backup.provider.com:80` (failover)
   - Tertiary: `provider-cdn.com:80` (CDN)

3. **Port Variations**: Same provider, different connection methods
   - Primary: `provider.com:80` (HTTP)
   - HTTP/2: `provider.com:8080` (Alt HTTP)
   - HTTPS: `provider.com:443` (Secure)

### When NOT to Use Different URLs

❌ **Don't mix completely different providers**  
Different providers have different URL structures that won't work together.

✅ **Do use same provider's multiple servers**  
Regional mirrors, backup servers, and CDNs from the same provider work perfectly.

### Implementation Details

#### Building Xtream Config

Each profile builds its own config from its URL:

```php
public function getXtreamConfigAttribute(): ?array
{
    // Use profile's URL if set, otherwise use playlist's URL
    $url = $this->url ?? $baseConfig['url'] ?? $baseConfig['server'] ?? null;
    
    return [
        'url' => $url,  // ← This can differ per profile
        'username' => $this->username,
        'password' => $this->password,
        'output' => $baseConfig['output'] ?? 'ts',
    ];
}
```

#### URL Transformation

When streaming a channel, the profile transforms the channel URL by replacing the playlist's primary credentials with the profile's credentials and (optionally) the base server URL:

```
Original URL: http://example.com/live/user1/pass1/channel123.ts

Profile with same server:
→ http://example.com/live/user2/pass2/channel123.ts

Profile with different server:
→ http://backup.com/live/user2/pass2/channel123.ts
```

The transformation uses regex pattern matching and expects the standard Xtream URL format:
```
http://domain:port/(live|series|movie)/username/password/<stream_id>
```

---

## Managing Your Profiles

### Testing Profiles

**Always test after adding!**

1. Click **Test** button
2. System checks credentials and connectivity
3. Auto-detects max connections
4. Updates Max Streams field
5. Shows success/failure notification

### Adjusting Priorities

Control which profiles are tried first:

- **0** = Highest priority (usually primary)
- **1** = Second choice (first backup)
- **2** = Third choice (second backup)

Lower numbers = tried first

### Setting Connection Limits

Override auto-detected limits:

- Leave blank = use provider's limit
- Set number = enforce your own limit

**Why manually limit?**
- Reserve connections for other apps
- Prevent overloading a profile
- Test with reduced capacity

**Note**: If provider info has not yet been fetched (e.g. before the first "Test"), the system defaults to a max of 1 connection. Always use the **Test** button or wait for the background refresh job to populate provider info.

### Enabling/Disabling Profiles

Toggle profiles on/off without deleting:

- Disabled = skipped during selection
- Useful for troubleshooting
- Rotate accounts easily

---

## Understanding Pool Status

The Pool Status widget shows real-time connection usage:

```
Total: 5/15 active | 10 available

✓ ⭐ Primary: 3/5 streams
✓ Backup: 2/5 streams  
✗ Account3: 0/5 streams (Disabled)
```

**Reading the Display:**

- ✓ = Profile enabled
- ✗ = Profile disabled
- ⭐ = Primary profile
- **3/5** = 3 active of 5 maximum
- **Total: 5/15** = 5 in use out of 15 total capacity, 10 available

Connection counts are read directly from Redis, reflecting the current state of active streams tracked by m3u-proxy. The proxy sends `stream_stopped` webhooks that trigger immediate decrements.

---

## Connection Management

### Redis-Based Connection Tracking

Connections are tracked in Redis for real-time accuracy (database queries are slow for high-frequency updates).

#### Redis Keys Structure

```
playlist_profile:{profile_id}:connections
    → Current active connection count for this profile

stream:{stream_id}:profile_id
    → Which provider profile is serving this stream

playlist_profile:{profile_id}:streams
    → Set of stream IDs currently using this profile

channel_stream:{channel_id}:{playlist_uuid}
    → The active (or pending) stream ID for this channel

stream:{stream_id}:channel
    → Reverse mapping: stream ID → channel coordinates (for cleanup)
```

#### Key Lifecycle

```
Profile Selection (inside atomic lock):
├─ incr playlist_profile:1:connections            [count=1]
├─ set  stream:reservation:abc:profile_id = 1
├─ sadd playlist_profile:1:streams reservation:abc
├─ setex channel_stream:123:uuid = reservation:abc  [TTL=30s]
└─ setex stream:reservation:abc:channel = "123:uuid" [TTL=30s]

Stream Created (finalizeReservation):
├─ srem playlist_profile:1:streams reservation:abc
├─ del  stream:reservation:abc:profile_id
├─ sadd playlist_profile:1:streams abc123          [real stream ID]
├─ set  stream:abc123:profile_id = 1
├─ set  channel_stream:123:uuid = abc123           [TTL=24h]
└─ set  stream:abc123:channel = "123:uuid"         [TTL=24h]

Stream Ended (via stream_stopped webhook):
├─ decr playlist_profile:1:connections             [count=0, atomic Lua]
├─ del  stream:abc123:profile_id
├─ srem playlist_profile:1:streams abc123
├─ del  stream:abc123:channel
└─ del  channel_stream:123:uuid  (only if still pointing to abc123)
```

#### Why Redis?

1. **Speed**: O(1) operations, no database query overhead
2. **Real-time**: Immediate reflection of connection changes
3. **Auto-cleanup**: TTL prevents stale keys from accumulating
4. **Atomic operations**: Lua scripts prevent race conditions (count never goes negative)
5. **Distributed**: Shared state across multiple app instances

### Atomic Locking to Prevent Race Conditions

When multiple clients request the same channel simultaneously, there is a race window where two requests might both see "capacity available" and both try to allocate slots. The system prevents this with a per-playlist Redis lock:

1. **Lock acquired** — only one request can allocate at a time
2. **Channel reuse check** — if channel already has a reservation, skip allocation
3. **Profile selected and count incremented** — inside the lock, atomically
4. **Lock released** — next request now sees updated count

If capacity is still unavailable after the lock, the system calls `reconcileFromProxy()` to correct any stale Redis counts (e.g. from rapidly switching channels) and retries once before returning a 503.

### Pool Status Reporting

The `ProfileService::getPoolStatus()` method returns the live pool state for all profiles in a playlist, including:
- Active connection count (from Redis)
- Maximum connection capacity (from `effective_max_streams`)
- Available slots
- Profile expiration dates (for subscription monitoring)

---

## Configuration & Settings

### Environment Variables

```env
# M3U Proxy Configuration (required for profiles)
M3U_PROXY_HOST=localhost        # or container hostname (e.g. m3u-proxy)
M3U_PROXY_PORT=8085
M3U_PROXY_TOKEN=your-secret-token

# Redis Configuration (required for pooling)
REDIS_HOST=localhost
REDIS_SERVER_PORT=6379
```

See [M3U Proxy Integration Guide](m3u-proxy-integration.md) for full Docker Compose setup.

### Database Migrations

```
# Create playlist_profiles table
2025_12_17_000001_create_playlist_profiles_table.php

# Add URL field (added January 2026)
2026_01_03_181027_add_url_to_playlist_profiles_table.php
```

---

## Best Practices

### For End Users

1. **Enable profiles only if needed**: Adds complexity and requires proxy
2. **Test all profiles after adding**: Ensure credentials are correct
3. **Set reasonable priorities**: Primary = 0, backups = 1, 2, etc.
4. **Monitor pool status**: Check the pool status widget regularly
5. **Check provider info expiry dates**: Expired subscriptions will fail silently

### For Developers

1. **Cache provider info**: Don't call API on every request — use `refreshProfile()` in background jobs
2. **Use Redis for connections**: Avoid database for high-frequency updates
3. **Handle profile URL transformation carefully**: Regex patterns must match standard Xtream URL format
4. **Test failover scenarios**: Ensure graceful degradation when profiles fail
5. **Log profile selection**: Use debug logging to trace "no capacity" issues

---

## Future Enhancements

Potential improvements for future versions:

1. **Max Clients Per Pool**: Limit how many users can share one stream
2. **Quality Tiers**: Different pools for SD/HD/4K quality
3. **Load Balancing**: Distribute clients across multiple transcoded streams
4. **Persistent Streams**: Keep popular channels always transcoding
5. **Predictive Pooling**: Pre-start streams for likely-to-be-watched channels
6. **Profile Health Check**: Monitor provider connectivity automatically
7. **Automatic Failover**: Switch to backup profile if primary fails
8. **Usage Analytics**: Track profile usage and efficiency metrics

---

## Frequently Asked Questions

### Q: Can I use accounts from completely different IPTV providers?

**A:** While technically possible by setting different URLs, it's **not recommended**. Provider Profiles is designed for the same provider with multiple accounts. Different providers may have:
- Incompatible URL structures
- Different API implementations  
- Varying authentication methods
- Different channel naming/IDs

For different providers, create separate playlists instead.

### Q: Why can't I just set any URL I want?

**A:** The URL transformation system uses pattern matching to replace credentials and server URLs. It expects a consistent Xtream API URL format:
```
http://provider.com/live/username/password/stream123.ts
```

If a profile URL uses a different URL structure (different path format), the regex pattern won't match and the original URL will be used unchanged — which may fail to authenticate.

### Q: Why does the proxy need to be enabled for Provider Profiles?

**A:** Provider Profiles requires the m3u-proxy for two reasons:
1. **Connection tracking**: The proxy sends `stream_stopped` webhooks that trigger Redis decrements. Without the proxy, connections would never be decremented and all profiles would eventually appear "full".
2. **Stream pooling**: The proxy manages shared FFmpeg processes so multiple clients can share one provider connection.

### Q: The pool status shows connections but streams have ended — why?

**A:** This usually means the `stream_stopped` webhook was not received. Common causes:
- The proxy is not configured with a webhook URL back to m3u-editor
- Network connectivity issue between proxy and editor
- Redis count drift from a crash or restart

**Fix:** Use the reconcile function or restart the proxy. See [Troubleshooting Guide](pooled-providers-troubleshooting.md).

### Q: Real-World Configuration Examples

**Family with 3 IPTV Accounts:**
```
Provider: MyIPTV.com
Account 1: user1 @ MyIPTV.com (5 connections) — Priority 0
Account 2: user2 @ MyIPTV.com (5 connections) — Priority 1
Account 3: user3 @ MyIPTV.com (5 connections) — Priority 2

Total Capacity: 15 simultaneous streams!
```

**Provider with Regional Servers:**
```
Provider: GlobalIPTV
Account 1: user1 @ us.global-iptv.com (primary)     — Priority 0
Account 2: user1 @ eu.global-iptv.com (backup URL)  — Priority 1
Account 3: user1 @ asia.global-iptv.com (backup URL) — Priority 2

Redundancy without extra cost!
```

### Q: Quick Reference — Common Configurations

**Basic Setup (Same Provider, Same Server):**
- Primary: username=user1, URL=(blank)
- Backup: username=user2, URL=(blank)

**Advanced Setup (Same Provider, Multiple Servers):**
- Primary: username=user1, URL=us.provider.com
- Backup 1: username=user2, URL=eu.provider.com
- Backup 2: username=user3, URL=asia.provider.com

### Q: Capacity Planning Guide

- **Light use** (1-3 people): 2 accounts = 4-6 connections
- **Medium use** (3-5 people): 3 accounts = 9-15 connections
- **Heavy use** (5+ people): 4-5 accounts = 20-25 connections

---

## Related Documentation

- [Stream Pooling Technical Details](stream-pooling.md)
- [M3U Proxy Integration Guide](m3u-proxy-integration.md)
- [Pooled Providers Troubleshooting](pooled-providers-troubleshooting.md)
- [Pooled Providers Architecture](pooled-providers-architecture.md)
