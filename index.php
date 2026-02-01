<?php
declare(strict_types=1);

// ============================================================================
// HTTP LAYER - Request/Response handling
// ============================================================================
namespace Framework\Http {
    /**
     * Immutable HTTP request representation
     * Captures and normalizes incoming HTTP requests
     */
    readonly class Request
    {
        public function __construct(
            public string $uri,
            public string $method,
            public array $params = [],
            public array $body = [],
            public array $headers = [],
            public array $attributes = []
        ) {
        }

        /**
         * Create Request from PHP globals ($_SERVER, $_GET, $_POST)
         */
        public static function capture(): self
        {
            // Extract HTTP headers from $_SERVER (they start with HTTP_)
            $headers = (array_combine(
                array_map(
                    fn($k) => str_replace("_", "-", substr($k, 5)),
                    array_filter(
                        array_keys($_SERVER),
                        fn($k) => str_starts_with($k, "HTTP_")
                    )
                ),
                array_filter(
                    $_SERVER,
                    fn($_, $k) => str_starts_with($k, "HTTP_"),
                    ARRAY_FILTER_USE_BOTH
                )
            )) ?: [];
            
            return new self(
                parse_url($_SERVER["REQUEST_URI"] ?? "/", PHP_URL_PATH),
                $_SERVER["REQUEST_METHOD"] ?? "GET",
                $_GET,
                $_POST,
                $headers
            );
        }

        /**
         * Get value from params, body, or attributes (in that order)
         */
        public function get(string $k, mixed $d = null): mixed
        {
            return $this->params[$k] ??
                ($this->body[$k] ?? ($this->attributes[$k] ?? $d));
        }

        /**
         * Get HTTP header value (case-insensitive)
         */
        public function header(string $n): ?string
        {
            return $this->headers[strtoupper($n)] ?? null;
        }

        /**
         * Create new Request with added/modified property
         * Maintains immutability by returning new instance
         */
        public function with(
            string $prop,
            string|array $key,
            mixed $val = null
        ): self {
            $data = get_object_vars($this);
            is_array($key)
                ? ($data[$prop] = [...$data[$prop], ...$key])
                : ($data[$prop][$key] = $val);
            return new self(...$data);
        }
    }

    /**
     * Immutable HTTP response representation
     * Encapsulates content, status code, and headers
     */
    readonly class Response
    {
        public function __construct(
            public string $content = "",
            public int $status = 200,
            public array $headers = []
        ) {
        }

        /**
         * Create JSON response
         */
        public static function json(mixed $d, int $s = 200): self
        {
            return new self(json_encode($d, JSON_THROW_ON_ERROR), $s, [
                "Content-Type" => "application/json",
            ]);
        }

        /**
         * Create HTML response
         */
        public static function html(string $c, int $s = 200): self
        {
            return new self($c, $s, [
                "Content-Type" => "text/html; charset=UTF-8",
            ]);
        }

        /**
         * Create redirect response
         */
        public static function redirect(string $u, int $s = 302): self
        {
            return new self("", $s, ["Location" => $u]);
        }

        /**
         * Send response to client
         * Sets headers, outputs content, and flushes buffers
         */
        public function send(): void
        {
            if (!headers_sent()) {
                http_response_code($this->status);
                foreach ($this->headers as $k => $v) {
                    header("$k: $v");
                }
            }
            echo $this->content;
            
            // Flush output based on available functions
            match (true) {
                function_exists("fastcgi_finish_request") => fastcgi_finish_request(),
                function_exists("litespeed_finish_request") => litespeed_finish_request(),
                default => (ob_get_level() > 0 && ob_end_flush()) || flush(),
            };
        }
    }
}

// ============================================================================
// LOGGING - Structured logging with file output
// ============================================================================
namespace Framework\Log {
    interface Logger
    {
        public function log(string $level, string $msg, array $ctx = []): void;
    }

    /**
     * File-based logger implementation
     * Writes timestamped log entries to a file
     */
    final class FileLogger implements Logger
    {
        public function __construct(private string $file)
        {
            // Create directory if it doesn't exist
            is_dir($d = dirname($file)) || @mkdir($d, 0777, true);
        }

        /**
         * Write log entry to file
         * Format: [timestamp] LEVEL: message {context}
         */
        public function log(string $level, string $msg, array $ctx = []): void
        {
            $entry = sprintf(
                "[%s] %s: %s %s\n",
                date("Y-m-d H:i:s"),
                strtoupper($level),
                $msg,
                $ctx ? json_encode($ctx) : ""
            );
            
            // Write to file or fallback to error_log
            file_put_contents($this->file, $entry, FILE_APPEND) ?:
            error_log("Log fallback: $entry");
        }
    }
}

// ============================================================================
// CACHING - File-based cache with OPcache integration
// ============================================================================
namespace Framework\Cache {
    interface Driver
    {
        public function get(string $k): mixed;
        public function set(string $k, mixed $v, int $ttl = 3600): bool;
        public function delete(string $k): bool;
    }

    /**
     * PHP file-based cache driver
     * Stores data as PHP arrays, leveraging OPcache for performance
     */
    final class PhpDriver implements Driver
    {
        public function __construct(private string $dir)
        {
            is_dir($dir) ||
                @mkdir($dir, 0755, true) ||
                throw new \RuntimeException("Cannot create: $dir");
        }

        /**
         * Generate cache file path from key (MD5 hash)
         */
        private function path(string $k): string
        {
            return "{$this->dir}/" . md5($k) . ".php";
        }

        /**
         * Retrieve cached value
         * Returns null if not found or expired
         */
        public function get(string $k): mixed
        {
            if (!file_exists($f = $this->path($k))) {
                return null;
            }
            
            try {
                $d = require $f;
            } catch (\Throwable) {
                return null;
            }
            
            // Check expiration and return value or delete if expired
            return is_array($d) && ($d["exp"] === 0 || $d["exp"] >= time())
                ? $d["val"]
                : ($this->delete($k) ?: null);
        }

        /**
         * Store value in cache
         * TTL of 0 means no expiration
         */
        public function set(string $k, mixed $v, int $ttl = 3600): bool
        {
            $f = $this->path($k);
            $tmp = "$f." . uniqid() . ".tmp";
            
            // Write to temp file first, then atomic rename
            $ok =
                file_put_contents(
                    $tmp,
                    "<?php return " .
                        var_export(
                            [
                                "val" => $v,
                                "exp" => $ttl > 0 ? time() + $ttl : 0,
                            ],
                            true
                        ) .
                        ";"
                ) && rename($tmp, $f);
            
            // Invalidate OPcache for this file
            $ok &&
                function_exists("opcache_invalidate") &&
                @opcache_invalidate($f, true);
            
            $ok || @unlink($tmp);
            return (bool) $ok;
        }

        /**
         * Delete cached value
         */
        public function delete(string $k): bool
        {
            $f = $this->path($k);
            file_exists($f) &&
                @unlink($f) &&
                function_exists("opcache_invalidate") &&
                @opcache_invalidate($f, true);
            return true;
        }
    }
}

// ============================================================================
// DISCOVERY - Automatic scanning and loading of modules/plugins
// ============================================================================
namespace Framework\Discovery {
    use Framework\Cache\Driver;

    /**
     * Module/Plugin definition from manifest.json
     * Contains metadata about routes, dependencies, and capabilities
     */
    readonly class Definition
    {
        public function __construct(
            public string $name,
            public string $class,
            public string $path,
            public array $routes = [],
            public array $provides = [],      // Capabilities this provides
            public array $requires = [],      // Module capabilities needed
            public array $dependsOn = [],     // Plugin dependencies
            public int $score = 100           // Provider priority (higher wins)
        ) {
        }

        public function toArray(): array
        {
            return get_object_vars($this);
        }

        public static function fromArray(array $d): self
        {
            return new self(
                $d["name"],
                $d["class"],
                $d["path"],
                $d["routes"] ?? [],
                $d["provides"] ?? [],
                $d["requires"] ?? [],
                $d["dependsOn"] ?? [],
                $d["score"] ?? 100
            );
        }
    }

    /**
     * Scans directories for manifest.json files
     * Builds and caches module/plugin definitions
     */
    final class Scanner
    {
        public function __construct(
            private string $dir,
            private ?Driver $cache = null,
            private string $key = "disc",
            private int $ttl = 86400
        ) {
        }

        /**
         * Scan directory for manifests
         * Returns cached results if available and not stale
         */
        public function scan(bool $force = false): array
        {
            $hash = $this->hash();
            
            // Return cached if valid
            if (
                !$force &&
                $this->cache &&
                ($c = $this->cache->get($this->key)) &&
                ($c["hash"] ?? "") === $hash
            ) {
                return array_map(Definition::fromArray(...), $c["data"]);
            }

            // Scan for manifest files
            $defs = [];
            foreach (glob("{$this->dir}/*/manifest.json") ?: [] as $f) {
                $j = json_decode(file_get_contents($f), true);
                if ($j && isset($j["name"], $j["className"])) {
                    $defs[$j["name"]] = new Definition(
                        $j["name"],
                        $j["className"],
                        dirname($f),
                        $j["routes"] ?? [],
                        $j["provides"] ?? [],
                        $j["requires"] ?? [],
                        $j["dependsOn"] ?? [],
                        $j["providerScore"] ?? 100
                    );
                }
            }

            // Cache results with hash for invalidation
            $this->cache?->set(
                $this->key,
                [
                    "data" => array_map(fn($d) => $d->toArray(), $defs),
                    "hash" => $hash,
                ],
                $this->ttl
            );
            
            return $defs;
        }

        /**
         * Generate hash of all manifest files (for cache invalidation)
         */
        private function hash(): string
        {
            return md5(
                implode(
                    "",
                    array_map(
                        fn($f) => $f . filemtime($f),
                        glob("{$this->dir}/*/manifest.json") ?: []
                    )
                )
            );
        }
    }
}

// ============================================================================
// ROUTING - URL pattern matching and parameter extraction
// ============================================================================
namespace Framework\Router {
    use Framework\Discovery\Definition;

    /**
     * HTTP router with static, dynamic, and regex route support
     * Routes requests to appropriate plugins
     */
    final class Router
    {
        private array $static = [],   // Fast lookup for static routes
            $dynamic = [],            // Routes with parameters
            $routes = [];             // All route definitions

        /**
         * Register routes from a plugin definition
         */
        public function add(Definition $def): void
        {
            foreach ($def->routes as $pat => $methods) {
                $methods = (array) $methods;
                $pat = is_int($pat) ? $methods[0] : $pat;
                [$regex, $params, $isStatic] = $this->compile($pat);
                
                $this->routes[] = $r = [
                    "pattern" => $pat,
                    "regex" => $regex,
                    "methods" => $methods,
                    "params" => $params,
                    "plugin" => $def->name,
                ];
                
                $idx = count($this->routes) - 1;
                
                // Store in static or dynamic index
                foreach ($methods as $m) {
                    $isStatic
                        ? ($this->static[$m][$pat] = $idx)
                        : ($this->dynamic[$m][] = $idx);
                }
            }
        }

        /**
         * Compile route pattern to regex
         * Returns [regex, param_names, is_static]
         */
        private function compile(string $p): array
        {
            // Already a regex pattern
            if (preg_match("/^[#~^]/", $p)) {
                return [$p, [], false];
            }
            
            // Static route (no parameters)
            if (!str_contains($p, "{")) {
                return [$p, [], true];
            }
            
            // Dynamic route - extract parameters
            $params = [];
            $regex = preg_replace_callback(
                "/\{(\w+)(?::([^}]+))?}/",
                fn($m) => ($params[] = $m[1])
                    ? "(" . ($m[2] ?? "[^/]+") . ")"
                    : "",
                $p
            );
            
            return ["#^{$regex}$#", $params, false];
        }

        /**
         * Match URI and method to a route
         * Returns route info and extracted parameters
         */
        public function match(string $uri, string $method): ?array
        {
            // Try static routes first (fastest)
            if (
                ($i =
                    $this->static[$method][$uri] ??
                    ($this->static["*"][$uri] ?? null)) !== null
            ) {
                return ["route" => $this->routes[$i], "params" => []];
            }
            
            // Try dynamic routes
            foreach (
                [...$this->dynamic[$method] ?? [], ...$this->dynamic["*"] ?? []]
                as $i
            ) {
                if (preg_match($this->routes[$i]["regex"], $uri, $m)) {
                    return [
                        "route" => $this->routes[$i],
                        "params" => array_combine(
                            $this->routes[$i]["params"],
                            array_slice($m, 1)
                        ),
                    ];
                }
            }
            
            return null;
        }
    }
}

// ============================================================================
// DEPENDENCY GRAPH - Topological sorting and provider resolution
// ============================================================================
namespace Framework\Graph {
    /**
     * Dependency graph resolver
     * Handles transitive dependencies and topological sorting
     */
    final class Resolver
    {
        private array $nodes = [],        // All registered nodes
            $providers = [];              // Capability -> providers map

        /**
         * Register a node with its dependencies and capabilities
         */
        public function add(
            string $id,
            array $deps,
            array $provides,
            mixed $data = null
        ): void {
            $this->nodes[$id] = ["deps" => $deps, "data" => $data];
            
            // Register as provider for each capability
            foreach ($provides as $c) {
                $this->providers[$c][] = $id;
            }
        }

        /**
         * Resolve dependencies into load order
         * Returns topologically sorted array and any missing dependencies
         */
        public function resolve(
            array $reqs,
            ?callable $scorer = null,
            bool $allowMissing = false
        ): array {
            $needed = $missing = [];
            $this->collect($reqs, $needed, $missing, $scorer, $allowMissing);

            // Build subgraph of only needed nodes
            $sub = [];
            foreach (array_keys($needed) as $id) {
                $resolved = [];
                foreach ($this->nodes[$id]["deps"] as $d) {
                    if (
                        ($p = $this->provider($d, $scorer)) &&
                        isset($needed[$p])
                    ) {
                        $resolved[] = $p;
                    } elseif ($allowMissing) {
                        $missing[] = $d;
                    }
                }
                $sub[$id] = array_unique($resolved);
            }

            return [
                "order" => $this->topo($sub),
                "missing" => array_unique($missing),
            ];
        }

        /**
         * Recursively collect all needed nodes
         */
        private function collect(
            array $reqs,
            array &$needed,
            array &$missing,
            ?callable $scorer,
            bool $allow
        ): void {
            foreach ($reqs as $r) {
                if (!($p = $this->provider($r, $scorer))) {
                    $allow
                        ? ($missing[] = $r)
                        : throw new \RuntimeException("Unmet: $r");
                    continue;
                }
                
                if (!isset($needed[$p])) {
                    $needed[$p] = true;
                    // Recursively collect dependencies
                    $this->collect(
                        $this->nodes[$p]["deps"],
                        $needed,
                        $missing,
                        $scorer,
                        $allow
                    );
                }
            }
        }

        /**
         * Find best provider for a capability
         * Uses scorer function if multiple providers exist
         */
        private function provider(string $c, ?callable $s): ?string {
            $p = $this->providers[$c] ?? [];

            if (count($p) === 0) return null;
            if (count($p) === 1) return $p[0];
    
            // Multiple providers - score them
            if (!$s) return $p[0];
    
            // Sort by score (higher is better)
            usort($p, function($a, $b) use ($s) {
                $scoreA = $s($this->nodes[$a]['data'] ?? null);
                $scoreB = $s($this->nodes[$b]['data'] ?? null);
                return $scoreB <=> $scoreA;
            });
    
            return $p[0];
        }

        /**
         * Topological sort using Kahn's algorithm
         * Detects circular dependencies
         */
        private function topo(array $nodes): array
        {
            // Calculate in-degree for each node
            $in = array_fill_keys($k = array_keys($nodes), 0);
            $adj = array_fill_keys($k, []);
            
            foreach ($nodes as $id => $deps) {
                foreach ($deps as $d) {
                    if (isset($in[$d])) {
                        $adj[$d][] = $id;
                        $in[$id]++;
                    }
                }
            }
            
            // Start with nodes that have no dependencies
            $q = array_keys(array_filter($in, fn($d) => $d === 0));
            $out = [];
            
            while ($q) {
                $u = array_shift($q);
                $out[] = $u;
                
                foreach ($adj[$u] as $v) {
                    if (--$in[$v] === 0) {
                        $q[] = $v;
                    }
                }
            }
            
            // If not all nodes processed, there's a cycle
            count($out) === count($nodes) ||
                throw new \RuntimeException("Circular dependency");
            
            return $out;
        }
    }
}

// ============================================================================
// CORE - Main framework classes
// ============================================================================
namespace Framework\Core {
    use Framework\Http\{Request, Response};
    use Framework\Discovery\{Definition, Scanner};
    use Framework\Graph\Resolver;
    use Framework\Router\Router;
    use Framework\Cache\Driver;
    use Framework\Log\Logger;

    /**
     * Base class for modules (shared services)
     * Modules are booted once and provide services to plugins
     */
    abstract class Module
    {
        protected bool $booted = false;
        
        abstract public function boot(Services $s, Config $c): void;
        abstract public function terminate(): void;
        
        public function isBooted(): bool
        {
            return $this->booted;
        }
    }

    /**
     * Base class for plugins (request handlers)
     * Plugins process requests and return responses
     */
    abstract class Plugin
    {
        protected array $missing = [];
        
        abstract public function execute(Context $ctx, Services $s): ?Response;
        
        public function validate(): bool
        {
            return true;
        }
        
        public function setMissing(array $m): void
        {
            $this->missing = $m;
        }
    }

    /**
     * Request context that flows through plugin chain
     * Allows plugins to share data and control execution flow
     */
    final class Context
    {
        private array $caps = [];          // Shared capabilities
        private bool $halted = false;      // Execution stopped?
        private ?Response $early = null;   // Early response (if halted)

        public function __construct(private Request $req)
        {
        }

        public function request(): Request
        {
            return $this->req;
        }

        /**
         * Add attribute to request (creates new immutable request)
         */
        public function attr(string $k, mixed $v): void
        {
            $this->req = $this->req->with("attributes", $k, $v);
        }

        /**
         * Provide capability for other plugins to consume
         */
        public function provide(string $k, mixed $v): void
        {
            $this->caps[$k] = $v;
        }

        /**
         * Consume capability provided by previous plugin
         */
        public function consume(string $k, mixed $d = null): mixed
        {
            return $this->caps[$k] ?? $d;
        }

        /**
         * Halt plugin chain execution with reason
         */
        public function halt(string $r, int $c = 403): void
        {
            $this->halted = true;
            $this->early = Response::html("<h1>Halted</h1><p>$r</p>", $c);
        }

        public function isHalted(): bool
        {
            return $this->halted;
        }

        public function earlyResponse(): ?Response
        {
            return $this->early;
        }
    }

    /**
     * Service container (singleton pattern)
     * Manages lazy-loaded service dependencies
     */
    final class Services
    {
        private static ?self $i = null;
        private array $r = [],             // Resolvers (factories)
            $c = [];                       // Cached instances

        public static function get(): self
        {
            return self::$i ??= new self();
        }

        /**
         * Bind service factory
         */
        public function bind(string $n, callable $f): void
        {
            $this->r[$n] = $f;
        }

        /**
         * Resolve service (creates singleton on first call)
         */
        public function resolve(string $n): object
        {
            return $this->c[$n] ??= ($this->r[$n] ??
                throw new \RuntimeException("Missing: $n"))();
        }
    }

    /**
     * Configuration container
     * Holds application settings
     */
    final class Config
    {
        public function __construct(private array $d = [])
        {
        }

        public function get(string $k, mixed $d = null): mixed
        {
            return $this->d[$k] ?? $d;
        }
    }

    /**
     * Registry for modules and plugins
     * Manages definitions, instances, and dependency resolution
     */
    final class Registry
    {
        private array $defs = [],          // Definitions by name
            $inst = [];                    // Cached instances
        public Resolver $graph;
        public ?Router $router;

        public function __construct(bool $routing)
        {
            $this->graph = new Resolver();
            $this->router = $routing ? new Router() : null;
        }

        /**
         * Register a definition in the registry
         */
        public function add(Definition $d): void
        {
            $this->defs[$d->name] = $d;
            $this->graph->add(
                $d->name,
                $d->dependsOn ?: $d->requires,
                $d->provides,
                $d
            );
            $this->router?->add($d);
        }

        /**
         * Get or create instance for a definition
         */
        public function instance(string $n): ?object
        {
            if (isset($this->inst[$n])) {
                return $this->inst[$n];
            }
            
            $d = $this->defs[$n] ?? null;
            if (
                !$d ||
                !file_exists(
                    $f =
                        "{$d->path}/" .
                        ($this->router ? "Plugin" : "Module") .
                        ".php"
                )
            ) {
                return null;
            }
            
            require_once $f;
            return class_exists($d->class)
                ? ($this->inst[$n] = new $d->class())
                : null;
        }

        public function def(string $n): ?Definition
        {
            return $this->defs[$n] ?? null;
        }

        /**
         * Build execution chain for a plugin
         * Returns chain order, required modules, and missing deps
         */
        public function chain(string $t): array
        {
            $d =
                $this->defs[$t] ?? throw new \RuntimeException("Not found: $t");
            
            // Resolve plugin dependencies
            $r = $this->graph->resolve(
                $d->dependsOn,
                fn($n) => $n->score,
                true
            );
            
            // Collect module requirements from entire chain
            $reqs = $d->requires;
            foreach ($r["order"] as $p) {
                $reqs = [...$reqs, ...$this->defs[$p]->requires];
            }
            
            return [
                "chain" => [...$r["order"], $t],
                "modules" => array_unique($reqs),
                "missing" => $r["missing"],
            ];
        }
    }

    /**
     * Main application kernel
     * Orchestrates discovery, routing, and request handling
     */
    final class Kernel
    {
        private Registry $mods, $plugs;
        private ?Driver $cache = null;
        private ?Logger $log = null;
        private array $booted = [];        // Track booted modules for cleanup

        public function __construct(private Config $cfg)
        {
            $this->mods = new Registry(false);   // Modules don't need routing
            $this->plugs = new Registry(true);   // Plugins use routing
        }

        public function setCache(Driver $c): void
        {
            $this->cache = $c;
        }

        public function setLogger(Logger $l): void
        {
            $this->log = $l;
        }

        /**
         * Discover and register all modules and plugins
         */
        public function discover(string $modDir, string $plgDir): void
        {
            foreach (
                (new Scanner($modDir, $this->cache, "disc_mod"))->scan()
                as $d
            ) {
                $this->mods->add($d);
            }
            
            foreach (
                (new Scanner($plgDir, $this->cache, "disc_plg"))->scan()
                as $d
            ) {
                $this->plugs->add($d);
            }
        }

        /**
         * Main request handling loop
         * 1. Gate (validate request)
         * 2. Route (find handler)
         * 3. Plan (resolve dependencies)
         * 4. Boot (initialize modules)
         * 5. Execute (run plugin chain)
         * 6. Respond (send output)
         * 7. Cleanup (terminate modules)
         */
        public function run(): void
        {
            $debug = $this->cfg->get("debug", false);
            
            try {
                // Validate incoming request
                $this->gate();
                
                // Capture request from globals
                $req = Request::capture();
                
                // Find matching route
                $match =
                    $this->plugs->router->match($req->uri, $req->method) ??
                    throw new \Exception("Not Found", 404);

                // Build or retrieve cached execution plan
                $plan =
                    $this->cache?->get(
                        $ck = "plan:{$req->method}:{$match["route"]["pattern"]}"
                    ) ??
                    (function () use ($match, $ck) {
                        $chain = $this->plugs->chain($match["route"]["plugin"]);
                        $plan = [
                            "handler" => $match["route"]["plugin"],
                            "plugins" => $chain["chain"],
                            "modules" => $this->mods->graph->resolve(
                                $chain["modules"]
                            )["order"],
                            "missing" => $chain["missing"],
                        ];
                        $this->cache?->set($ck, $plan);
                        return $plan;
                    })();

                // Add route parameters to request
                $req = $req->with("attributes", $match["params"]);
                
                // Boot required modules
                foreach ($plan["modules"] as $m) {
                    if ($mod = $this->mods->instance($m)) {
                        if (!$mod->isBooted()) {
                            $mod->boot(Services::get(), $this->cfg);
                            $this->booted[] = $mod;
                        }
                    }
                }

                // Execute plugin chain
                $ctx = new Context($req);
                $resp = null;
                
                foreach ($plan["plugins"] as $p) {
                    if (!($plg = $this->plugs->instance($p))) {
                        continue;
                    }
                    
                    $plg->setMissing($plan["missing"]);
                    $plg->validate() ||
                        throw new \Exception("Validation failed: $p");
                    
                    // Execute plugin, stop if response or halted
                    if (
                        ($resp = $plg->execute($ctx, Services::get())) ||
                        $ctx->isHalted()
                    ) {
                        $resp ??= $ctx->earlyResponse();
                        break;
                    }
                }
                
                // Send response
                ($resp ?? new Response("Internal Error", 500))->send();
                
            } catch (\Throwable $e) {
                // Determine HTTP status code
                $code =
                    $e->getCode() >= 400 && $e->getCode() < 600
                        ? $e->getCode()
                        : 500;
                
                // Log error
                $this->log?->log("ERROR", $e->getMessage(), [
                    "file" => $e->getFile(),
                    "line" => $e->getLine(),
                ]);
                
                // Send error response (verbose in debug mode)
                ($debug
                    ? Response::html(
                        "<h1>{$e->getMessage()}</h1><pre>{$e->getTraceAsString()}</pre>",
                        $code
                    )
                    : new Response("Internal Server Error", $code)
                )->send();
                
            } finally {
                // Terminate modules in reverse order
                array_walk(
                    array_reverse($this->booted),
                    fn($m) => $m->terminate()
                );
            }
        }

        /**
         * Validate incoming request (security gate)
         * Checks content length, method requirements, and format
         */
        private function gate(): void
        {
            $len = (int) ($_SERVER["CONTENT_LENGTH"] ?? 0);
            $type = $_SERVER["CONTENT_TYPE"] ?? "";
            $method = $_SERVER["REQUEST_METHOD"] ?? "GET";
            
            // Calculate max allowed payload
            $limit = min(
                2097152,  // 2MB hard limit
                (int) (preg_replace_callback(
                    "/^(\d+)([kmg])?/i",
                    fn($m) => $m[1] * 1024 ** stripos("bkmg", $m[2] ?? "b"),
                    ini_get("post_max_size")
                ) ?: PHP_INT_MAX)
            );

            // Validate payload size
            $len > $limit && throw new \Exception("Payload Too Large", 413);
            
            // Require Content-Length for POST/PUT/PATCH
            in_array($method, ["POST", "PUT", "PATCH"]) &&
                $len === 0 &&
                !str_contains(
                    $_SERVER["HTTP_TRANSFER_ENCODING"] ?? "",
                    "chunked"
                ) &&
                throw new \Exception("Length Required", 411);
            
            // Validate multipart boundary
            str_starts_with($type, "multipart/form-data") &&
                !str_contains($type, "boundary=") &&
                throw new \Exception("Malformed Multipart", 400);
        }
    }
}

// ============================================================================
// BOOTSTRAP - Application initialization
// ============================================================================
namespace {
    use Framework\Core\{Config, Kernel};
    use Framework\Cache\PhpDriver;
    use Framework\Log\FileLogger;

    // Configuration
    $cfg = new Config([
        "db_dsn" => "sqlite:" . dirname(__DIR__) . "/data/db.sqlite",
        "cache_path" => dirname(__DIR__) . "/data/cache",
        "log_path" => dirname(__DIR__) . "/data/logs/app.log",
        "modules_path" => dirname(__DIR__) . "/extensions/modules",
        "plugins_path" => dirname(__DIR__) . "/extensions/plugins",
        "debug" => true,
    ]);

    // Initialize kernel
    $k = new Kernel($cfg);
    $k->setCache(new PhpDriver($cfg->get("cache_path")));
    $k->setLogger(new FileLogger($cfg->get("log_path")));
    $k->discover($cfg->get("modules_path"), $cfg->get("plugins_path"));
    
    // Handle request
    $k->run();
}
