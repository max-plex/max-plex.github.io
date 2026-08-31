<?php
/**
 * Enterprise OTT Platform - REST API Master Entrypoint
 */

declare(strict_types=1);

// Enable error display for diagnostics
ini_set('display_errors', '0');
error_reporting(E_ALL);

// Robust PSR-4 Autoloader with Case-Insensitive Linux Support
spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $path = str_replace('\\', '/', $relativeClass);

    // 1. Check src/
    $file = __DIR__ . '/../src/' . $path . '.php';
    if (file_exists($file)) {
        require_once $file;
        return;
    }

    // 2. Check config/ (e.g. App\Config\Database)
    if (str_starts_with($relativeClass, 'Config\\')) {
        $configName = substr($relativeClass, 7); // e.g. "Database"
        $configFile1 = __DIR__ . '/../config/' . $configName . '.php';
        $configFile2 = __DIR__ . '/../config/' . strtolower($configName) . '.php';

        if (file_exists($configFile1)) {
            require_once $configFile1;
            return;
        } elseif (file_exists($configFile2)) {
            require_once $configFile2;
            return;
        }
    }
});

use App\Core\Env;
use App\Core\Request;

// 1. Load Environment Variables (.env)
$envPath = __DIR__ . '/../.env';
if (file_exists($envPath)) {
    Env::load($envPath);
}

// 2. Dispatch Router with Global Exception Safety
try {
    $router = require __DIR__ . '/../routes/api.php';
    $request = new Request();
    $router->dispatch($request);
} catch (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    header('Access-Control-Allow-Origin: *');
    echo json_encode([
        'status'  => false,
        'message' => 'Server Error: ' . $e->getMessage(),
        'file'    => basename($e->getFile()) . ':' . $e->getLine()
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}
