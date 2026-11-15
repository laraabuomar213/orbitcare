<?php
// Minimal API router. Each route declares who may call it:
//   'auth'  => true (default) requires a signed-in user
//   'roles' => ['astronaut'] / ['mission_admin'] limits the role
//   'rate'  => [bucket, maxRequests, windowSeconds]
//   'csrf'  => false only for machine endpoints with their own credential (the vest's device token);
//              it is refused together with 'auth' => true, so a browser session can never skip it.
// Every other write (POST/PUT/PATCH/DELETE) must carry the CSRF token in the X-CSRF-Token header.
declare(strict_types=1);

final class Router
{
    private array $routes = [];

    public function get(string $pattern, callable $handler, array $options = []): void
    {
        $this->add('GET', $pattern, $handler, $options);
    }

    public function post(string $pattern, callable $handler, array $options = []): void
    {
        $this->add('POST', $pattern, $handler, $options);
    }

    public function put(string $pattern, callable $handler, array $options = []): void
    {
        $this->add('PUT', $pattern, $handler, $options);
    }

    private function add(string $method, string $pattern, callable $handler, array $options): void
    {
        if (($options['csrf'] ?? true) === false && ($options['auth'] ?? true) !== false) {
            throw new LogicException("Route {$pattern}: only routes without a user session may skip CSRF.");
        }
        // "/alerts/{id}" → "#^/alerts/(?<id>\d+)$#"
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?<$1>\d+)', $pattern) . '$#';
        $this->routes[] = compact('method', 'regex', 'handler', 'options');
    }

    public function dispatch(string $method, string $path): never
    {
        $started = microtime(true);
        $allowedMethods = [];

        try {
            enforce_cors();

            foreach ($this->routes as $route) {
                if (!preg_match($route['regex'], $path, $matches)) {
                    continue;
                }
                if ($route['method'] !== $method) {
                    $allowedMethods[] = $route['method'];
                    continue;
                }

                $params = array_map('intval', array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY));
                $options = $route['options'] + ['auth' => true, 'roles' => null, 'rate' => null, 'csrf' => true];

                // Sign-in is checked first so an expired session reports "session_expired", not a token error.
                $user = $options['auth'] ? api_require_user($options['roles']) : current_user();

                // Header for fetch() calls; form field for plain HTML forms (e.g. Log Out).
                if ($method !== 'GET' && $options['csrf'] && !verify_csrf_token($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? null)) {
                    throw new ApiException(403, 'csrf_failed', t('err.csrf'));
                }
                if ($options['rate']) {
                    enforce_rate_limit(...$options['rate']);
                }

                $body = $method === 'GET' ? [] : request_body();

                $data = ($route['handler'])($params, $body, $user);

                log_event('api.request', ['method' => $method, 'path' => $path, 'status' => 200, 'ms' => (int) ((microtime(true) - $started) * 1000)]);
                json_response(['ok' => true, 'data' => $data]);
            }

            if ($allowedMethods) {
                header('Allow: ' . implode(', ', array_unique($allowedMethods)));
                throw new ApiException(405, 'method_not_allowed', t('err.method_not_allowed'));
            }
            throw ApiException::notFound(t('err.unknown_endpoint'));
        } catch (ApiException $error) {
            log_event('api.request', ['method' => $method, 'path' => $path, 'status' => $error->status, 'code' => $error->errorCode], $error->status >= 500 ? 'error' : 'info');
            json_error($error->status, $error->errorCode, $error->getMessage(), $error->fields);
        } catch (DatabaseUnavailable $error) {
            log_event('api.request', ['method' => $method, 'path' => $path, 'status' => 503, 'code' => 'database_unavailable'], 'error');
            json_error(503, 'database_unavailable', t('err.database_unavailable'));
        } catch (Throwable $error) {
            log_event('api.exception', ['method' => $method, 'path' => $path, 'status' => 500, 'error' => $error->getMessage(), 'at' => basename($error->getFile()) . ':' . $error->getLine()], 'error');
            json_error(500, 'server_error', t('err.server_error'));
        }
    }
}
