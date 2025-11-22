## ⚡ Swoole Coroutine Support (Enhanced Fork)

This fork extends Laravel Octane with **true coroutine support** for Swoole, enabling non-blocking I/O and massive concurrency improvements.

### The Problem

Standard Octane uses a "One Worker = One Request" model. When a request performs blocking I/O (database queries, API calls, sleep), the entire worker is blocked:

```
4 workers × 1 request/worker = 4 concurrent requests max
```

With 5-second blocking operations, this means **0.8 requests/second throughput**.

### The Solution: Coroutine Pool

This fork implements a **Worker Pool** architecture where each Swoole worker maintains a pool of isolated Laravel Application instances:

```
4 workers × 50 concurrent requests/worker = 200 concurrent requests
```

With the same 5-second blocking operations, this achieves **40 requests/second throughput** — a **50× improvement**!

### Key Features

✅ **Non-blocking I/O** - Automatic coroutine switching during database/API calls  
✅ **Massive concurrency** - Handle 50-500 concurrent requests per worker  
✅ **Complete isolation** - Each coroutine gets its own Application instance  
✅ **Production-safe** - Proper state management prevents memory leaks  
✅ **Configurable** - Tune pool size via CLI, env vars, or config  
✅ **Memory-efficient** - Smart defaults prevent resource exhaustion

### Quick Start

Since this package is not yet on Packagist, install it directly from the GitHub repository:

1. **Update `composer.json`** to include the repository:

```json
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/ModelsLab/octane-coroutine"
    }
],
"require": {
    "modelslab/octane-coroutine": "^0.1"
}
```

2. **Install the package**:

```bash
composer update
```

3. **Configure pool size** (optional, default is 50):

```bash
echo "OCTANE_POOL_SIZE=100" >> .env
```

4. **Start with coroutine support**:

```bash
php artisan octane:start --server=swoole --workers=4 --pool=100
```

### Configuration Options

**1. Via CLI (highest priority):**
```bash
php artisan octane:start --server=swoole --pool=25
```

**2. Via Environment Variable:**
```bash
# .env
OCTANE_POOL_SIZE=50
```

**3. Via Config File:**
```php
// config/octane.php
'swoole' => [
    'pool' => [
        'size' => 50,        // Application instances per worker
        'min_size' => 1,     // Minimum allowed
        'max_size' => 1000,  // Maximum allowed
    ],
],
```

### Performance Comparison

| Scenario | Standard Octane | With Coroutines | Improvement |
|----------|----------------|-----------------|-------------|
| **5s blocking I/O** | 0.8 req/s | 40 req/s | **50×** |
| **1s blocking I/O** | 4 req/s | 200 req/s | **50×** |
| **Memory usage** | ~200MB | ~2-10GB* | - |
| **Concurrent requests** | 4 | 200 | **50×** |

*Depends on pool size and application complexity

### Resource Planning

Choose your pool size based on available resources:

- **Small (10-20)**: Development, 2-4GB RAM
- **Medium (50-100)**: Production small-medium, 4-8GB RAM  
- **Large (150-300)**: High-traffic, 8-16GB RAM
- **XL (400-1000)**: Enterprise, 16GB+ RAM

**Formula**: `Memory needed ≈ pool_size × workers × 10-50MB per Application`

### Important Notes

⚠️ Ensure your **database max_connections** can handle `pool_size × workers`  
⚠️ Monitor memory usage and adjust pool size accordingly  
⚠️ Avoid static variables in your application code (they're shared across coroutines)

### Architecture

Unlike standard Octane which uses process-level concurrency, this fork implements **coroutine-level concurrency** using:

- `Swoole\Coroutine\Channel` for Worker pooling
- `Swoole\Coroutine::getContext()` for request isolation
- Isolated Laravel Application instances per coroutine
- Coroutine-safe timer table using `Coroutine::getCid()`

### When to Use This Fork

✅ **Use this fork if:**
- Your app makes many external API calls
- You have long-running database queries
- You need to handle 1000+ concurrent requests
- Your operations involve blocking I/O (file uploads, etc.)

❌ **Standard Octane is fine if:**
- Your requests are purely CPU-bound
- Average response time is <100ms
- You don't need high concurrency

## Official Documentation

Documentation for Octane can be found on the [Laravel website](https://laravel.com/docs/octane).

## Contributing

Thank you for considering contributing to Octane! You can read the contribution guide [here](.github/CONTRIBUTING.md).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

Please review [our security policy](https://github.com/laravel/octane/security/policy) on how to report security vulnerabilities.

## License

Laravel Octane is open-sourced software licensed under the [MIT license](LICENSE.md).
