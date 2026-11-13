<?php

declare(strict_types=1);

namespace RuneCloud\Http;

use InvalidArgumentException;

/**
 * Dispatches requests using explicitly registered methods and paths.
 */
final class Router
{
    //--------------------------------------------------------------------------
    // Private properties
    //--------------------------------------------------------------------------

    /**
     * Maps paths and HTTP methods to handlers.
     *
     * @var array<string, array<string, callable>>>
     */
    private array $routes = [];

    //--------------------------------------------------------------------------
    // Public methods
    //--------------------------------------------------------------------------

    /**
     * Registers a handler for a method and path, optionally containing named segments.
     *
     * @param string $method HTTP method.
     * @param string $path Absolute request path.
     * @param callable $handler Handler receiving named path segments in declaration order.
     *
     * @return void
     * @throws InvalidArgumentException When a catch-all placeholder is not the last segment.
     */
    public function add(string $method, string $path, callable $handler): void
    {
        if (str_contains($path, '...}') && preg_match('/\{[a-zA-Z][a-zA-Z0-9_]*\.\.\.\}$/D', $path) !== 1) {
            throw new InvalidArgumentException('A catch-all parameter must be the final route segment.');
        }
        
        $this->routes[$path][strtoupper($method)] = $handler;
    }

    /**
     * Returns the matching handler without executing or rendering it.
     *
     * @param string $method Incoming HTTP method.
     * @param string $path Request path without its query string.
     *
     * @return callable(): (array<string, mixed>|FileResponse|JsonResponse)
     * @throws HttpException When the path or HTTP method does not match.
     */
    public function match(string $method, string $path): callable
    {
        $parameters = [];
        $handlers = $this->routes[$path] ?? null;
        
        if ($handlers === null) {
            foreach ($this->routes as $pattern => $candidates) {
                $parameters = $this->matchParameters($pattern, $path);
                
                if ($parameters !== null) {
                    $handlers = $candidates;
                    break;
                }
            }
        }
        
        if ($handlers === null) {
            throw new HttpException('not_found', 404);
        }
        
        $method = strtoupper($method);
        
        if (!isset($handlers[$method])) {
            throw new HttpException('method_not_allowed', 405, ['Allow' => implode(', ', array_keys($handlers))]);
        }
        
        $handler = $handlers[$method];
        
        return fn (): array|FileResponse|JsonResponse => $handler(...array_values($parameters));
    }

    //--------------------------------------------------------------------------
    // Private methods
    //--------------------------------------------------------------------------

    /**
     * Extracts named segments and an optional final {path...} catch-all parameter.
     *
     * @param string $pattern Registered path with placeholders such as {id}.
     * @param string $path Incoming path without its query string.
     *
     * @return array<string, string>|null Parameters, or null when the path does not match.
     */
    private function matchParameters(string $pattern, string $path): ?array
    {
        $expected = explode('/', $pattern);
        $actual = explode('/', $path);
        
        $catchAll = preg_match('/^\{([a-zA-Z][a-zA-Z0-9_]*)\.\.\.\}$/D', end($expected), $tail) === 1;
        
        if ((!$catchAll && count($expected) !== count($actual))
            || ($catchAll && count($actual) < count($expected))
        ) {
            return null;
        }
        
        $parameters = [];
        
        foreach ($expected as $index => $segment) {
            if ($catchAll && $index === count($expected) - 1) {
                $parts = [];
                
                foreach (array_slice($actual, $index) as $part) {
                    $value = rawurldecode($part);
                    
                    if ($value === '' || $value === '.' || $value === '..'
                        || strpbrk($value, "/\\\0") !== false
                    ) {
                        return null;
                    }
                    
                    $parts[] = $value;
                }
                
                $parameters[$tail[1]] = implode('/', $parts);
                break;
            }
            
            if (preg_match('/^\{([a-zA-Z][a-zA-Z0-9_]*)\}$/', $segment, $match) === 1) {
                $value = rawurldecode($actual[$index]);
                
                if ($value === '' || strpbrk($value, "/\\\0") !== false) {
                    return null;
                }
                
                $parameters[$match[1]] = $value;
            } elseif ($segment !== $actual[$index]) {
                return null;
            }
        }
        
        return $parameters;
    }
}
