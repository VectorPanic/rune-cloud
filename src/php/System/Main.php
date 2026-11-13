<?php

declare(strict_types=1);

namespace RuneCloud\System;

use RuneCloud\Http\FileResponse;
use RuneCloud\Http\Errors;
use RuneCloud\Http\HttpException;
use RuneCloud\Http\Router;
use RuneCloud\Http\Request;
use RuneCloud\Http\JsonResponse;
use RuneCloud\MySQL\MySQLi;
use RuneCloud\View\FileView;
use RuneCloud\View\JsonView;
use Throwable;

/**
 * Starts the application and composes its request-handling dependencies.
 */
final class Main
{
    //--------------------------------------------------------------------------
    // Public methods
    //--------------------------------------------------------------------------

    /**
     * Loads configuration, registers routes and handles the incoming request.
     *
     * @param string $method Incoming HTTP method.
     * @param string $uri Incoming request URI, including any query string.
     * @param string $basePath Application URL prefix, or an empty string at the web root.
     * @param Request|null $request Explicit input for protected routes.
     *
     * @return void
     */
    public function run(
        string $method,
        string $uri,
        string $basePath = '',
        ?Request $request = null
    ): void
    {
        try {
            $this->render($this->handle($method, $uri, $basePath, $request));
        } catch (Throwable $error) {
            $this->renderJson((new Errors())->response($error));
        }
    }

    //--------------------------------------------------------------------------
    // Private methods
    //--------------------------------------------------------------------------

    /**
     * Selects a route and returns its controller result.
     * @param string $method Incoming HTTP method.
     * @param string $uri Incoming request URI.
     * @param string $basePath Application URL prefix.
     * @param Request|null $request Explicit request input.
     * @return array<string, mixed>|FileResponse|JsonResponse
     * @throws \Throwable When initialization, routing or the handler fails.
     */
    private function handle(string $method, string $uri, string $basePath, ?Request $request): array|FileResponse|JsonResponse
    {
        $database = $this->connect();
        $router = $this->createRouter($database, $request);
        $path = $this->path($uri, $basePath);
        $handler = $router->match($method, $path);

        return $handler();
    }

    /**
     * Opens the database using the application configuration.
     * @return MySQLi Shared request connection.
     * @throws \Throwable When configuration or connection fails.
     */
    private function connect(): MySQLi
    {
        $config = require dirname(__DIR__, 3) . '/config/database.php';
        
        return new MySQLi(
            $config['host'],
            $config['username'],
            $config['password'],
            $config['database'],
            $config['port']
        );
    }

    /**
     * Registers configured application routes for the request.
     * @param MySQLi $database Shared request connection.
     * @param Request|null $request Explicit request input.
     * @return Router Configured router.
     * @throws \Throwable When configuration or route composition fails.
     */
    private function createRouter(MySQLi $database, ?Request $request): Router
    {
        $uploads = require dirname(__DIR__, 3) . '/config/uploads.php';
        $admin = $this->adminConfig();
        $setup = new Setup($database, $uploads, $admin['token'] ?? '', $admin['resetPasscode'] ?? '');
        $router = new Router();
        $setup->register($router, $request);

        return $router;
    }

    /**
     * Reads optional administrative credentials.
     * @return array<string, string> Administrative settings, or an empty array when unconfigured.
     */
    private function adminConfig(): array
    {
        $path = dirname(__DIR__, 3) . '/config/admin.php';
        return is_file($path) ? require $path : [];
    }

    /**
     * Extracts and normalizes the route path from the request URI.
     * @param string $uri Incoming request URI.
     * @param string $basePath Application URL prefix.
     * @return string Application-relative path without a trailing slash, except root.
     * @throws HttpException When the URI is malformed or outside the application.
     */
    private function path(string $uri, string $basePath): string
    {
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || !str_starts_with($path, '/')) {
            throw new HttpException('bad_request', 400);
        }

        $path = $this->relativePath($path, rtrim($basePath, '/'));

        return rtrim($path, '/') ?: '/';
    }

    /**
     * Removes the application prefix only when the request belongs to it.
     * @param string $path Absolute request path.
     * @param string $basePath Application prefix without its trailing slash.
     * @return string Application-relative path.
     * @throws HttpException When the request belongs to another application.
     */
    private function relativePath(string $path, string $basePath): string
    {
        if ($basePath === '') {
            return $path;
        }
        if ($path !== $basePath && !str_starts_with($path, $basePath . '/')) {
            throw new HttpException('not_found', 404);
        }

        return substr($path, strlen($basePath)) ?: '/';
    }

    /**
     * Selects the renderer for a controller result.
     * @param array<string, mixed>|FileResponse|JsonResponse $response Controller result.
     * @return void
     * @throws \Throwable When response preparation or rendering fails.
     */
    private function render(array|FileResponse|JsonResponse $response): void
    {
        if ($response instanceof FileResponse) {
            (new FileView())->render($response);
            return;
        }

        $this->renderJson($response instanceof JsonResponse ? $response : new JsonResponse($response));
    }

    /**
     * Sends JSON response headers and renders the complete body.
     * @param JsonResponse $response JSON data, HTTP status and headers.
     * @return void
     * @throws \JsonException When response data cannot be encoded.
     */
    private function renderJson(JsonResponse $response): void
    {
        foreach ($response->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        (new JsonView())->render($response->data, $response->status);
    }
}
