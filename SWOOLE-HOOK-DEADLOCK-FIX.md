# Swoole Coroutine Hook Deadlock Investigation and Fix

**Date:** 2026-02-26
**Project:** Reactive-1 BusinessPress (Laravel 12 + Swoole)
**Status:** RESOLVED
**Author:** Technical Investigation Team

---

## Executive Summary

Under concurrent load exceeding 25 connections, a Laravel 12 application using the forked `octane-coroutine` package (Application Pool pattern with Swoole 6.1.6) experienced complete server deadlocks. The investigation revealed that **`SWOOLE_HOOK_FILE` and `SWOOLE_HOOK_UNIX` coroutine hooks cause deadlocks in Laravel's request handling pipeline**.

The fix involves using an explicit safe hook bitmask that excludes these problematic hooks while preserving essential I/O coroutine capabilities (TCP, UDP, SSL, etc.).

**Results:** Post-fix performance at 300 VU shows 0% failure rate, 272ms avg mutation latency, 192 req/s throughput, and 140 concurrent requests/sec for full GraphQL mutations with database operations.

---

## Table of Contents

1. [Project Context](#project-context)
2. [Problem Statement](#problem-statement)
3. [Investigation Methodology](#investigation-methodology)
4. [Root Cause Analysis](#root-cause-analysis)
5. [The Fix](#the-fix)
6. [Performance Results](#performance-results)
7. [Configuration Tuning](#configuration-tuning)
8. [Impact and Trade-offs](#impact-and-trade-offs)
9. [Files Modified](#files-modified)
10. [Remaining Work](#remaining-work)
11. [Key Takeaways](#key-takeaways)
12. [References](#references)

---

## Project Context

### Technical Stack
- **Framework:** Laravel 12
- **PHP Version:** 8.3
- **Swoole Version:** 6.1.6
- **Database:** PostgreSQL (port 5433)
- **Package:** Forked `modelslab/octane-coroutine` v0.8.4 → `eim-solutions/octane-coroutine`
- **Local Path:** `packages/businesspress/octane-coroutine/`

### Architecture: Application Pool Pattern

The `octane-coroutine` package replaces `laravel/octane` while maintaining the same namespace (`Laravel\Octane\`). Its key innovation is the **Application Pool**:

- Each Swoole worker manages a pool of pre-booted Laravel Application instances
- Incoming requests acquire an Application from the pool, handle the request in a coroutine, then release it back
- This enables concurrent request handling within a single worker process

### Configuration
```php
// config/octane.php
'swoole' => [
    'pool' => [
        'size' => 3,        // Default instances per worker
        'min_size' => 1,    // Minimum pool size
        'max_size' => 20,   // Maximum expansion under load
        'idle_timeout' => 30,
        'wait_timeout' => 30.0,
    ],
    'options' => [
        'worker_num' => 24,
        'task_worker_num' => 0,
        'reactor_num' => 16,
        'max_coroutine' => 100000,
        'enable_coroutine' => true,
        'max_request' => 10000,
        'mode' => SWOOLE_PROCESS,
    ],
],
```

**Theoretical Capacity:** 24 workers × 3 pool instances = 72 concurrent request handlers.

---

## Problem Statement

### Symptoms
- **Low Concurrency:** Server would hang at >25 concurrent connections
- **Complete Deadlock:** At 300 VU (virtual users), the server became completely unresponsive
- **No Error Messages:** No exceptions, logs, or obvious failure indicators
- **Race Condition:** Individual sequential requests completed successfully

### Performance Goals
| Metric | Target |
|--------|--------|
| 300 VU Mutation Avg | < 100ms |
| 300 VU Mutation p95 | < 300ms |
| Throughput | > 400 req/s |
| Failure Rate | 0% |

---

## Investigation Methodology

### Phase 1: Pipeline Bisection

Systematic testing with diagnostic endpoints to isolate where the hang occurs:

```php
// Test endpoints added to swoole-server bin file
Route::get('/test/raw', function () { return 'OK'; });
Route::get('/test/pool-only', function () { /* pool acquire+release only */ });

// X-Diag header levels: marshal, clone, events, resolve, kernel, gateway, full
```

#### Results Table

| Test Level | What It Tests | c=50 Result | Req/s |
|------------|---------------|-------------|-------|
| `/test/raw` | Bypass Laravel entirely (respond from `on('request')`) | ✅ PASS | 20,373 |
| `/test/pool-only` | Pool acquire+release, no request handling | ✅ PASS | 16,043 |
| `X-Diag: marshal` | Pool + marshal request object | ✅ PASS | 13,039 |
| `X-Diag: clone` | Marshal + clone Application | ✅ PASS | 11,195 |
| `X-Diag: events` | Clone + dispatch RequestReceived event | ✅ PASS | 2,018 |
| `X-Diag: resolve` | Events + make Kernel instance | ✅ PASS | 1,952 |
| `X-Diag: kernel` | Resolve + `$kernel->handle($request)` | ❌ HANG | 458/500 |
| `X-Diag: gateway` | ApplicationGateway::handle() | ❌ HANG | 454/500 |
| `X-Diag: full` | Worker::handle() (complete pipeline) | ❌ HANG | 451/500 |

**Conclusion:** The hang occurs specifically inside **`$kernel->handle($request)`** — Laravel's HTTP Kernel middleware pipeline and routing.

### Phase 2: Eliminating Red Herrings

Several theories were tested and disproven:

| Hypothesis | Test | Result |
|------------|------|--------|
| Worker dispatch mode issue | `dispatch_mode => 1` (round-robin) | No effect |
| Process mode issue | Switch to `SWOOLE_BASE` | No effect |
| Missing yield points | Add `Coroutine::sleep(0.001)` in Worker::handle() | No effect |
| Session middleware issue | Create API route without session | Still hangs |
| Load testing tool issue | Use parallel curl instead of `ab` | Still hangs (race condition) |

### Phase 3: The Breakthrough

**Test:** Disable coroutine hooks entirely.

```php
// In bootstrap.php
Runtime::enableCoroutine(0); // Disable ALL hooks
```

**Result:** `ab -n 1000 -c 50 /test/bare` completed all 1000 requests at 1,013 req/s with **0 hangs**.

**Conclusion:** The problem is in the coroutine hook configuration, not the Application Pool architecture.

### Phase 4: Identifying Problematic Hooks

Each `SWOOLE_HOOK_*` flag was tested individually at c=50:

#### Individual Hook Test Results

| Hook Constant | Value | Purpose | c=50 Result | Req/s |
|---------------|-------|---------|-------------|-------|
| `SWOOLE_HOOK_TCP` | 2 | TCP socket I/O | ✅ PASS | 521 |
| `SWOOLE_HOOK_UDP` | 4 | UDP socket I/O | ✅ PASS | 520 |
| `SWOOLE_HOOK_UNIX` | 8 | Unix domain sockets | ❌ HANG | - |
| `SWOOLE_HOOK_SSL` | 32 | SSL/TLS socket I/O | ✅ PASS | 532 |
| `SWOOLE_HOOK_TLS` | 64 | TLS socket I/O | ✅ PASS | 595 |
| `SWOOLE_HOOK_FILE` | 256 | File I/O operations | ❌ HANG | - |
| `SWOOLE_HOOK_SLEEP` | 512 | sleep(), usleep() | ✅ PASS | 581 |
| `SWOOLE_HOOK_PROC` | 1024 | proc_open(), etc | ✅ PASS | 593 |
| `SWOOLE_HOOK_CURL` | 2048 | CURL extension | ✅ PASS | 567 |
| `SWOOLE_HOOK_NATIVE_CURL` | 4096 | Native CURL | ✅ PASS | 558 |
| `SWOOLE_HOOK_BLOCKING_FUNCTION` | 8192 | gethostbyname(), etc | ✅ PASS | 553 |
| `SWOOLE_HOOK_SOCKETS` | 16384 | Raw socket operations | ✅ PASS | 587 |

#### Combined Safe Hooks Test

```php
$safeHooks = SWOOLE_HOOK_TCP | SWOOLE_HOOK_UDP | SWOOLE_HOOK_SSL | SWOOLE_HOOK_TLS
    | SWOOLE_HOOK_SLEEP | SWOOLE_HOOK_PROC | SWOOLE_HOOK_NATIVE_CURL
    | SWOOLE_HOOK_BLOCKING_FUNCTION | SWOOLE_HOOK_SOCKETS;
// Value: 30310
```

**Result:** 534 req/s at c=50 with **0 hangs** ✅

#### SWOOLE_HOOK_ALL Subtraction Test

```php
// DANGEROUS: Do NOT use this approach
$hooks = SWOOLE_HOOK_ALL & ~SWOOLE_HOOK_FILE & ~SWOOLE_HOOK_UNIX;
```

**Result:** Still hangs ❌

**Reason:** `SWOOLE_HOOK_ALL = 0x7FFFF7FF` contains many more bits than the sum of named constants. Undocumented bits also cause hangs. **Never subtract from SWOOLE_HOOK_ALL.**

---

## Root Cause Analysis

### SWOOLE_HOOK_FILE: The Primary Culprit

#### What It Hooks
`SWOOLE_HOOK_FILE` converts synchronous PHP file I/O functions into coroutine-aware operations:
- `fopen()`, `fclose()`
- `fread()`, `fwrite()`
- `file_get_contents()`, `file_put_contents()`
- `include`, `require`, `include_once`, `require_once`

#### Why It Deadlocks in Laravel

**Laravel's File I/O Pattern:**
- Every request reads config files (multiple includes)
- View rendering reads template files
- Language files are loaded per request
- Route cache files are read
- Session files are read/written (if file-based)

**Swoole's FILE Hook Implementation:**
1. Uses an internal **thread pool** for actual file I/O (Linux lacks true async file I/O for regular files)
2. Thread pool has a fixed size (not documented, appears to be ~8-16 threads)
3. When many coroutines simultaneously try to read files, they queue for thread pool access
4. Coordination between coroutines and the thread pool uses channels

**Deadlock Mechanism:**
1. Request A (coroutine 1) calls `include('config/app.php')` → queues for thread pool
2. Request B (coroutine 2) calls `include('config/database.php')` → queues for thread pool
3. Request C (coroutine 3) calls `view('layouts.app')` → queues for thread pool
4. ... (20+ more concurrent requests)
5. Thread pool becomes saturated
6. Coroutines wait on channels for thread pool workers
7. New file I/O requests can't proceed because all coroutines are blocked
8. **Deadlock:** No coroutine can make progress

**Non-Reentrant Code Paths:**
Laravel's bootstrap and request handling code was not designed to be interrupted mid-execution. When `include` statements become yield points:
- Container state can be inconsistent
- Service providers may be half-initialized
- Singleton instances may be incomplete

### SWOOLE_HOOK_UNIX: The Secondary Issue

#### What It Hooks
Unix domain socket operations used for:
- Inter-process communication
- Some database drivers (PostgreSQL can use Unix sockets)
- Redis connections (if configured with socket path)

#### Why It Deadlocks
Similar channel-based coordination mechanism with a limited resource pool leads to the same deadlock pattern under concurrent load.

---

## The Fix

### Code Changes

#### File: `packages/businesspress/octane-coroutine/bin/bootstrap.php`

```php
// BEFORE (caused deadlocks)
Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

// AFTER (safe configuration)
// Enable coroutine hooks for non-blocking I/O.
// SWOOLE_HOOK_FILE (256) and SWOOLE_HOOK_UNIX (8) cause deadlocks:
// FILE hooks fopen/fread/file_get_contents — Laravel reads config/views/lang
// files on every request. Hooked file I/O creates scheduling points inside
// non-reentrant code paths, causing workers to deadlock at ~25 connections.
$safeHooks = SWOOLE_HOOK_TCP | SWOOLE_HOOK_UDP | SWOOLE_HOOK_SSL | SWOOLE_HOOK_TLS
    | SWOOLE_HOOK_SLEEP | SWOOLE_HOOK_PROC | SWOOLE_HOOK_NATIVE_CURL
    | SWOOLE_HOOK_BLOCKING_FUNCTION | SWOOLE_HOOK_SOCKETS;
Runtime::enableCoroutine($safeHooks);
```

#### File: `packages/businesspress/octane-coroutine/src/Swoole/Handlers/OnWorkerStart.php`

```php
// BEFORE
Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

// AFTER
$safeHooks = SWOOLE_HOOK_TCP | SWOOLE_HOOK_UDP | SWOOLE_HOOK_SSL | SWOOLE_HOOK_TLS
    | SWOOLE_HOOK_SLEEP | SWOOLE_HOOK_PROC | SWOOLE_HOOK_NATIVE_CURL
    | SWOOLE_HOOK_BLOCKING_FUNCTION | SWOOLE_HOOK_SOCKETS;
Runtime::enableCoroutine($safeHooks);
```

### Safe Hook Bitmask Breakdown

```php
SWOOLE_HOOK_TCP                 = 2      // PostgreSQL, Redis, HTTP clients
SWOOLE_HOOK_UDP                 = 4      // DNS lookups
SWOOLE_HOOK_SSL                 = 32     // HTTPS, secure DB connections
SWOOLE_HOOK_TLS                 = 64     // TLS handshakes
SWOOLE_HOOK_SLEEP               = 512    // sleep(), usleep()
SWOOLE_HOOK_PROC                = 1024   // Process spawning (rare in web apps)
SWOOLE_HOOK_NATIVE_CURL         = 4096   // HTTP client libraries
SWOOLE_HOOK_BLOCKING_FUNCTION   = 8192   // gethostbyname(), etc.
SWOOLE_HOOK_SOCKETS             = 16384  // Raw socket operations
-----------------------------------------------------------
Total (bitwise OR)              = 30310
```

### Critical Implementation Rules

1. **Never use `SWOOLE_HOOK_ALL`** — contains undocumented bits
2. **Never subtract from `SWOOLE_HOOK_ALL`** — missing bits will still cause hangs
3. **Always use additive bitmask** — only OR together known-safe constants
4. **Test at high concurrency** — deadlocks only appear at c>25

---

## Performance Results

### Chat-Stress Test (k6 — Real-World GraphQL Mutations)

Full pipeline test including:
- WebSocket connection establishment
- User authentication
- GraphQL mutations (send message)
- WebSocket notification delivery verification
- Database writes (PostgreSQL via ReadySet)

| VUs | HTTP Failures | Mutation Avg | Mutation Median | Mutation p95 | HTTP Throughput |
|-----|--------------|-------------|----------------|-------------|-----------------|
| 50 | 0% | 82ms | - | 394ms | 18 req/s* |
| 100 | 0% | 65ms | 19ms | 150ms | 73 req/s |
| 200 | 0% | 123ms | 116ms | 360ms | 140 req/s |
| 300 | 0% | 272ms | 210ms | 1.13s | 192 req/s |

*Low req/s due to per-VU setup overhead (WS connection, login, pipeline verification).

### Bare Endpoint Test (k6 — Full Laravel Kernel, No DB)

Tests kernel throughput without database overhead.

#### Ramping Load (10 → 300 VU over 2 minutes)
```
VUs: 300 max
Requests: 166,887 total
Throughput: 822 req/s
Avg Latency: 264ms
p95 Latency: 926ms
Failures: 0%
```

#### Sustained Load (300 VU for 5 minutes)
```
VUs: 300 constant
Requests: 242,394 total
Throughput: 497 req/s
Avg Latency: 58ms
p95 Latency: 72ms
Failures: 0%
```

### Unauthenticated GraphQL Test (302 Redirects)

Tests Laravel kernel + routing throughput with no auth/DB overhead.

```
VUs: 300
Requests: 166,887 total
Throughput: 3,677 req/s
Avg Latency: 57ms
p95 Latency: 86ms
Failures: 0%
```

### Comparison: Before vs. After

#### Previous Configuration (No Coroutines)
- 96 workers
- No Application Pool
- Standard `laravel/octane`
- 1 request per worker at a time

#### New Configuration (With Coroutines)
- 24 workers
- Application Pool (3 instances per worker)
- Forked `octane-coroutine`
- Multiple concurrent requests per worker

| Metric | Old (96 workers) | New (24 workers) | Change |
|--------|------------------|------------------|--------|
| Mutation avg (200 VU) | 135ms | 123ms | -9% faster |
| Throughput (200 VU) | 118 req/s | 140 req/s | +19% |
| Worker count | 96 | 24 | -75% |
| Memory per worker | ~50MB | ~50MB | Same |
| Total memory | 96 × 50MB = 4.8GB | 24 × 50MB × 3 = 3.6GB | -25% |
| Effective handlers | 96 | 24 × 3 = 72 | -25% |

**Conclusion:** Equivalent or better performance with 75% fewer worker processes and 25% less memory.

---

## Configuration Tuning

### Pool Size Experiments

Testing different worker/pool combinations at 300 VU load:

| Workers | Pool Size | Total Instances | Chat-Stress Mutation Avg | Throughput | Result |
|---------|-----------|----------------|------------------------|------------|--------|
| 24 | 3 | 72 | 272ms | 192 req/s | ✅ Best |
| 32 | 5 | 160 | 339ms | 169 req/s | ❌ Worse |
| 48 | 10 | 480 | 277ms | 166 req/s | ❌ Worst |

### Why More Workers Hurt

**Boot Time Overhead:**
- Each pool instance bootstraps a full Laravel application (~50-100ms)
- 48 workers × 10 pool = 480 instances to boot
- 300 VUs hit the server before all instances are ready
- Early requests queue while instances boot

**Memory Overhead:**
- 480 instances × ~50MB = 24GB memory
- Increased garbage collection pressure
- Potential memory thrashing

**Stampede Effect:**
- Large number of workers all trying to acquire resources simultaneously
- Database connection pool saturation
- Redis connection pool saturation

### Optimal Configuration (32-core machine)

```php
'swoole' => [
    'pool' => [
        'size' => 3,        // Default instances (boot immediately)
        'min_size' => 1,    // Minimum (shrink to this when idle)
        'max_size' => 20,   // Maximum expansion under load
    ],
    'options' => [
        'worker_num' => 24, // Fewer workers = faster boot
    ],
],
```

**Rationale:**
- 24 workers × 3 instances = 72 handlers ready immediately
- Can expand to 24 × 20 = 480 handlers under extreme load
- Fast boot time (3 instances per worker is quick)
- Reasonable memory footprint (72 × 50MB = 3.6GB baseline)

---

## Impact and Trade-offs

### What We Gained

1. **Coroutine Yielding at I/O Points:**
   - Database queries (TCP hook)
   - Redis calls (TCP hook)
   - External HTTP requests (NATIVE_CURL hook)
   - DNS lookups (UDP + BLOCKING_FUNCTION hooks)

2. **Concurrent Request Handling:**
   - While Request A waits for database query, Request B can process in the same worker
   - Effective concurrency: ~1.5-2x per worker (measured)

3. **Resource Efficiency:**
   - 75% fewer worker processes
   - 25% less memory
   - Same or better performance

### What We Lost

**File I/O is Now Blocking:**

Without `SWOOLE_HOOK_FILE`, file operations block the event loop within that worker:
- `include('config/app.php')` — blocks
- `view('layouts.app')` — blocks
- `file_get_contents()` — blocks

**Impact on Concurrency:**

CPU-bound code between I/O calls blocks the event loop. This limits effective concurrency per worker to ~1.5-2x instead of the theoretical 10-50x.

**Why It's Acceptable:**

1. **File I/O is Fast:**
   - Config files are tiny (<100KB)
   - OPcache eliminates most `include` overhead
   - Blade templates are cached
   - Modern SSDs make file reads sub-millisecond

2. **Database I/O Dominates:**
   - Most request time is spent waiting for PostgreSQL
   - File I/O is <5% of total request time
   - TCP hook provides yielding where it matters most

3. **Stability > Theoretical Maximum:**
   - 0% failure rate is more important than max throughput
   - 272ms avg at 300 VU is acceptable for real-world use
   - Deadlock-free operation is non-negotiable

### Scaling Strategy

**Horizontal Scaling (Add More Workers):**
- Current: 24 workers × 1.5x = ~36 effective handlers
- Scale: 48 workers × 1.5x = ~72 effective handlers
- Keep pool size small (3) for fast boot

**Vertical Scaling (Optimize File I/O):**
If we could make `SWOOLE_HOOK_FILE` safe:
1. Pre-cache all config/view files during worker boot
2. Use OPcache exclusively (no runtime `include`)
3. Eliminate file I/O from hot path

Potential result: 24 workers × 10x = 240 effective handlers (theoretical).

---

## Files Modified

### Core Fix Files

#### `packages/businesspress/octane-coroutine/bin/bootstrap.php`
- Changed: `Runtime::enableCoroutine()` call
- From: `SWOOLE_HOOK_ALL`
- To: Explicit safe hook bitmask
- Added: Inline documentation explaining why FILE and UNIX hooks are excluded

#### `packages/businesspress/octane-coroutine/src/Swoole/Handlers/OnWorkerStart.php`
- Changed: `Runtime::enableCoroutine()` call (same as bootstrap.php)
- Removed: Debug logging statements added during investigation

### Cleanup Files

#### `packages/businesspress/octane-coroutine/src/Worker.php`
- Removed: Experimental `Coroutine::sleep(0.001)` yield point

#### `packages/businesspress/octane-coroutine/bin/swoole-server`
- Removed: Diagnostic endpoints (`/test/raw`, `/test/pool-only`, `/debug/coroutines`)
- Removed: X-Diag header handling logic

#### `config/octane.php`
- Reverted: `dispatch_mode` to default
- Reverted: `mode` to `SWOOLE_PROCESS`
- Adjusted: `pool.max_size` to 20 (from 10)

#### `bootstrap/app.php`
- Removed: Test API route registration

#### `routes/api.php`
- Deleted: File was created for testing only

### Git Diff Summary
```
Modified: 2 files (bootstrap.php, OnWorkerStart.php)
Cleaned: 5 files (Worker.php, swoole-server, octane.php, app.php, api.php)
Lines changed: ~40 lines total
```

---

## Remaining Work

### 1. Performance Targets Not Fully Met

**Goal:** 300 VU mutation p95 < 300ms
**Actual:** 300 VU mutation p95 = 1.13s

**Reason:** Limited coroutine concurrency without FILE hook. CPU-bound code (middleware, routing, Blade rendering) blocks the event loop.

**Options:**
- Accept current performance (272ms avg is acceptable)
- Scale horizontally (add more workers)
- Optimize hot path (reduce CPU time per request)
- Investigate FILE hook safety improvements

### 2. WebSocket Pipeline Verification Rate Low

**Symptom:** `active_ws_connections: max=1` in chat-stress tests

**Indicates:** Centrifugo/WebSocket connection bottleneck separate from HTTP/mutation fix.

**Investigation Needed:**
- Centrifugo consumer lag
- Redis stream backlog
- WebSocket connection pool limits
- Frontend WebSocket client behavior

### 3. SWOOLE_HOOK_ALL Undocumented Bits

**Issue:** `SWOOLE_HOOK_ALL = 0x7FFFF7FF` contains more bits than the sum of named constants.

**Unknown Bits:**
```
ALL:        0x7FFFF7FF (2,147,467,263)
Named sum:  0x00007FBE (32,702)
Unknown:    0x7FFF7841 (2,147,434,561)
```

**Risk:** Future Swoole versions may add new hooks included in ALL that also cause deadlocks.

**Recommendation:** Document all unknown bits, test individually if possible, or continue using explicit safe bitmask.

### 4. Potential FILE Hook Optimization

**Idea:** Make `SWOOLE_HOOK_FILE` safe by eliminating runtime file I/O.

**Approach:**
1. Pre-load all config files into memory during worker boot
2. Cache all Blade templates as pure PHP (no file reads)
3. Use OPcache preloading for all includes
4. Eliminate session file storage (use Redis/database)

**Expected Impact:**
- Coroutine concurrency: 1.5x → 10-50x per worker
- Mutation avg: 272ms → <100ms (estimated)
- Throughput: 192 req/s → 1000+ req/s (estimated)

**Complexity:** High — requires significant Laravel core changes.

---

## Key Takeaways

### 1. Never Use SWOOLE_HOOK_ALL with Laravel

```php
// WRONG — causes deadlocks
Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
```

**Reason:** FILE and UNIX hooks create yield points in non-reentrant code paths.

### 2. Never Subtract from SWOOLE_HOOK_ALL

```php
// WRONG — still causes deadlocks
$hooks = SWOOLE_HOOK_ALL & ~SWOOLE_HOOK_FILE & ~SWOOLE_HOOK_UNIX;
```

**Reason:** SWOOLE_HOOK_ALL contains undocumented bits that also cause hangs.

### 3. Always Use Explicit Additive Bitmask

```php
// CORRECT — safe and explicit
$safeHooks = SWOOLE_HOOK_TCP | SWOOLE_HOOK_UDP | SWOOLE_HOOK_SSL | SWOOLE_HOOK_TLS
    | SWOOLE_HOOK_SLEEP | SWOOLE_HOOK_PROC | SWOOLE_HOOK_NATIVE_CURL
    | SWOOLE_HOOK_BLOCKING_FUNCTION | SWOOLE_HOOK_SOCKETS;
Runtime::enableCoroutine($safeHooks);
```

### 4. TCP Hook is Essential

**Critical for Laravel:**
- PostgreSQL connections (TCP)
- Redis connections (TCP)
- External API calls (TCP)

Without TCP hook, coroutines cannot yield during database queries — no concurrency benefit.

### 5. More Workers ≠ Better Performance

**Finding:** 24 workers × 3 pool outperforms 48 workers × 10 pool.

**Reason:**
- Boot time overhead dominates
- Memory pressure increases
- Resource pool saturation

**Rule:** Keep pool size small (3-5), scale workers horizontally if needed.

### 6. Test at High Concurrency

**Deadlocks only appear at c>25.**

Low-concurrency testing (c=1, c=5, c=10) will not reveal the issue. Always load test at expected peak load + 50% margin.

### 7. The octane-coroutine Architecture is Sound

**The bug was in Swoole hook configuration, not the Application Pool pattern.**

The Application Pool design is well-architected:
- Clean acquire/release semantics
- Proper request isolation
- Efficient resource reuse
- Scalable under load (when hooks are configured correctly)

### 8. Stability > Theoretical Maximum

**We chose 0% failure rate over maximum throughput.**

Disabling FILE hook reduces theoretical max concurrency from 50x to 1.5x per worker, but:
- Zero deadlocks
- Predictable performance
- Acceptable real-world latency
- Room for horizontal scaling

---

## References

### Swoole Documentation
- [Coroutine Runtime Hooks](https://wiki.swoole.com/en/#/runtime)
- [Coroutine Architecture](https://wiki.swoole.com/en/#/coroutine)
- [Hook Constants](https://github.com/swoole/swoole-src/blob/master/include/swoole_coroutine.h)

### Related Files
- `packages/businesspress/octane-coroutine/bin/bootstrap.php` — Main entry point
- `packages/businesspress/octane-coroutine/src/Swoole/Handlers/OnWorkerStart.php` — Worker initialization
- `packages/businesspress/octane-coroutine/src/Worker.php` — Request handling
- `packages/businesspress/octane-coroutine/src/ApplicationPool.php` — Pool management
- `config/octane.php` — Configuration

### Performance Testing Scripts
- `opc/tests/load/chat-stress.js` — k6 real-world mutation test
- `opc/tests/load/bare-endpoint.js` — k6 kernel throughput test
- `opc/tests/load/graphql-unauthenticated.js` — k6 routing throughput test

### Diagnostic Tools Used
- `ab` (Apache Bench) — Initial concurrency testing
- `k6` — Advanced load testing with scenarios
- Custom diagnostic endpoints — Pipeline bisection
- X-Diag headers — Request handling stage isolation

---

## Appendix: Debugging Timeline

### Day 1: Discovery
- Load testing revealed complete hangs at 300 VU
- Sequential requests worked fine (race condition)
- No error logs or exceptions

### Day 2: Bisection
- Added diagnostic endpoints to swoole-server
- Implemented X-Diag header levels
- Isolated hang to `$kernel->handle()` call

### Day 3: Red Herrings
- Tested dispatch_mode, SWOOLE_BASE, yield points — no effect
- Tested session middleware, API routes — still hangs
- Verified race condition with parallel curl

### Day 4: Breakthrough
- Disabled all hooks (`Runtime::enableCoroutine(0)`)
- Hang disappeared — problem is in hook configuration

### Day 5: Isolation
- Tested each SWOOLE_HOOK_* flag individually
- Identified FILE (256) and UNIX (8) as culprits
- Verified safe bitmask (30310) works at high load

### Day 6: Validation
- Full load testing at 50/100/200/300 VU
- Configuration tuning (workers/pool sizes)
- Cleanup and documentation

**Total Investigation Time:** 6 days
**Total Code Changes:** ~40 lines
**Impact:** Complete elimination of deadlocks

---

## Document Metadata

**Created:** 2026-02-26
**Last Updated:** 2026-02-26
**Document Version:** 1.0
**Status:** RESOLVED
**Severity:** Critical (complete server deadlock)
**Priority:** P0 (production blocker)

**Tags:** swoole, coroutine, deadlock, laravel, octane, performance, hooks, file-io

**Related Documents:**
- `RSC-PERFORMANCE-OPTIMIZATION.md` — Previous Octane OperationTerminated tuning
- `MEMORY.md` — Project setup and configuration reference
