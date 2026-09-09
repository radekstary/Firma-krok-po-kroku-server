<?php

declare(strict_types=1);

namespace Firma;

/**
 * Minimalny router: rejestracja tras z parametrami w klamrach (`/api/ksiegi/{id}/wpisy`) i
 * dopasowanie po metodzie + sciezce. Handler dostaje tablice parametrow sciezki. Celowo prosty —
 * bez zaleznosci, bo na hostingu wspoldzielonym nie zawsze mamy composera dla frameworka.
 */
final class Router
{
    /** @var list<array{method:string, regex:string, params:list<string>, handler:callable}> */
    private array $routes = [];

    /** @param callable(array<string,string>):void $handler */
    public function add(string $method, string $pattern, callable $handler): void
    {
        $params = [];
        $regex = preg_replace_callback(
            '/\{(\w+)\}/',
            function (array $m) use (&$params): string {
                $params[] = $m[1];
                return '([^/]+)';
            },
            $pattern
        );
        $this->routes[] = [
            'method'  => strtoupper($method),
            'regex'   => '#^' . $regex . '$#',
            'params'  => $params,
            'handler' => $handler,
        ];
    }

    /**
     * Dopasowuje zadanie i wola handler. Gdy sciezka pasuje, ale metoda nie — 405.
     * Gdy nic nie pasuje — 404.
     */
    public function dispatch(string $method, string $path): void
    {
        $pathMatched = false;
        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $m) === 1) {
                $pathMatched = true;
                if ($route['method'] === $method) {
                    $args = [];
                    foreach ($route['params'] as $i => $name) {
                        $args[$name] = $m[$i + 1];
                    }
                    ($route['handler'])($args);
                    return;
                }
            }
        }
        if ($pathMatched) {
            throw new HttpError(405, 'method_not_allowed', 'Metoda niedozwolona dla tej sciezki.');
        }
        throw new HttpError(404, 'not_found', 'Nie znaleziono zasobu.');
    }
}
