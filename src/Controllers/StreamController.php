<?php
namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\ScraperService;

class StreamController {
    public function getStream(Request $request): void {
        $code = trim($request->getQuery('code') ?? ($request->get('code') ?? ''));

        if (empty($code)) {
            Response::error('Missing "code" parameter', 422);
        }

        $streamData = ScraperService::resolveHdStreamDirect($code);

        if (!$streamData || empty($streamData['is_direct'])) {
            Response::error('Direct streaming source could not be resolved', 502);
        }

        $proxyBase = 'https://proxy-eta-tawny.vercel.app/api/stream';
        try {
            $db = \App\Config\Database::getConnection();
            $stmt = $db->query("SELECT key_value FROM system_config WHERE key_name = 'streaming_proxy_url' LIMIT 1");
            if ($stmt && $row = $stmt->fetch()) {
                if (!empty($row['key_value'])) $proxyBase = rtrim($row['key_value'], '/');
            }
        } catch (\Throwable $e) {}

        $qualities = [
            [
                'quality'      => '720P HD (Universal)',
                'resolution'   => '1280x720',
                'stream_url'   => "{$proxyBase}?code={$code}&quality=720p",
                'direct_m3u8'  => $streamData['qualities'][0]['stream_url'] ?? $streamData['master_url']
            ],
            [
                'quality'      => '1080P FHD (Universal)',
                'resolution'   => '1920x1080',
                'stream_url'   => "{$proxyBase}?code={$code}&quality=1080p",
                'direct_m3u8'  => $streamData['qualities'][1]['stream_url'] ?? $streamData['master_url']
            ],
            [
                'quality'      => '480P SD (Universal)',
                'resolution'   => '852x480',
                'stream_url'   => "{$proxyBase}?code={$code}&quality=480p",
                'direct_m3u8'  => $streamData['qualities'][2]['stream_url'] ?? $streamData['master_url']
            ]
        ];

        $responsePayload = [
            'is_resolved'      => true,
            'file_code'        => $code,
            'stream_type'      => 'hls_m3u8',
            'stream_url'       => "{$proxyBase}?code={$code}&quality=720p",
            'master_url'       => $streamData['master_url'],
            'qualities'        => $qualities,
            'embed_url'        => "https://hdstream4u.com/embed-{$code}.html",
            'headers'          => [
                'Referer'      => 'https://hdstream4u.com/',
                'Origin'       => 'https://hdstream4u.com',
                'User-Agent'   => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            ],
            'proxy_stream_url' => "{$proxyBase}?code={$code}&quality=720p"
        ];

        Response::success($responsePayload, 'Direct streaming source resolved successfully');
    }

    public function proxyStream(Request $request): void {
        @ini_set('max_execution_time', 0);
        @ini_set('memory_limit', '256M');

        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
        header('Access-Control-Allow-Headers: *');
        header('X-Accel-Buffering: no');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit;
        }

        // ========================================================
        // 1. ULTRA-FAST TS VIDEO SEGMENT STREAMING
        // ========================================================
        $tsUrl = $request->getQuery('ts') ?? ($_GET['ts'] ?? '');
        if (!empty($tsUrl)) {
            $tsUrl = urldecode($tsUrl);

            if (!filter_var($tsUrl, FILTER_VALIDATE_URL) || (strpos($tsUrl, '.ts') === false && strpos($tsUrl, '.m3u8') === false)) {
                http_response_code(400);
                exit('Invalid TS target URL');
            }

            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            header('Content-Type: video/MP2T');
            header('Cache-Control: public, max-age=86400');
            header('Accept-Ranges: bytes');

            $ch = curl_init($tsUrl);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_ENCODING, '');
            curl_setopt($ch, CURLOPT_BUFFERSIZE, 1048576); // 1 MB high-speed buffer
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $chunk) {
                echo $chunk;
                flush();
                return strlen($chunk);
            });
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
                'Referer: https://hdstream4u.com/',
                'Origin: https://hdstream4u.com'
            ]);

            curl_exec($ch);
            curl_close($ch);
            exit;
        }

        // ========================================================
        // 2. ULTRA-FAST M3U8 PLAYLIST (0.0001s Instant Parser)
        // ========================================================
        $code = trim($request->getQuery('code') ?? ($_GET['code'] ?? ''));
        $quality = strtolower($request->getQuery('quality') ?? ($_GET['quality'] ?? '720p'));

        if (empty($code)) {
            http_response_code(400);
            exit('Missing "code" parameter');
        }

        $cacheDir = sys_get_temp_dir() . '/hdstream_cache';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0777, true);
        }
        $cacheFile = $cacheDir . "/m3u8_" . md5($code . '_' . $quality) . ".m3u8";

        // Serve instantly from cache (50ms response!)
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < 180)) {
            header('Content-Type: application/vnd.apple.mpegurl');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('X-Proxy-Cache: HIT');
            readfile($cacheFile);
            exit;
        }

        // Resolve fresh upstream stream
        $resolved = ScraperService::resolveHdStreamDirect($code);

        if (!$resolved || empty($resolved['stream_url'])) {
            http_response_code(502);
            exit('Could not resolve upstream video stream');
        }

        $targetStreamUrl = $resolved['master_url'] ?? $resolved['stream_url'];

        $ch = curl_init($targetStreamUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Referer: https://hdstream4u.com/',
            'Origin: https://hdstream4u.com'
        ]);

        $m3u8Content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        $curlErrNo = curl_errno($ch);
        curl_close($ch);

        if ($httpCode !== 200 || empty($m3u8Content)) {
            header("Location: {$targetStreamUrl}", true, 302);
            exit;
        }

        // Fast Line-by-Line String Parser
        $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'];
        $proxyEndpoint = rtrim($baseUrl, '/') . '/api/v1/media/proxy_stream';

        $lines = explode("\n", $m3u8Content);
        foreach ($lines as &$line) {
            $trimmed = trim($line);
            if (!empty($trimmed) && $trimmed[0] !== '#' && (strpos($trimmed, 'http://') === 0 || strpos($trimmed, 'https://') === 0)) {
                $line = $proxyEndpoint . '?ts=' . urlencode($trimmed);
            }
        }
        $rewrittenM3u8 = implode("\n", $lines);

        @file_put_contents($cacheFile, $rewrittenM3u8);

        header('Content-Type: application/vnd.apple.mpegurl');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('X-Proxy-Cache: MISS');
        echo $rewrittenM3u8;
        exit;
    }
}
