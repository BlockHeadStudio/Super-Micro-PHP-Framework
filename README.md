
# Super Micro PHP Framework

A lightweight, component-based PHP framework designed for flexibility and performance. It provides immutable HTTP message handling, dependency injection, and a modular approach to organizing plugins and services. 

## Features

- **Immutable HTTP Request/Response**: Uses immutable HTTP message objects.
- **Manifest-based Discovery**: Supports discovering plugins and modules dynamically via manifest files.
- **Dependency Injection**: Automatic service resolution and dependency injection for modules and plugins.
- **Topological Dependency Resolution**: Automatically resolves dependencies for modules and plugins.
- **Compiled Routing**: Fast route matching via compiled regular expressions.
- **OPCache-Optimized Caching**: Utilizes file-based caching that is optimized for OPCache.

## Table of Contents

- [Installation](#installation)
- [Directory Structure](#directory-structure)
- [Creating Plugins and Modules](#creating-plugins-and-modules)
  - [Plugin Structure](#plugin-structure)
  - [Module Structure](#module-structure)
  - [Example Plugin](#example-plugin)
  - [Example Module](#example-module)
- [Usage](#usage)
- [License](#license)

## Installation

To install the framework, simply clone the repository or download the files.

```bash
git clone https://github.com/BlockHeadStudio/Super-Micro-PHP-Framework
```

## Directory Structure

The basic directory structure is as follows:

```
/project-root
    /extensions
        /modules
            /your-module
                /Module.php
                /manifest.json
        /plugins
            /your-plugin
                /Plugin.php
                /manifest.json
    /data
        /cache
        /logs
        /db.sqlite
    /src
    index.php
```

### Key Components:

- `/extensions`: Contains all the modules and plugins.
  - `/modules`: Contains service provider modules.
  - `/plugins`: Contains route-handling plugins.
- `/data`: Stores cache, logs, and the SQLite database.

## Creating Plugins and Modules

### Plugin Structure

A **plugin** is used to define a route handler. It implements the `AbstractPlugin` class and can be executed during the request handling process.

A typical plugin has the following structure:

```php
// /extensions/plugins/my-plugin/Plugin.php
namespace MyPlugin;

use Framework\Base\AbstractPlugin;
use Framework\Http\Response;
use Framework\Core\ExecutionContext;
use Framework\Core\ServiceRegistry;

class MyPlugin extends AbstractPlugin {
    public function execute(ExecutionContext $ctx, ServiceRegistry $services): ?Response {
        // Business logic
        return new Response("Hello from MyPlugin");
    }
}
```

### Module Structure

A **module** is used to provide services or dependencies. It implements the `AbstractModule` class and registers services into the service container.

A typical module has the following structure:

```php
// /extensions/modules/my-module/Module.php
namespace MyModule;

use Framework\Base\AbstractModule;
use Framework\Core\ServiceRegistry;
use Framework\Core\Config;

class MyModule extends AbstractModule {
    public function boot(ServiceRegistry $registry, Config $config): void {
        // Register services, etc.
        $registry->bind('my-service', function() {
            return new MyService();
        });
    }

    public function terminate(): void {
        // Cleanup if necessary
    }
}
```

### Example Plugin

```php
// /extensions/plugins/hello-world/Plugin.php
namespace HelloWorldPlugin;

use Framework\Base\AbstractPlugin;
use Framework\Http\Response;
use Framework\Core\ExecutionContext;
use Framework\Core\ServiceRegistry;

class HelloWorldPlugin extends AbstractPlugin {
    public function execute(ExecutionContext $ctx, ServiceRegistry $services): ?Response {
        // Business logic
        return new Response("Hello, World!");
    }
}
```

### Example Module

```php
// /extensions/modules/database/Module.php
namespace DatabaseModule;

use Framework\Base\AbstractModule;
use Framework\Core\ServiceRegistry;
use Framework\Core\Config;
use PDO;

class DatabaseModule extends AbstractModule {
    public function boot(ServiceRegistry $registry, Config $config): void {
        // Register database connection service
        $registry->bind('db', function() use ($config) {
            return new PDO($config->get('db_dsn'));
        });
    }

    public function terminate(): void {
        // Close any resources if necessary
    }
}
```

### Manifest Files

Each plugin and module must include a `manifest.json` file that describes the capabilities and dependencies of the component.

Example **Plugin Manifest** (`/extensions/plugins/hello-world/manifest.json`):

```json
{
    "name": "HelloWorldPlugin",
    "className": "HelloWorldPlugin\HelloWorldPlugin",
    "routes": {
        "/hello": ["GET"]
    },
    "provides": [],
    "requires": [],
    "dependsOn": []
}
```

Example **Module Manifest** (`/extensions/modules/database/manifest.json`):

```json
{
    "name": "DatabaseModule",
    "className": "DatabaseModule\DatabaseModule",
    "provides": ["db"],
    "requires": []
}
```

## Usage

### Running the Framework

The `index.php` file boots the framework, discovers the modules and plugins, and runs the application.

```php
// index.php
use Framework\Core\{Kernel, Config};
use Framework\Cache\PhpNativeDriver;
use Framework\Log\FileLogger;

$config = new Config([
    "db_dsn" => "sqlite:" . dirname(__DIR__) . "/data/db.sqlite",
    "cache_path" => dirname(__DIR__) . "/data/cache",
    "log_path" => dirname(__DIR__) . "/data/logs/app.log",
    "modules_path" => dirname(__DIR__) . "/extensions/modules",
    "plugins_path" => dirname(__DIR__) . "/extensions/plugins",
    "debug" => true
]);

$kernel = new Kernel($config);
$kernel->setCache(new PhpNativeDriver($config->get("cache_path")));
$kernel->setLogger(new FileLogger($config->get("log_path")));
$kernel->discover($config->get("modules_path"), $config->get("plugins_path"));
$kernel->run();
```

### Handling Requests

When a request is made, the framework checks the routes defined in the plugins, resolves their dependencies, and processes them. It can also resolve and execute middlewares (if any).

```php
// Example of adding routes in a plugin:
$plugin = new HelloWorldPlugin();
$response = $plugin->execute($executionContext, $serviceRegistry);
$response->send();
```

## License

This framework is licensed under the MIT License.


## Contributing

We welcome contributions to the Super Micro PHP Framework! Whether it's fixing a bug, adding a new feature, or improving documentation, your help is appreciated. Please follow these guidelines when contributing:

### Reporting Issues

If you encounter an issue, please follow these steps:

1. Search for an existing issue to see if it has already been reported.
2. If you find an existing issue, add a comment with any additional information you may have.
3. If the issue doesn't exist, please open a new issue, providing:

   - A description of the problem.
   - Steps to reproduce the problem.
   - Expected and actual behavior.
   - Screenshots (if applicable).
   - Any error messages or logs.

### Submitting Code

If you're submitting a pull request (PR), please follow these steps:

1. **Fork the repository**: Create a personal fork of the repository.
2. **Create a new branch**: Branch off from the `main` branch. Give your branch a descriptive name.
3. **Commit your changes**: Write clear, concise commit messages that describe the purpose of the changes. Reference any related issues in your commits.
4. **Push your changes**: Push your branch to your forked repository.
5. **Create a pull request**: Open a PR from your fork to the `main` branch of the original repository. Provide a clear description of what the PR does and why it is needed.

### Documentation

Please make sure to update relevant documentation if your changes affect the framework's usage or setup. If you're adding a new feature, explain how it works and provide examples.

### Pull Request Reviews

After submitting a pull request, please be open to feedback. I may request changes before merging your PR. Once your PR is reviewed and approved, it will be merged.

### Code of Conduct

By contributing to this project, you agree to follow the project's code of conduct and be respectful and professional in all communications.

Thank you for contributing!
