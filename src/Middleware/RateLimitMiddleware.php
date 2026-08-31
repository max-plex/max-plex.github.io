<?php
namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Core\Env;

class RateLimitMiddleware {
    public function handle(Request $request): void {
        $ip = $request->getClientIp();
        $maxRequests = (int)Env::get('RATE_LIMIT_MAX_REQUESTS', 150);
        $windowSeconds = (int)Env::get('RATE_LIMIT_WINDOW_SECONDS', 60);

        $cacheDir = sys_get_temp_dir() . '/ott_rate_limits';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0777, true);
        }

        $keyFile = $cacheDir . '/rate_' . md5($ip) . '.json';
        $now = time();

        $data = ['count' => 0, 'start_time' => $now];
        if (file_exists($keyFile)) {
            $content = @file_get_contents($keyFile);
            $parsed = json_decode($content, true);
            if (is_array($parsed)) {
                $data = $parsed;
            }
        }

        if ($now - $data['start_time'] > $windowSeconds) {
            $data = ['count' => 1, 'start_time' => $now];
        } else {
            $data['count']++;
        }

        @file_put_contents($keyFile, json_encode($data));

        header("X-RateLimit-Limit: {$maxRequests}");
        header("X-RateLimit-Remaining: " . max(0, $maxRequests - $data['count']));

        if ($data['count'] > $maxRequests) {
            Response::error("Rate limit exceeded. Please try again later.", 429);
        }
    }
}
