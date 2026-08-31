<?php
namespace App\Core;

class Router {
    private array $routes = [];
    private array $globalMiddleware = [];

    public function addGlobalMiddleware(callable|string $middleware): void {
        $this->globalMiddleware[] = $middleware;
    }

    public function get(string $path, callable|array $handler, array $middlewares = []): void {
        $this->addRoute('GET', $path, $handler, $middlewares);
    }

    public function post(string $path, callable|array $handler, array $middlewares = []): void {
        $this->addRoute('POST', $path, $handler, $middlewares);
    }

    public function put(string $path, callable|array $handler, array $middlewares = []): void {
        $this->addRoute('PUT', $path, $handler, $middlewares);
    }

    public function delete(string $path, callable|array $handler, array $middlewares = []): void {
        $this->addRoute('DELETE', $path, $handler, $middlewares);
    }

    public function options(string $path, callable|array $handler): void {
        $this->addRoute('OPTIONS', $path, $handler, []);
    }

    private function addRoute(string $method, string $path, callable|array $handler, array $middlewares): void {
        $pattern = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?P<$1>[^/]+)', $path);
        $pattern = '#^' . $pattern . '$#';

        $this->routes[] = [
            'method'      => $method,
            'pattern'     => $pattern,
            'handler'     => $handler,
            'middlewares' => $middlewares
        ];
    }

    public function dispatch(Request $request): void {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        
        // Handle pre-flight CORS OPTIONS request directly
        if ($method === 'OPTIONS') {
            http_response_code(200);
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
            exit;
        }

        // Clean URI from query strings & script path
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
        if ($scriptDir !== '/' && str_starts_with($uri, $scriptDir)) {
            $uri = substr($uri, strlen($scriptDir));
        }
        $uri = '/' . trim($uri, '/');

        // Execute Global Middlewares
        foreach ($this->globalMiddleware as $mw) {
            $this->executeMiddleware($mw, $request);
        }

        // Find Matching Route
        foreach ($this->routes as $route) {
            if ($route['method'] === $method && preg_match($route['pattern'], $uri, $matches)) {
                // Pass route params to request
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                foreach ($params as $k => $v) {
                    $_GET[$k] = $v;
                    $request->setParam($k, $v);
                }

                // Execute Route Middlewares
                foreach ($route['middlewares'] as $mw) {
                    $this->executeMiddleware($mw, $request);
                }

                // Execute Controller Handler
                $handler = $route['handler'];
                if (is_array($handler)) {
                    [$class, $action] = $handler;
                    $controller = new $class();
                    $controller->$action($request);
                } elseif (is_callable($handler)) {
                    $handler($request);
                }
                return;
            }
        }

        Response::notFound("Endpoint {$method} {$uri} not found");
    }

    private function executeMiddleware(callable|string $middleware, Request $request): void {
        if (is_string($middleware)) {
            $instance = new $middleware();
            $instance->handle($request);
        } elseif (is_callable($middleware)) {
            $middleware($request);
        }
    }
}
