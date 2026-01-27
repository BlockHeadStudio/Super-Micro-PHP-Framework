<?php
declare(strict_types=1);

/**
 * SUPER MICRO
 * 
 * A lightweight, component-based PHP framework featuring:
 * - Immutable HTTP Message implementation
 * - Manifest-based Discovery (Modules/Plugins)
 * - Dependency Injection & Service Registry
 * - Topological Dependency Resolution
 * - Compiled Routing
 * - OPCache-optimized Caching
 */

// ---------------------------------------------------------
// COMPONENT: HTTP
// Handles Request/Response objects immutably.
// ---------------------------------------------------------
namespace Framework\Http {

    /**
     * Represents an incoming HTTP request.
     * This class is immutable; modification methods return a new instance.
     */
    final class Request
    {
        /**
         * @param string $uri The request URI path.
         * @param string $method The HTTP verb (GET, POST, etc).
         * @param array $params Query string parameters ($_GET).
         * @param array $body Parsed body parameters ($_POST).
         * @param array $headers HTTP Headers.
         * @param array $attributes Custom attributes attached during request lifecycle (e.g., route params).
         */
        public function __construct(
            private string $uri,
            private string $method,
            private array $params = [],
            private array $body = [],
            private array $headers = [],
            private array $attributes = []
        ) {}

        /**
         * Captures global PHP variables ($_SERVER, $_GET, $_POST) to create a Request instance.
         * Normalizes HTTP headers from $_SERVER.
         */
        public static function capture(): self
        {
            $headers = [];
            foreach ($_SERVER as $key => $value) {
                if (str_starts_with($key, "HTTP_")) {
                    $headers[str_replace("_", "-", substr($key, 5))] = $value;
                }
            }

            return new self(
                parse_url($_SERVER["REQUEST_URI"] ?? "/", PHP_URL_PATH),
                $_SERVER["REQUEST_METHOD"] ?? "GET",
                $_GET,
                $_POST,
                $headers
            );
        }

        // Getters for request properties
        public function getUri(): string { return $this->uri; }
        public function getMethod(): string { return $this->method; }
        public function getParams(): array { return $this->params; }
        public function getBody(): array { return $this->body; }
        public function getHeaders(): array { return $this->headers; }
        public function getAttributes(): array { return $this->attributes; }

        // Helper accessors with default values
        public function getParam(string $key, mixed $default = null): mixed { return $this->params[$key] ?? $default; }
        public function getBodyParam(string $key, mixed $default = null): mixed { return $this->body[$key] ?? $default; }
        public function getHeader(string $name): ?string { return $this->headers[strtoupper($name)] ?? null; }
        public function getAttribute(string $key, mixed $default = null): mixed { return $this->attributes[$key] ?? $default; }

        // PSR-7 style "With" methods (Immutable setters)
        public function withUri(string $uri): self { $c = clone $this; $c->uri = $uri; return $c; }
        public function withMethod(string $method): self { $c = clone $this; $c->method = strtoupper($method); return $c; }
        public function withParam(string $key, mixed $value): self { return $this->cloneWith('params', $key, $value); }
        public function withBodyParam(string $key, mixed $value): self { return $this->cloneWith('body', $key, $value); }
        public function withHeader(string $name, string $value): self { return $this->cloneWith('headers', strtoupper($name), $value); }
        public function withAttribute(string $key, mixed $value): self { return $this->cloneWith('attributes', $key, $value); }
        
        /**
         * Merges multiple attributes at once.
         */
        public function withAttributes(array $attributes): self { 
            $c = clone $this; 
            $c->attributes = array_merge($c->attributes, $attributes); 
            return $c; 
        }

        /**
         * Internal helper to clone the object and modify a specific array property.
         */
        private function cloneWith(string $prop, string $key, mixed $val): self {
            $c = clone $this; 
            $c->{$prop}[$key] = $val; 
            return $c;
        }
    }

    /**
     * Represents an outgoing HTTP response.
     */
    final class Response
    {
        public function __construct(
            private string $content = "",
            private int $statusCode = 200,
            private array $headers = []
        ) {}

        /** Factory for JSON responses */
        public static function json(mixed $data, int $status = 200): self
        {
            return new self(json_encode($data, JSON_THROW_ON_ERROR), $status, ["Content-Type" => "application/json"]);
        }

        /** Factory for HTML responses */
        public static function html(string $content, int $status = 200): self
        {
            return new self($content, $status, ["Content-Type" => "text/html; charset=UTF-8"]);
        }

        /** Factory for Redirects */
        public static function redirect(string $url, int $status = 302): self
        {
            return new self("", $status, ["Location" => $url]);
        }

        /**
         * Emits headers and content to the client.
         * Handles server-specific functions to close connections while keeping the process alive.
         */
        public function send(): void
        {
            if (!headers_sent()) {
                http_response_code($this->statusCode);
                foreach ($this->headers as $key => $value) {
                    header("{$key}: {$value}");
                }
            }
            echo $this->content;
            
            // Attempt to close connection to client immediately so background tasks can continue
            match (true) {
                function_exists("fastcgi_finish_request") => fastcgi_finish_request(),
                function_exists("litespeed_finish_request") => litespeed_finish_request(),
                default => (ob_get_level() > 0 ? ob_end_flush() : null) . flush()
            };
        }
    }
}

// ---------------------------------------------------------
// COMPONENT: LOGGING
// Simple PSR-style logging interface and implementation.
// ---------------------------------------------------------
namespace Framework\Log {
    interface LoggerInterface {
        public function log(string $level, string $message, array $context = []): void;
        public function error(string $message, array $context = []): void;
    }

    /**
     * Logs messages to a local file.
     */
    final class FileLogger implements LoggerInterface
    {
        /**
         * @param string $logFile Absolute path to the log file. Creates directory if missing.
         */
        public function __construct(private string $logFile) {
            $dir = dirname($logFile);
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
        }

        /**
         * writes a formatted log entry to the file. Falls back to error_log on failure.
         */
        public function log(string $level, string $message, array $context = []): void {
            $date = date('Y-m-d H:i:s');
            $ctxJson = !empty($context) ? json_encode($context, JSON_UNESCAPED_SLASHES) : '';
            $entry = sprintf("[%s] %s: %s %s%s", $date, strtoupper($level), $message, $ctxJson, PHP_EOL);
            
            if (file_put_contents($this->logFile, $entry, FILE_APPEND) === false) {
                error_log("Framework Fallback Log: $level - $message $ctxJson");
            }
        }

        public function error(string $message, array $context = []): void {
            $this->log('ERROR', $message, $context);
        }
    }
}

// ---------------------------------------------------------
// COMPONENT: DISCOVERY UTILS
// Helpers for serializing discovery objects.
// ---------------------------------------------------------
namespace Framework\Discovery {
    trait SerializableTrait {
        /** Reconstructs object from array (used during cache hydration) */
        public static function fromArray(array $data): self {
            return new self(...$data);
        }
        /** Converts object to array (used for cache storage) */
        public function toArray(): array {
            return get_object_vars($this);
        }
    }
}

// ---------------------------------------------------------
// COMPONENT: DEFINITIONS
// DTOs that represent the metadata of Plugins and Modules found on disk.
// ---------------------------------------------------------
namespace Framework\Discovery {
    use Framework\Base\{AbstractPlugin, AbstractModule};

    /**
     * Holds metadata for a discovered Plugin (Routes, Capabilities, ClassName).
     */
    final class PluginDefinition
    {
        private bool $loaded = false;

        public function __construct(
            public readonly string $name,
            public readonly string $className,
            public readonly string $dirPath, // Path to directory containing Plugin.php
            public readonly array $routes,
            public readonly array $provides,
            public readonly array $requires,
            public readonly array $dependsOn,
            public readonly int $providerScore = 100
        ) {}

        public static function fromArray(array $data): self {
            return new self(
                $data['name'],
                $data['className'],
                $data['dirPath'],
                $data['routes'] ?? [],
                $data['provides'] ?? [],
                $data['requires'] ?? [],
                $data['dependsOn'] ?? [],
                $data['providerScore'] ?? 100
            );
        }

        public function toArray(): array {
            return [
                'name' => $this->name,
                'className' => $this->className,
                'dirPath' => $this->dirPath,
                'routes' => $this->routes,
                'provides' => $this->provides,
                'requires' => $this->requires,
                'dependsOn' => $this->dependsOn,
                'providerScore' => $this->providerScore
            ];
        }

        /**
         * lazy-loads the PHP file and returns the Plugin instance.
         */
        public function instantiate(): ?AbstractPlugin {
            if (!$this->loaded) { 
                $file = $this->dirPath . '/Plugin.php';
                if (!file_exists($file)) return null;
                require_once $file; 
                $this->loaded = true; 
            }
            
            if (!class_exists($this->className)) return null;
            $instance = new ($this->className)();
            
            if (!$instance instanceof AbstractPlugin) {
                return null;
            }
            return $instance;
        }
    }

    /**
     * Holds metadata for a discovered Module (Service Provider).
     */
    final class ModuleDefinition
    {
        private bool $loaded = false;
        private ?AbstractModule $instance = null;

        public function __construct(
            public readonly string $name,
            public readonly string $className,
            public readonly string $dirPath, // Path to directory containing Module.php
            public readonly array $provides,
            public readonly array $requires
        ) {}

        public static function fromArray(array $data): self {
            return new self(
                $data['name'], 
                $data['className'], 
                $data['dirPath'], 
                $data['provides'] ?? [], 
                $data['requires'] ?? []
            );
        }
        
        public function toArray(): array {
            return [
                "name" => $this->name, 
                "className" => $this->className, 
                "dirPath" => $this->dirPath, 
                "provides" => $this->provides, 
                "requires" => $this->requires
            ];
        }

        /**
         * Lazy-loads the PHP file and returns a singleton instance of the Module.
         */
        public function instantiate(): AbstractModule {
            if ($this->instance) return $this->instance;
            
            if (!$this->loaded) { 
                $file = $this->dirPath . '/Module.php';
                if (!file_exists($file)) throw new \RuntimeException("Module file missing: $file");
                require_once $file; 
                $this->loaded = true; 
            }

            if (!class_exists($this->className)) throw new \RuntimeException("Module class '{$this->className}' not found");
            $instance = new ($this->className)();

            if (!$instance instanceof AbstractModule) {
                throw new \RuntimeException("Class '{$this->className}' must extend AbstractModule");
            }
            
            return $this->instance = $instance;
        }

        public function setInstance(AbstractModule $instance): void { $this->instance = $instance; $this->loaded = true; }
        public function isLoaded(): bool { return $this->loaded; }
        public function isInstantiated(): bool { return $this->instance !== null; }
    }
}

// ---------------------------------------------------------
// COMPONENT: DISCOVERY ENGINE
// Scans directories, parses valid manifests, and caches the results.
// ---------------------------------------------------------
namespace Framework\Discovery {
    use Framework\Cache\CacheDriverInterface;

    /**
     * Base class for scanning and caching definitions.
     */
    abstract class AbstractDiscovery
    {
        public function __construct(
            protected string $directory,
            protected ?CacheDriverInterface $cache = null,
            protected string $cacheKey = "discovery",
            protected int $cacheTtl = 86400
        ) {}

        abstract protected function performColdBoot(): array;
        abstract protected function hydrate(array $data): array;

        /**
         * Main entry point: tries cache first, then falls back to file-scanning (cold boot).
         */
        public function discover(bool $forceCold = false): array
        {
            if (!$forceCold && $this->cache) {
                $cached = $this->cache->get($this->cacheKey);
                if ($cached && $this->validateCache($cached)) {
                    return $this->hydrate($cached["data"]);
                }
            }

            $definitions = $this->performColdBoot();
            $this->cacheDiscovery($definitions);
            return $definitions;
        }

        public function invalidate(): void { $this->cache?->delete($this->cacheKey); }

        /** Calculates a hash of file paths + mtimes to detect changes on disk. */
        protected function computeHash(array $patterns): string {
            $data = "";
            foreach ($patterns as $pattern) {
                foreach (glob($pattern) ?: [] as $file) { $data .= $file . filemtime($file); }
            }
            return md5($data);
        }

        /** Saves the discovered definitions to the cache driver. */
        protected function cacheDiscovery(array $definitions): void {
            if (!$this->cache) return;
            $this->cache->set($this->cacheKey, [
                "data" => array_map(fn($d) => $d->toArray(), $definitions),
                "timestamp" => time(),
                "hash" => $this->getCurrentHash(),
            ], $this->cacheTtl);
        }

        protected function validateCache(array $cached): bool {
            return isset($cached["hash"]) && $cached["hash"] === $this->getCurrentHash();
        }

        abstract protected function getCurrentHash(): string;
    }

    /**
     * Discovery implementation for Modules (Extensions/Services).
     */
    final class ModuleDiscovery extends AbstractDiscovery
    {
        /** Scans directories for manifest.json and creates ModuleDefinitions. */
        protected function performColdBoot(): array {
            if (!is_dir($this->directory)) return [];
            $definitions = [];
            
            $files = glob($this->directory . "/*/manifest.json") ?: [];
            
            foreach ($files as $file) {
                $json = json_decode(file_get_contents($file), true);
                if (!$json || !isset($json['name'], $json['className'])) continue;

                $definitions[$json['name']] = new ModuleDefinition(
                    $json['name'],
                    $json['className'],
                    dirname($file), 
                    $json['provides'] ?? [],
                    $json['requires'] ?? []
                );
            }
            return $definitions;
        }

        protected function hydrate(array $data): array {
            return array_map(fn($d) => ModuleDefinition::fromArray($d), $data);
        }

        protected function getCurrentHash(): string {
            return $this->computeHash([$this->directory . "/*/manifest.json"]);
        }
    }

    /**
     * Discovery implementation for Plugins (Route Endpoints).
     */
    final class PluginDiscovery extends AbstractDiscovery
    {
        /** Scans directories for manifest.json and creates PluginDefinitions. */
        protected function performColdBoot(): array {
            if (!is_dir($this->directory)) return [];
            $definitions = [];

            $files = glob($this->directory . "/*/manifest.json") ?: [];

            foreach ($files as $file) {
                $json = json_decode(file_get_contents($file), true);
                if (!$json || !isset($json['name'], $json['className'])) continue;

                $definitions[$json['name']] = new PluginDefinition(
                    $json['name'],
                    $json['className'],
                    dirname($file),
                    $json['routes'] ?? [],
                    $json['provides'] ?? [],
                    $json['requires'] ?? [],
                    $json['dependsOn'] ?? [],
                    $json['providerScore'] ?? 100
                );
            }
            return $definitions;
        }

        protected function hydrate(array $data): array {
            return array_map(fn($d) => PluginDefinition::fromArray($d), $data);
        }

        protected function getCurrentHash(): string {
            return $this->computeHash([$this->directory . "/*/manifest.json"]);
        }
    }
}

// ---------------------------------------------------------
// COMPONENT: ROUTER
// Compiles routes from plugins into regex/static maps for fast matching.
// ---------------------------------------------------------
namespace Framework\Router {
    /**
     * Value object representing a single compiled route.
     */
    final readonly class Route
    {
        use \Framework\Discovery\SerializableTrait;
        public function __construct(
            public string $pattern,
            public string $regex,
            public array $methods,
            public array $paramNames,
            public string $pluginName
        ) {}
    }

    /**
     * Handles route compilation and matching.
     */
    final class CompiledRouter
    {
        private array $routes = [];
        private array $staticRoutes = []; // O(1) Lookup for routes without params
        private array $dynamicRoutes = []; // Regex iteration for routes with params

        /**
         * Ingests a PluginDefinition and compiles its route patterns.
         */
        public function addFromDefinition(\Framework\Discovery\PluginDefinition $def): void
        {
            foreach ($def->routes as $pattern => $methods) {
                $methods = (array)$methods;
                $compiled = $this->compilePattern(is_int($pattern) ? $methods[0] : $pattern);

                $this->routes[] = new Route(
                    $pattern, $compiled["regex"], $methods, $compiled["params"], $def->name
                );
                $index = count($this->routes) - 1;

                foreach ($methods as $method) {
                    if ($compiled["static"]) {
                        $this->staticRoutes[$method][$pattern] = $index;
                    } else {
                        $this->dynamicRoutes[$method][] = $index;
                    }
                }
            }
        }

        /**
         * Transforms path patterns (e.g., /user/{id}) into Regex.
         */
        private function compilePattern(string $pattern): array
        {
            // If it starts with regex delimiters, treat as raw regex
            if (preg_match("/^[#~^]/", $pattern)) return ["regex" => $pattern, "params" => [], "static" => false];
            // If no curly braces, it's a static string matching
            if (!str_contains($pattern, "{")) return ["regex" => $pattern, "params" => [], "static" => true];

            $params = [];
            $regex = preg_replace_callback(
                "/\{(\w+)(?::([^}]+))?\}/",
                function ($m) use (&$params) { $params[] = $m[1]; return "(" . ($m[2] ?? "[^/]+") . ")"; },
                $pattern
            );

            return ["regex" => "#^" . $regex . '$#', "params" => $params, "static" => false];
        }

        /**
         * Finds a matching route for the URI and Method.
         */
        public function match(string $uri, string $method): ?array
        {
            // Try O(1) static lookup first
            $idx = $this->staticRoutes[$method][$uri] ?? $this->staticRoutes["*"][$uri] ?? null;
            if ($idx !== null) return ["route" => $this->routes[$idx], "params" => []];

            // Fallback to regex iteration
            $candidates = array_merge($this->dynamicRoutes[$method] ?? [], $this->dynamicRoutes["*"] ?? []);
            foreach ($candidates as $idx) {
                $route = $this->routes[$idx];
                if (preg_match($route->regex, $uri, $matches)) {
                    array_shift($matches);
                    return ["route" => $route, "params" => array_combine($route->paramNames, $matches)];
                }
            }
            return null;
        }
    }
}

// ---------------------------------------------------------
// COMPONENT: GRAPH UTILITIES
// Handles dependency resolution and sorting.
// ---------------------------------------------------------
namespace Framework\Graph {
    final readonly class Node {
        public function __construct(public string $id, public array $dependencies = [], public mixed $data = null) {}
    }

    /**
     * Represents dependencies between system components.
     * Can resolve abstract requirements (Capabilities) to concrete providers.
     */
    final class DependencyGraph
    {
        private array $nodes = [];
        private array $providers = []; // Map of Capability => [NodeIDs]

        /** Adds a node (Extension) and registers what it provides. */
        public function addNode(string $id, array $dependencies, array $provides, mixed $data = null): void {
            $this->nodes[$id] = new Node($id, $dependencies, $data);
            foreach ($provides as $cap) $this->providers[$cap][] = $id;
        }

        /**
         * Resolves dependencies and returns a topologically sorted list.
         */
        public function resolve(array $requirements, ?callable $scorer = null, bool $allowMissing = false): array
        {
            $needed = []; 
            $missing = [];
            // Recursively find all nodes needed to satisfy requirements
            $this->collect($requirements, $needed, $missing, $scorer, $allowMissing);

            // build a temporary subgraph of only the needed nodes
            $subgraph = [];
            foreach (array_keys($needed) as $id) {
                $node = $this->nodes[$id];
                $resolvedDeps = [];
                foreach ($node->dependencies as $dep) {
                    $p = $this->findProvider($dep, $scorer);
                    if ($p && isset($needed[$p])) $resolvedDeps[] = $p;
                    elseif ($allowMissing) $missing[] = $dep;
                }
                $subgraph[$id] = new Node($id, array_unique($resolvedDeps), $node->data);
            }

            return [
                "order" => (new TopologicalSorter())->sort($subgraph),
                "missing" => array_unique($missing)
            ];
        }

        /** Recursive helper to traverse dependencies. */
        private function collect(array $reqs, array &$needed, array &$missing, ?callable $scorer, bool $allowMissing): void {
            foreach ($reqs as $req) {
                $p = $this->findProvider($req, $scorer);
                if (!$p) {
                    if (!$allowMissing) throw new \RuntimeException("Unsatisfied dependency: $req");
                    $missing[] = $req; continue;
                }
                if (isset($needed[$p])) continue;
                $needed[$p] = true;
                $this->collect($this->nodes[$p]->dependencies, $needed, $missing, $scorer, $allowMissing);
            }
        }

        /** Finds the best provider for a capability, optionally using a scoring function. */
        private function findProvider(string $cap, ?callable $scorer): ?string {
            $c = $this->providers[$cap] ?? [];
            if (count($c) <= 1) return $c[0] ?? null;
            if (!$scorer) return $c[0];
            usort($c, fn($a, $b) => $scorer($this->nodes[$b]) <=> $scorer($this->nodes[$a]));
            return $c[0];
        }
    }

    /**
     * Sorts nodes so that dependencies always come before dependents.
     */
    final class TopologicalSorter {
        public function sort(array $nodes): array {
            $inDegree = array_fill_keys(array_keys($nodes), 0);
            $adj = array_fill_keys(array_keys($nodes), []);

            // Build Adjacency List
            foreach ($nodes as $id => $node) {
                foreach ($node->dependencies as $dep) {
                    if (isset($inDegree[$dep])) { $adj[$dep][] = $id; $inDegree[$id]++; }
                }
            }

            // Kahn's Algorithm
            $queue = [];
            foreach ($inDegree as $id => $d) if ($d === 0) $queue[] = $id;

            $sorted = [];
            while ($queue) {
                $u = array_shift($queue);
                $sorted[] = $u;
                foreach ($adj[$u] as $v) if (--$inDegree[$v] === 0) $queue[] = $v;
            }

            if (count($sorted) !== count($nodes)) throw new \RuntimeException("Circular dependency detected");
            return $sorted;
        }
    }
}

// ---------------------------------------------------------
// COMPONENT: CACHE (OPCache Optimized)
// ---------------------------------------------------------
namespace Framework\Cache {
    interface CacheDriverInterface {
        public function get(string $key): mixed;
        public function set(string $key, mixed $value, int $ttl = 3600): bool;
        public function delete(string $key): bool;
        public function clear(): bool;
    }

    /**
     * Stores cache items as native PHP files containing arrays.
     * This allows the OS to cache file reads and PHP's OPCache to cache the compiled array data.
     */
    final class PhpNativeDriver implements CacheDriverInterface
    {
        public function __construct(private string $dir) {
            if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
                throw new \RuntimeException("Cannot create cache dir: $dir");
            }
        }

        private function p(string $k): string { 
            return $this->dir . "/" . md5($k) . ".php"; 
        }

        public function get(string $key): mixed {
            $f = $this->p($key);
            if (!file_exists($f)) return null;

            try {
                $data = require $f;
            } catch (\Throwable) {
                return null;
            }

            if (!is_array($data) || ($data["exp"] !== 0 && $data["exp"] < time())) {
                $this->delete($key);
                return null;
            }
            return $data["val"];
        }

        public function set(string $key, mixed $val, int $ttl = 3600): bool {
            $f = $this->p($key);
            $tmp = $f . uniqid('', true) . '.tmp';
            
            $exp = $ttl > 0 ? time() + $ttl : 0;
            // Write a valid PHP file that returns the array
            $code = "<?php return " . var_export(["val" => $val, "exp" => $exp], true) . ";";
            
            if (file_put_contents($tmp, $code) === false) return false;
            
            // Atomic rename to ensure we don't read partial files
            if (rename($tmp, $f)) {
                // IMPORTANT: Tell PHP to re-compile this file if it was already cached in memory
                if (function_exists('opcache_invalidate')) {
                    @opcache_invalidate($f, true);
                }
                return true;
            }
            @unlink($tmp);
            return false;
        }

        public function delete(string $key): bool {
            $f = $this->p($key);
            if (file_exists($f)) {
                $res = @unlink($f);
                if ($res && function_exists('opcache_invalidate')) {
                    @opcache_invalidate($f, true);
                }
                return $res;
            }
            return true;
        }

        public function clear(): bool {
            $files = glob($this->dir . "/*.php");
            foreach ($files as $f) {
                @unlink($f);
                if (function_exists('opcache_invalidate')) @opcache_invalidate($f, true);
            }
            return true;
        }
    }
}

// ---------------------------------------------------------
// COMPONENT: CONTRACTS & BASE
// Base classes for the user to extend.
// ---------------------------------------------------------
namespace Framework\Base {
    use Framework\Core\{ServiceRegistry, ExecutionContext, Config};
    use Framework\Http\Response;

    /**
     * Base for Service Providers / Modules.
     * Used to register services into the container.
     */
    abstract class AbstractModule {
        protected bool $booted = false;
        abstract public function boot(ServiceRegistry $registry, Config $config): void;
        abstract public function terminate(): void;
        public function isBooted(): bool { return $this->booted; }
    }

    /**
     * Base for Logic / Route Handlers.
     * Used to execute business logic and return responses.
     */
    abstract class AbstractPlugin {
        protected array $missingCapabilities = [];
        abstract public function execute(ExecutionContext $ctx, ServiceRegistry $services): ?Response;
        public function validate(): bool { return true; }
        public static function getProviderScore(): int { return 100; }
        public function setMissing(array $caps): void { $this->missingCapabilities = $caps; }
    }
}

// ---------------------------------------------------------
// COMPONENT: CORE
// The glue that holds the system together.
// ---------------------------------------------------------
namespace Framework\Core {
    use Framework\Http\{Request, Response};
    use Framework\Discovery\{ModuleDefinition, PluginDefinition};
    use Framework\Graph\DependencyGraph;
    use Framework\Log\LoggerInterface;

    /**
     * A state object passed through the plugin execution chain.
     * Allows plugins to inspect the request, share data, or halt execution.
     */
    final class ExecutionContext {
        private array $caps = [];
        private bool $halted = false;
        private ?Response $earlyResponse = null;

        public function __construct(private Request $req) {}
        public function getRequest(): Request { return $this->req; }
        public function setRequestAttribute(string $k, mixed $v): void { $this->req = $this->req->withAttribute($k, $v); }
        public function provide(string $k, mixed $v): void { $this->caps[$k] = $v; }
        public function consume(string $k, mixed $default = null): mixed { return $this->caps[$k] ?? $default; }
        
        /** Stops the specific plugin chain and prepares an immediate response. */
        public function halt(string $reason, int $code = 403): void { 
            $this->halted = true; 
            $this->earlyResponse = new Response("<h1>Halted</h1><p>$reason</p>", $code); 
        }
        public function isHalted(): bool { return $this->halted; }
        public function getEarlyResponse(): ?Response { return $this->earlyResponse; }
    }

    /**
     * A Simple Service Locator (Singleton) for global access to booted Module services.
     */
    final class ServiceRegistry {
        private static ?self $instance = null;
        private array $resolvers = [];
        private array $instances = [];

        public static function getInstance(): self { return self::$instance ??= new self(); }
        public function bind(string $name, callable $resolver): void { $this->resolvers[$name] = $resolver; }
        public function get(string $name): object {
            return $this->instances[$name] ??= ($this->resolvers[$name] ?? throw new \RuntimeException("Service '$name' not found"))();
        }
    }

    /**
     * manages the set of discovered Definitions, the Node Graph, and the Router.
     */
    final class Registry {
        private array $definitions = [];
        private array $instances = [];
        private DependencyGraph $graph;
        private ?\Framework\Router\CompiledRouter $router = null;

        public function __construct(bool $isPluginRegistry) {
            $this->graph = new DependencyGraph();
            // Only Plugins need routing capabilities
            if ($isPluginRegistry) $this->router = new \Framework\Router\CompiledRouter();
        }

        /** Register definition and add it to the dependency graph */
        public function register($definition): void {
            $this->definitions[$definition->name] = $definition;
            $dependencies = $definition->requires;
            if ($definition instanceof PluginDefinition) {
                // Plugins depend on Modules (via 'requires') AND other Plugins (via 'dependsOn')
                $dependencies = array_merge($dependencies, $definition->dependsOn);
            }
            $this->graph->addNode($definition->name, $dependencies, $definition->provides, $definition);
            if ($this->router && $definition instanceof PluginDefinition) $this->router->addFromDefinition($definition);
        }

        public function get(string $name) {
            return $this->instances[$name] ??= $this->definitions[$name]?->instantiate();
        }

        public function getGraph(): DependencyGraph { return $this->graph; }
        public function getRouter(): ?\Framework\Router\CompiledRouter { return $this->router; }
        
        /** Resolves module load order based on capabilities */
        public function resolveModules(array $reqs): array {
            $res = $this->graph->resolve($reqs);
            return ["order" => $res["order"]];
        }

        /** Resolves a specific Plugin target and its entire dependency chain */
        public function resolvePluginChain(string $target): array {
            $def = $this->definitions[$target] ?? throw new \RuntimeException("Plugin '$target' not found");
            // Resolve plugin-to-plugin dependencies
            $res = $this->graph->resolve($def->dependsOn, fn($n) => $n->data->providerScore, true);
            
            // Collect all module requirements from the computed plugin chain
            $reqs = $def->requires;
            foreach($res["order"] as $p) $reqs = array_merge($reqs, $this->definitions[$p]->requires);

            return [
                "chain" => [...$res["order"], $target],
                "modules" => array_unique($reqs),
                "missing" => $res["missing"]
            ];
        }
    }

    /**
     * Determines execution plan without booting the system (can be cached).
     */
    final class Sandbox {
        public function __construct(private Registry $plugins, private Registry $modules) {}

        public function run(Request $request, ?\Framework\Cache\CacheDriverInterface $cache): array {
            $match = $this->plugins->getRouter()->match($request->getUri(), $request->getMethod());
            if (!$match) return ["found" => false];

            // Try to fetch the execution plan from cache based on route pattern
            $cacheKey = "plan:" . $request->getMethod() . ":" . $match["route"]->pattern;
            $plan = $cache?->get($cacheKey);

            if (!$plan) {
                // Determine which Plugins run (Middleware + Handler)
                $chain = $this->plugins->resolvePluginChain($match["route"]->pluginName);
                // Determine which Modules are needed by those plugins
                $modules = $this->modules->resolveModules($chain["modules"]);
                
                $plan = [
                    "handler" => $match["route"]->pluginName, 
                    "plugins" => $chain["chain"], 
                    "modules" => $modules["order"], 
                    "missing" => $chain["missing"]
                ];
                $cache?->set($cacheKey, $plan);
            }

            return ["found" => true, "plan" => $plan, "params" => $match["params"]];
        }
    }

    /** Simple configuration container. */
    final class Config {
        public function __construct(private array $s = []) {}
        public function get(string $k, mixed $d = null): mixed { return $this->s[$k] ?? $d; }
    }

    /**
     * The Heart of the Application.
     * Orchestrates checking the request, discovering extensions, planning execution, and booting.
     */
    final class Kernel
    {
        private Registry $modules;
        private Registry $plugins;
        private \Framework\Cache\CacheDriverInterface $cache;
        private ?LoggerInterface $logger = null;
        private array $booted = [];

        public function __construct(private Config $config) {
            $this->modules = new Registry(false);
            $this->plugins = new Registry(true);
        }

        public function setCache(\Framework\Cache\CacheDriverInterface $c): void { $this->cache = $c; }
        public function setLogger(LoggerInterface $l): void { $this->logger = $l; }

        /** Discovers all Modules and Plugins from disk. */
        public function discover(string $modDir, string $plgDir): void {
            $modDisc = new \Framework\Discovery\ModuleDiscovery($modDir, $this->cache, "disc_modules");
            foreach ($modDisc->discover() as $d) $this->modules->register($d);
            $plgDisc = new \Framework\Discovery\PluginDiscovery($plgDir, $this->cache, "disc_plugins");
            foreach ($plgDisc->discover() as $d) $this->plugins->register($d);
        }

        private function parseSize(string $size): int {
            $unit = preg_replace('/[^bkmgtpezy]/i', '', $size);
            $size = preg_replace('/[^0-9\.]/', '', $size); 
            return (int) ($size * pow(1024, stripos('bkmgtpezy', $unit[0] ?? 'b')));
        }

        /** Main execution method. */
        public function run(): void {
            $isDebug = $this->config->get("debug", false);
            
            try {
                // 1. Strict Request Gatekeeping (Security)
                $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
                $type = $_SERVER['CONTENT_TYPE'] ?? '';
                $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
                $appLimit = 2097152; // Hard limit 2MB
                $phpLimit = $this->parseSize(ini_get('post_max_size'));

                // Block oversize requests before processing
                if ($len > $appLimit || ($phpLimit > 0 && $len > $phpLimit)) {
                    throw new \Exception("Payload Too Large", 413);
                }

                // Block malformed POST/PUT requests
                if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
                    if ($len === 0 && !str_contains($_SERVER['HTTP_TRANSFER_ENCODING'] ?? '', 'chunked')) {
                        throw new \Exception("Length Required", 411);
                    }
                    if (str_starts_with($type, 'multipart/form-data') && !str_contains($type, 'boundary=')) {
                        throw new \Exception("Malformed Multipart Request", 400);
                    }
                }

                // 2. Routing and Planning
                $req = Request::capture();
                $sandbox = new Sandbox($this->plugins, $this->modules);
                
                $res = $sandbox->run($req, $this->cache);
                if (!$res["found"]) { (new Response("Not Found", 404))->send(); return; }

                $plan = $res["plan"];
                $req = $req->withAttributes($res["params"]);

                // 3. Boot Required Modules (Dependency Injection)
                foreach ($plan["modules"] as $mName) {
                    $m = $this->modules->get($mName);
                    if (!$m->isBooted()) { $m->boot(ServiceRegistry::getInstance(), $this->config); $this->booted[] = $m; }
                }

                // 4. Execute Plugin Chain (Middleware & Controller)
                $ctx = new ExecutionContext($req);
                $response = null;

                foreach ($plan["plugins"] as $pName) {
                    $p = $this->plugins->get($pName);

                    // If plugin is null just continue
                    if (!$p) continue;
                    $p->setMissing($plan["missing"]);
                    if (!$p->validate()) throw new \Exception("Plugin $pName failed validation");
                    
                    $out = $p->execute($ctx, ServiceRegistry::getInstance());
                    
                    // Stop if a plugin (middleware) halted execution
                    if ($ctx->isHalted()) { $response = $ctx->getEarlyResponse(); break; }
                    if ($out instanceof Response) { $response = $out; break; }
                }

                ($response ?? new Response("Internal Error", 500))->send();

            } catch (\Throwable $e) {
                // Error Handling
                $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
                
                if ($this->logger) {
                    $this->logger->error($e->getMessage(), [
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'trace' => $e->getTraceAsString()
                    ]);
                }

                if ($isDebug) {
                    $html = "<h1>Error: " . $e->getMessage() . "</h1>";
                    $html .= "<pre>" . $e->getTraceAsString() . "</pre>";
                    (new Response($html, $code))->send();
                } else {
                    (new Response("Internal Server Error", $code))->send();
                }
            } finally {
                // Cleanup
                foreach (array_reverse($this->booted) as $m) $m->terminate();
            }
        }
    }
}

// ---------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------
namespace {
    use Framework\Core\{Kernel, Config};
    use Framework\Cache\PhpNativeDriver;
    use Framework\Log\FileLogger;

    $config = new Config([
        "db_dsn" => "sqlite:" . dirname(__DIR__) . "/data/db.sqlite",
        "cache_path" => dirname(__DIR__) . "/data/cache",
        "log_path" => dirname(__DIR__) . "/data/logs/app.log",
        "modules_path" => dirname(__DIR__) . "/extensions/modules",
        "plugins_path" => dirname(__DIR__) . "/extensions/plugins",
        "debug" => true, 
    ]);

    $kernel = new Kernel($config);
    $kernel->setCache(new PhpNativeDriver($config->get("cache_path")));
    $kernel->setLogger(new FileLogger($config->get("log_path")));
    $kernel->discover($config->get("modules_path"), $config->get("plugins_path"));
    $kernel->run();
}