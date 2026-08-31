<?php
namespace App\Core;

class Response {
    public static function json(bool $status, mixed $data = [], string $message = '', int $statusCode = 200): void {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=UTF-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

        $response = [
            'status' => $status,
            'message' => $message
        ];

        if (is_array($data) && isset($data[0])) {
            $response['count'] = count($data);
            $response['data'] = $data;
        } elseif ($data !== null) {
            $response['data'] = $data;
        }

        echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    public static function success(mixed $data = [], string $message = 'Success', int $statusCode = 200): void {
        self::json(true, $data, $message, $statusCode);
    }

    public static function error(string $message = 'An error occurred', int $statusCode = 400, mixed $errors = null): void {
        self::json(false, $errors, $message, $statusCode);
    }

    public static function unauthorized(string $message = 'Unauthorized access'): void {
        self::json(false, null, $message, 401);
    }

    public static function notFound(string $message = 'Resource not found'): void {
        self::json(false, null, $message, 404);
    }
}
