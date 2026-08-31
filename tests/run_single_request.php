<?php
/**
 * Single Request CLI Runner for Testing API Endpoints
 */

declare(strict_types=1);

if ($argc < 2) {
    echo json_encode(['error' => 'Missing payload argument']);
    exit(1);
}

$raw = base64_decode($argv[1], true);
$payload = $raw ? json_decode($raw, true) : json_decode($argv[1], true);
if (!$payload) {
    echo json_encode(['error' => 'Invalid JSON payload argument']);
    exit(1);
}

$dbPath  = $payload['db_path'] ?? '';
$method  = strtoupper($payload['method'] ?? 'GET');
$uri     = $payload['uri'] ?? '/';
$body    = $payload['body'] ?? [];
$headers = $payload['headers'] ?? [];

// PSR-4 Autoloader
spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) return;
    $relativeClass = substr($class, $len);
    $path = str_replace('\\', '/', $relativeClass);
    $file = __DIR__ . '/../src/' . $path . '.php';
    if (file_exists($file)) {
        require_once $file;
        return;
    }
    if (str_starts_with($relativeClass, 'Config\\')) {
        $configName = substr($relativeClass, 7);
        $configFile = __DIR__ . '/../config/' . $configName . '.php';
        if (file_exists($configFile)) {
            require_once $configFile;
            return;
        }
    }
});

use App\Config\Database;
use App\Core\Env;
use App\Core\Request;
use App\Core\Router;

// Inject DB connection to SQLite test DB
$pdo = null;
if (!empty($dbPath) && file_exists($dbPath)) {
    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $pdo->sqliteCreateFunction('NOW', function() {
            return date('Y-m-d H:i:s');
        });
        $pdo->sqliteCreateFunction('DATE_ADD', function($date, $interval) {
            return date('Y-m-d H:i:s', time() + 3600);
        });
    }
    $ref = new ReflectionProperty(Database::class, 'instance');
    $ref->setAccessible(true);
    $ref->setValue(null, $pdo);

    register_shutdown_function(function() use (&$pdo) {
        $pdo = null;
        $ref = new ReflectionProperty(Database::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, null);
    });
}

// Setup $_SERVER environment
$_SERVER['REQUEST_METHOD'] = $method;
$_SERVER['REQUEST_URI'] = $uri;
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

foreach ($headers as $k => $v) {
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $k));
    $_SERVER[$serverKey] = $v;
}

$_GET = [];
$queryString = parse_url($uri, PHP_URL_QUERY);
if ($queryString) {
    parse_str($queryString, $_GET);
}

if (!empty($body)) {
    $_POST = $body;
}

// Initialize Router & Request
$router = require __DIR__ . '/../routes/api.php';
$request = new Request();

// If body was provided as JSON, populate bodyParams via reflection or simulate
if (!empty($body)) {
    $refBody = new ReflectionProperty(Request::class, 'bodyParams');
    $refBody->setAccessible(true);
    $refBody->setValue($request, $body);
}

// Dispatch
$router->dispatch($request);
