# Super Micro

**A minimalist, plugin-oriented PHP micro-kernel framework for building modular web applications**

Super Micro is a lightweight, high-performance PHP framework that implements a plugin-based architecture with automatic dependency resolution, dynamic routing, and built-in caching. Despite its small footprint, it provides enterprise-grade features like topological sorting, service containers, and modular extension discovery.

## Features

### Core Architecture
- **Plugin-Based System** - Extend functionality through isolated, self-contained plugins
- **Automatic Dependency Resolution** - Graph-based dependency injection with topological sorting
- **Dynamic Module Discovery** - Automatic scanning and loading of modules and plugins
- **Lazy Loading** - Components are instantiated only when needed
- **Service Container** - Centralized dependency injection container with singleton pattern

### HTTP & Routing
- **Smart Router** - Static and dynamic route matching with parameter extraction
- **Regex Support** - Full regex patterns for advanced route matching
- **Method-Based Routing** - Route by HTTP method (GET, POST, PUT, DELETE, etc.)
- **Request/Response Abstraction** - Clean, immutable HTTP interfaces
- **Context Pipeline** - Request flows through plugin chain with shared context

### Performance & Caching
- **OPcache-Aware Cache Driver** - PHP-based caching with OPcache invalidation
- **Route Plan Caching** - Execution plans cached to eliminate runtime resolution overhead
- **Discovery Caching** - Module/plugin manifests cached with automatic invalidation
- **Zero-Config Performance** - Fast out of the box with intelligent defaults

### Developer Experience
- **Manifest-Based Configuration** - JSON manifests for simple plugin definition
- **File-Based Logging** - Structured logging with automatic directory creation
- **Debug Mode** - Detailed error pages with stack traces during development
- **Type Safety** - Leverages PHP 8.1+ readonly classes and strict types
- **No External Dependencies** - Pure PHP implementation

## Requirements

- PHP 8.1 or higher
- OPcache (recommended for production)

## Installation

- Download index.php file and place it on your server

### Directory Structure

```
super-micro/
public/index.php          # Entry point (the kernel)
extensions/modules/       # Shared modules (services, utilities)
  example-module/
    manifest.json
    Module.php
extensions/plugins/           # Request handlers (controllers, middlewares)
  example-plugin/
    manifest.json
    Plugin.php
data/cache/             # File-based cache storage
data/logs/              # Application logs
```

## Quick Start

### 1. Create a Module

Modules provide shared functionality and services.

**`extensions/modules/database/manifest.json`**
```json
{
  "name": "database",
  "className": "DatabaseModule",
  "provides": ["db", "database"],
  "requires": [],
  "providerScore": 100
}
```

**`extensions/modules/database/Module.php`**
```php
<?php
namespace Framework\Core;

class DatabaseModule extends Module
{
    public function boot(Services $s, Config $c): void
    {
        $s->bind('db', function() use ($c) {
            return new \PDO($c->get('db_dsn'));
        });
        $this->booted = true;
    }

    public function terminate(): void
    {
        // Cleanup logic
    }
}
```

### 2. Create a Plugin

Plugins handle HTTP requests and generate responses.

**`extensions/plugins/hello/manifest.json`**
```json
{
  "name": "hello",
  "className": "HelloPlugin",
  "routes": {
    "/hello/{name}": ["GET"]
  },
  "requires": [],
  "dependsOn": [],
  "provides": []
}
```

**`extensions/plugins/hello/Plugin.php`**
```php
<?php
namespace Framework\Core;
use Framework\Http\Response;

class HelloPlugin extends Plugin
{
    public function execute(Context $ctx, Services $s): ?Response
    {
        $name = $ctx->request()->get('name', 'World');
        return Response::html("<h1>Hello, {$name}!</h1>");
    }

    public function validate(): bool
    {
        return true;
    }
}
```

### 3. Configure and Run

The kernel auto-discovers and loads all extensions:

```php
// public/index.php is already configured
// Just navigate to: http://localhost/hello/SuperMicro
```

## Architecture Deep Dive

### Request Lifecycle

1. **Gate Phase** - Validate request size, content-type, and method
2. **Discovery** - Scan for modules and plugins (cached after first run)
3. **Routing** - Match request URI to plugin route
4. **Planning** - Resolve dependency graph and create execution plan (cached)
5. **Module Bootstrapping** - Initialize required modules in dependency order
6. **Plugin Chain Execution** - Execute plugin pipeline until response or halt
7. **Response Delivery** - Send HTTP response with proper headers
8. **Termination** - Cleanup modules in reverse order

### Dependency Resolution

Super Micro uses a sophisticated graph-based resolver that:
- Handles transitive dependencies automatically
- Performs topological sorting to determine load order
- Supports multiple providers for the same capability
- Scores providers to select the best implementation
- Detects circular dependencies and fails fast
- Allows optional dependencies with graceful degradation

**Example dependency chain:**
```
Plugin: BlogPost
  depends on: Template
    requires: Database
  requires: Database, Cache
```

The kernel resolves this to: `Database’ `Cache’ Template’ BlogPost`

### Context & Capabilities

The `Context` object flows through the plugin chain, allowing plugins to:
- Share data via `provide()` and `consume()`
- Modify request attributes with `attr()`
- Halt execution early with `halt()`
- Check if request was halted via `isHalted()`

**Example middleware pattern:**
```php
class AuthPlugin extends Plugin
{
    public function execute(Context $ctx, Services $s): ?Response
    {
        $token = $ctx->request()->header('Authorization');
        
        if (!$this->validateToken($token)) {
            $ctx->halt('Unauthorized', 401);
            return null;
        }
        
        $ctx->provide('user', $this->getUserFromToken($token));
        return null; // Continue to next plugin
    }
}
```

### Service Container

Lazy-loading singleton container for dependency injection:

```php
// In a Module
$services->bind('mailer', function() {
    return new MailService($config);
});

// In a Plugin
$mailer = $services->resolve('mailer');
$mailer->send('Welcome!');
```

### Routing Patterns

Super Micro supports three routing styles:

**1. Static Routes**
```json
{
  "routes": {
    "/about": ["GET"],
    "/contact": ["GET", "POST"]
  }
}
```

**2. Dynamic Routes with Parameters**
```json
{
  "routes": {
    "/user/{id}": ["GET"],
    "/post/{slug}": ["GET"],
    "/api/{version}/{endpoint}": ["*"]
  }
}
```

**3. Regex Routes**
```json
{
  "routes": {
    "#^/api/v[0-9]+/.*$#": ["GET", "POST"],
    "~/products/(\\d+)~": ["GET"]
  }
}
```

Parameters are extracted and available via `$ctx->request()->get('param_name')`.

### Caching Strategy

Three-tier caching system:

1. **Discovery Cache** - Manifest scanning results (24h TTL)
2. **Route Plan Cache** - Dependency resolution results (no expiration)
3. **OPcache Integration** - PHP file cache with automatic invalidation

Cache keys are content-addressable (MD5 hashes) for automatic invalidation.

## Manifest Reference

### Module Manifest

```json
{
  "name": "unique-module-name",
  "className": "ModuleClassName",
  "provides": ["capability1", "capability2"],
  "requires": [],
  "providerScore": 100
}
```

- **name** - Unique name for this module
- **className** - PHP class name (must extend `Framework\Core\Module`)
- **provides** - Capabilities this module provides to plugins
- **requires** - Other modules' capabilities this module depends on
- **providerScore** - Priority when multiple modules provide the same capability (higher = preferred)

### Plugin Manifest

```json
{
  "name": "unique-plugin-name",
  "className": "PluginClassName",
  "routes": {
    "/path": ["GET"],
    "/api/{id}": ["POST", "PUT"]
  },
  "requires": ["database", "cache"],
  "dependsOn": ["auth"],
  "provides": [],
  "providerScore": 100
}
```

- **name** - Unique name for this plugin
- **className** - PHP class name (must extend `Framework\Core\Plugin`)
- **routes** - URI patterns this plugin handles
- **requires** - Module capabilities needed (e.g., "database")
- **dependsOn** - Other capabilities provided by other plugins that must execute before this one
- **provides** - Capabilities this plugin provides to other plugins
- **providerScore** - Priority when multiple plugins provide the same capability (higher = preferred)

## API Reference

### Request Object

```php
$request = Request::capture();

$uri = $request->uri;           // Parsed URI path
$method = $request->method;     // HTTP method
$value = $request->get('key');  // Get from params/body/attributes
$header = $request->header('Content-Type');
$newReq = $request->with('attributes', 'key', 'value');
```

### Response Object

```php
// HTML Response
Response::html('<h1>Hello</h1>', 200);

// JSON Response
Response::json(['status' => 'ok'], 200);

// Redirect
Response::redirect('/new-location', 302);

// Send Response
$response->send();
```

### Context API

```php
$ctx = new Context($request);

// Request access
$request = $ctx->request();

// Attributes
$ctx->attr('key', 'value');

// Capabilities
$ctx->provide('service', $serviceInstance);
$service = $ctx->consume('service');

// Flow control
$ctx->halt('Reason', 403);
if ($ctx->isHalted()) { /* ... */ }
$response = $ctx->earlyResponse();
```

### Services Container

```php
$services = Services::get();

// Binding
$services->bind('logger', function() {
    return new Logger();
});

// Resolution (creates singleton)
$logger = $services->resolve('logger');
```

### Logger Interface

```php
$logger->log('info', 'User logged in', ['user_id' => 123]);
$logger->log('error', 'Database connection failed');
```

### Cache Driver

```php
$cache = new PhpDriver('/path/to/cache');

$cache->set('key', $value, 3600);
$value = $cache->get('key');
$cache->delete('key');
```

## Configuration

Edit the configuration array in `public/index.php`:

```php
$cfg = new Config([
    'db_dsn' => 'sqlite:' . dirname(__DIR__) . '/data/db.sqlite', // Set to your path or db config
    'cache_path' => dirname(__DIR__) . '/data/cache',
    'log_path' => dirname(__DIR__) . '/data/logs/app.log',
    'modules_path' => dirname(__DIR__) . '/extensions/modules',
    'plugins_path' => dirname(__DIR__) '/extensions/plugins',
    'debug' => true,  // Set to false in production
]);
```

## Production Deployment

### 1. Enable OPcache

```ini
; php.ini
opcache.enable=1
opcache.memory_consumption=128
opcache.interned_strings_buffer=8
opcache.max_accelerated_files=10000
opcache.validate_timestamps=0  ; Disable in production
```

### 2. Disable Debug Mode

```php
'debug' => false
```

### 3. Set Proper Permissions

```bash
chmod -R 755 public/
chmod -R 777 data/cache data/logs
```

### 4. Configure Web Server

**Apache (.htaccess)**
```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [L]
```

**Nginx**
```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}

location ~ \.php$ {
    fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
    fastcgi_index index.php;
    include fastcgi_params;
}
```

## Performance Tips

1. **Use OPcache** - Provides 2-3x performance boost
2. **Keep manifests simple** - Discovery is cached but initial scan matters
3. **Minimize plugin chains** - Each plugin adds overhead
4. **Use static routes** - Faster than dynamic or regex routes
5. **Disable discovery cache in dev** - Pass `true` to `Scanner::scan(true)`

## Advanced Patterns

### Middleware Chain

```php
// AuthPlugin.php
public function execute(Context $ctx, Services $s): ?Response
{
    // Authenticate and continue
    $ctx->provide('user', $user);
    return null;  // Continue chain
}

// LoggerPlugin.php
public function execute(Context $ctx, Services $s): ?Response
{
    // Log and continue
    $s->resolve('logger')->log('info', 'Request started');
    return null;
}

// ControllerPlugin.php
public function execute(Context $ctx, Services $s): ?Response
{
    $user = $ctx->consume('user');
    // Handle request
    return Response::json(['user' => $user->name]);
}
```

Ensure proper `dependsOn` in manifests to maintain order.

### Multiple Providers

```php
// Module A provides "cache" with score 100
// Module B provides "cache" with score 150 (Redis)

// The kernel will choose Module B automatically, similar effect applies to plugins
```

### Optional Dependencies

```php
public function execute(Context $ctx, Services $s): ?Response
{
    if (in_array('premium.feature', $this->missing)) { // Plugin capabilities are treated as optional dependencies, make sure to check missing array
        return Response::html('Feature not available');
    }
    
    // Use premium feature
}
```

## Troubleshooting

### Routes Not Found
- Check manifest.json syntax
- Verify routes array structure
- Clear cache: `rm -rf data/cache/*`
- Enable debug mode and check error output

### Module Not Loading
- Verify `Module.php` exists in module directory
- Check class name matches manifest
- Ensure namespace is `Framework\Core`
- Check boot() method sets `$this->booted = true`
- Check dependencies, ensure it does not depend on plugin capability

### Circular Dependencies
- Review `dependsOn` chains
- Use `requires` for modules capabilities and `dependsOn` for plugin capabilities
- Error message shows the cycle

### Performance Issues
- Enable OPcache
- Check discovery cache is working (logs should show cache hits)
- Profile with Xdebug to find bottlenecks
- Reduce plugin chain length

## Testing

Run the included example:

```bash
php -S localhost:8000 -t public
```

Visit `http://localhost:8000/hello/World`

## Contributing

Contributions are welcome! Please:

1. Fork the repository
2. Create a feature branch
3. Write tests for new functionality
4. Submit a pull request

## License

MIT License - feel free to use in commercial and open-source projects.

## Credits

Created as a minimalist alternative to heavyweight PHP frameworks. Inspired by microkernel architecture patterns and modern dependency injection containers.

## Philosophy

Super Micro embodies these principles:

- **Simplicity** - One file, zero dependencies, readable code
- **Modularity** - Everything is a plugin or module
- **Performance** - Cache everything, lazy-load everything
- **Flexibility** - Convention over configuration, but configurable when needed
- **Transparency** - You can read and understand the entire framework in an hour

---

**Built with ❤️¸ for developers who want power without bloat**