<?php
namespace App\Core;

class Request {
    private array $queryParams;
    private array $bodyParams;
    private array $routeParams = [];
    private array $headers;
    private ?int $userId = null;
    private ?array $authUser = null;

    public function __construct() {
        if (empty($_GET) && !empty($_SERVER['REQUEST_URI'])) {
            $queryString = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
            if ($queryString) {
                parse_str($queryString, $_GET);
            }
        }
        $this->queryParams = $_GET ?? [];
        $this->headers = $this->getAllRequestHeaders();
        $this->bodyParams = $this->parseRequestBody();
    }

    private function getAllRequestHeaders(): array {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (str_starts_with($name, 'HTTP_')) {
                $headerName = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                $headers[$headerName] = $value;
            } elseif (in_array($name, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'])) {
                $headerName = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', $name))));
                $headers[$headerName] = $value;
            }
        }
        return $headers;
    }

    private function parseRequestBody(): array {
        $contentType = $this->getHeader('Content-Type') ?? '';
        
        if (str_contains($contentType, 'application/json')) {
            $rawInput = file_get_contents('php://input');
            $decoded = json_decode($rawInput, true);
            return is_array($decoded) ? $decoded : [];
        }

        return $_POST ?? [];
    }

    public function setParam(string $key, mixed $value): void {
        $this->routeParams[$key] = $value;
        $this->queryParams[$key] = $value;
    }

    public function get(string $key, mixed $default = null): mixed {
        return $this->routeParams[$key] ?? $this->queryParams[$key] ?? $this->bodyParams[$key] ?? $_GET[$key] ?? $default;
    }

    public function getQuery(string $key, mixed $default = null): mixed {
        return $this->queryParams[$key] ?? $this->routeParams[$key] ?? $_GET[$key] ?? $default;
    }

    public function getBody(): array {
        return $this->bodyParams;
    }

    public function getHeader(string $key, ?string $default = null): ?string {
        return $this->headers[$key] ?? $this->headers[strtolower($key)] ?? $default;
    }

    public function getBearerToken(): ?string {
        $authHeader = $this->getHeader('Authorization') ?? $this->getHeader('authorization');
        if ($authHeader && preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
            return $matches[1];
        }
        return null;
    }

    public function getClientIp(): string {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            return $_SERVER['HTTP_CF_CONNECTING_IP'];
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($ips[0]);
        }
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    public function setAuthUser(array $user): void {
        $this->authUser = $user;
        $this->userId = $user['id'] ?? null;
    }

    public function getAuthUser(): ?array {
        return $this->authUser;
    }

    public function getUserId(): ?int {
        return $this->userId;
    }
}
