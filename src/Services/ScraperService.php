<?php
namespace App\Services;

use App\Controllers\ConfigController;

class ScraperService {
    private static ?string $cachedBaseUrl = null;

    private static array $knownSeeds = [
        'https://new5.hdhub4u.cl',
        'https://new1.hdhub4u.af',
        'https://hdhub4u.tv',
        'https://hdhub4u.ms',
        'https://hdhub4u.cl',
        'https://hdhub4u.lat',
        'https://hdhub4u.pe',
        'https://hdhub4u.mov'
    ];

    public static function getBaseUrl(): string {
        if (self::$cachedBaseUrl !== null) {
            return self::$cachedBaseUrl;
        }
        if (class_exists('App\Controllers\ConfigController')) {
            self::$cachedBaseUrl = ConfigController::getDynamicBaseUrl();
        } else {
            self::$cachedBaseUrl = 'https://new5.hdhub4u.cl';
        }
        return self::$cachedBaseUrl;
    }

    public static function setBaseUrl(string $newBase): void {
        self::$cachedBaseUrl = rtrim($newBase, '/');
        if (class_exists('App\Controllers\ConfigController')) {
            ConfigController::updateDynamicBaseUrl(self::$cachedBaseUrl);
        }
    }

    /**
     * Probes known seeds to discover and persist live working domain when current one fails
     */
    public static function probeAndHealBaseUrl(): ?string {
        $currentBase = self::getBaseUrl();
        $candidates = array_unique(array_merge([$currentBase], self::$knownSeeds));

        foreach ($candidates as $cand) {
            $ch = curl_init($cand . '/');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 4);
            curl_setopt($ch, CURLOPT_TIMEOUT, 6);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
            
            $res = curl_exec($ch);
            $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200 && !empty($res) && !empty($effectiveUrl)) {
                $host = parse_url($effectiveUrl, PHP_URL_HOST);
                $scheme = parse_url($effectiveUrl, PHP_URL_SCHEME) ?: 'https';
                if ($host && str_contains($host, 'hdhub4u')) {
                    $newBase = "{$scheme}://{$host}";
                    self::setBaseUrl($newBase);
                    return $newBase;
                }
            }
        }

        return null;
    }

    public static function fetchHTML(string $url, array $customHeaders = [], int $timeout = 15, bool $isRetry = false): ?string {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_ENCODING, 'gzip, deflate');

        $headers = array_merge([
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9,hi;q=0.8'
        ], $customHeaders);

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $html = curl_exec($ch);
        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // 1. In-Flight Redirection Detection & Auto-Persistence (HDHub4u only)
        if (!empty($effectiveUrl) && $httpCode === 200 && str_contains($url, 'hdhub4u')) {
            $effHost = parse_url($effectiveUrl, PHP_URL_HOST);
            $currBase = self::getBaseUrl();
            $currHost = parse_url($currBase, PHP_URL_HOST);

            if ($effHost && $currHost && $effHost !== $currHost && str_contains($effHost, 'hdhub4u')) {
                $effScheme = parse_url($effectiveUrl, PHP_URL_SCHEME) ?: 'https';
                $newBase = "{$effScheme}://{$effHost}";
                self::setBaseUrl($newBase);
            }
        }

        // 2. Self-Healing Fallback on Failure/Timeout (Only for HDHub4u URLs)
        if (str_contains($url, 'hdhub4u') && (empty($html) || $httpCode >= 400) && !$isRetry) {
            $healedBase = self::probeAndHealBaseUrl();
            if ($healedBase) {
                // Rewrite current URL with newly healed base domain
                $oldHost = parse_url($url, PHP_URL_HOST);
                $newHost = parse_url($healedBase, PHP_URL_HOST);
                if ($oldHost && $newHost && $oldHost !== $newHost) {
                    $healedUrl = str_replace($oldHost, $newHost, $url);
                    return self::fetchHTML($healedUrl, $customHeaders, $timeout, true);
                }
            }
        }

        return $html ?: null;
    }

    public static function getFinalRedirectUrl(string $url, int $maxRedirects = 5): string {
        if (empty($url) || $maxRedirects <= 0) return $url;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Referer: https://gamerxyt.com/']);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);

        if (in_array($httpCode, [301, 302, 307, 308]) && !empty($redirectUrl)) {
            if (preg_match('/link=(https?:\/\/[^&]+)/i', $redirectUrl, $linkMatch)) {
                return urldecode($linkMatch[1]);
            }
            return self::getFinalRedirectUrl($redirectUrl, $maxRedirects - 1);
        }

        return $url;
    }

    public static function unpackPackerJs(string $packedJs): string {
        $regex = "/eval\s*\(\s*function\(p,a,c,k,e,[rd]\)[\s\S]*?\}\s*\(\s*['\"]([\s\S]*?)['\"]\s*,\s*(\d+)\s*,\s*(\d+)\s*,\s*['\"]([\s\S]*?)['\"]\s*\.split\('\|'\)/i";
        if (!preg_match($regex, $packedJs, $m)) {
            return '';
        }

        $payload = $m[1];
        $radix = (int)$m[2];
        $count = (int)$m[3];
        $words = explode('|', $m[4]);

        if ($radix <= 0 || $count <= 0) return '';

        $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

        for ($c = $count - 1; $c >= 0; $c--) {
            $num = $c;
            if ($num == 0) {
                $key = '0';
            } else {
                $res = '';
                while ($num > 0) {
                    $rem = $num % $radix;
                    $res = $chars[$rem] . $res;
                    $num = (int)floor($num / $radix);
                }
                $key = $res;
            }

            $word = (isset($words[$c]) && $words[$c] !== '') ? $words[$c] : $key;
            $payload = preg_replace('/\b' . preg_quote($key, '/') . '\b/', $word, $payload);
        }

        return $payload;
    }

    public static function resolveHdStreamDirect(string $code): ?array {
        $cleanCode = trim($code);
        if (preg_match('/(?:file\/|embed-|\/)([a-zA-Z0-9_-]{8,30})(?:\.html)?/i', $cleanCode, $m)) {
            $cleanCode = $m[1];
        }

        $streamPageUrl = "https://hdstream4u.com/file/{$cleanCode}";
        $html = self::fetchHTML($streamPageUrl, ['Referer: https://new1.hdhub4u.af/']);

        if (!$html) {
            return [
                'is_direct'   => false,
                'stream_type' => 'webview',
                'stream_url'  => null,
                'embed_url'   => "https://hdstream4u.com/embed-{$cleanCode}.html",
                'qualities'   => []
            ];
        }

        $unpacked = self::unpackPackerJs($html);
        if (empty($unpacked)) {
            return [
                'is_direct'   => false,
                'stream_type' => 'webview',
                'stream_url'  => null,
                'embed_url'   => "https://hdstream4u.com/embed-{$cleanCode}.html",
                'qualities'   => []
            ];
        }

        if (preg_match('/https?:\/\/[^\s"\'\<\>]+\.m3u8[^\s"\'\<\>]*/i', $unpacked, $m3u8Match)) {
            $masterUrl = $m3u8Match[0];
            $qualities = [];

            if (str_contains($masterUrl, 'master.m3u8')) {
                $qualities[] = ['quality' => '720p', 'label' => '720p HD', 'stream_url' => str_replace('master.m3u8', 'index-f2-v1-a1.m3u8', $masterUrl)];
                $qualities[] = ['quality' => '1080p', 'label' => '1080p Full HD', 'stream_url' => str_replace('master.m3u8', 'index-f3-v1-a1.m3u8', $masterUrl)];
                $qualities[] = ['quality' => '480p', 'label' => '480p SD', 'stream_url' => str_replace('master.m3u8', 'index-f1-v1-a1.m3u8', $masterUrl)];
            } else {
                $qualities[] = ['quality' => 'Default', 'label' => 'Auto Quality', 'stream_url' => $masterUrl];
            }

            return [
                'is_direct'   => true,
                'stream_type' => 'hls',
                'stream_url'  => $qualities[0]['stream_url'],
                'master_url'  => $masterUrl,
                'qualities'   => $qualities,
                'embed_url'   => "https://hdstream4u.com/embed-{$cleanCode}.html"
            ];
        }

        return [
            'is_direct'   => false,
            'stream_type' => 'webview',
            'stream_url'  => null,
            'embed_url'   => "https://hdstream4u.com/embed-{$cleanCode}.html",
            'qualities'   => []
        ];
    }

    public static function resolveGreenmount(string $url): ?array {
        $html = self::fetchHTML($url, ['Referer: https://new1.hdhub4u.af/']);
        if (!$html) return null;

        if (preg_match('/s\(\s*[\'"]o[\'"]\s*,\s*[\'"]([a-zA-Z0-9+\/=%]+)[\'"]/i', $html, $tokenMatch)) {
            $token = $tokenMatch[1];
            $step1 = base64_decode($token);
            $step2 = base64_decode($step1);
            $step3 = str_rot13($step2);
            $step4 = base64_decode($step3);
            $json = json_decode($step4, true);

            if ($json && !empty($json['o'])) {
                $destUrl = base64_decode($json['o']);
                if (filter_var($destUrl, FILTER_VALIDATE_URL)) {
                    return self::resolveHbLinks($destUrl);
                }
            }
        }
        return null;
    }

    public static function resolveHbLinks(string $url): ?array {
        $html = self::fetchHTML($url, ['Referer: https://new1.hdhub4u.af/']);
        if (!$html) return null;

        $qualities = [];
        $blockRegex = '/<(h[1-6]|p)[^>]*>([\s\S]*?)<\/(h[1-6]|p)>/i';

        if (preg_match_all($blockRegex, $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $blockHtml = $m[2];
                $text = trim(preg_replace('/\s+/', ' ', strip_tags($blockHtml)));

                if (preg_match('/(480p|720p|1080p|2160p|4K)/i', $text, $qMatch)) {
                    $qualityTag = strtoupper($qMatch[1]);
                    if (stripos($text, 'HEVC') !== false || stripos($text, '10Bit') !== false) {
                        $qualityTag .= ' HEVC';
                    } elseif (stripos($text, 'WEB-DL') !== false) {
                        $qualityTag .= ' WEB-DL';
                    }

                    // Clean human-readable size label
                    preg_match('/([0-9.]+\s*(?:GB|MB))/i', $text, $sizeM);
                    $cleanLabel = $qualityTag . (!empty($sizeM[1]) ? " [{$sizeM[1]}]" : "");

                    $candidateUrls = [];
                    $blockMirrors  = [];

                    if (preg_match_all('/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $blockHtml, $aMatches, PREG_SET_ORDER)) {
                        foreach ($aMatches as $am) {
                            $href = $am[1];
                            if (str_contains($href, 'hubcdn') || str_contains($href, 'hubcloud') || str_contains($href, 'hubdrive') || str_contains($href, 'gdflix')) {
                                if (!in_array($href, $candidateUrls, true)) {
                                    $candidateUrls[] = $href;
                                }
                            } elseif (preg_match('/pixeldrain\.(?:com|dev)\/u\/([a-zA-Z0-9]+)/i', $href, $pm)) {
                                $blockMirrors[] = [
                                    'server_name'  => '⚡ PixelDrain (Direct 10Gbps)',
                                    'download_url' => "https://pixeldrain.com/api/file/{$pm[1]}?download",
                                    'is_direct'    => true
                                ];
                            }
                        }
                    }

                    $target = !empty($candidateUrls) ? $candidateUrls[0] : (!empty($blockMirrors) ? $blockMirrors[0]['download_url'] : null);
                    if ($target) {
                        $qualities[] = [
                            'quality'               => $qualityTag,
                            'label'                 => $cleanLabel,
                            'target_url'            => $target,
                            'download_resolver_api' => "/api/v1/media/download?url=" . urlencode($target)
                        ];
                    }
                }
            }
        }

        // Extract all candidate primary download buttons from the page
        $allPageCandidates = [];
        $allPageMirrors    = [];

        if (preg_match_all('/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $html, $allMatches, PREG_SET_ORDER)) {
            foreach ($allMatches as $am) {
                $href = $am[1];
                if (str_contains($href, 'hubcdn') || str_contains($href, 'hubcloud') || str_contains($href, 'hubdrive') || str_contains($href, 'gdflix')) {
                    if (!in_array($href, $allPageCandidates, true)) {
                        $allPageCandidates[] = $href;
                    }
                } elseif (preg_match('/pixeldrain\.(?:com|dev)\/u\/([a-zA-Z0-9]+)/i', $href, $pm)) {
                    $allPageMirrors[] = [
                        'server_name'  => '⚡ PixelDrain (Direct 10Gbps)',
                        'download_url' => "https://pixeldrain.com/api/file/{$pm[1]}?download",
                        'is_direct'    => true
                    ];
                }
            }
        }

        $primaryServers = [];
        $primaryUrl     = !empty($allPageCandidates) ? $allPageCandidates[0] : null;

        // Try candidates one by one in fallback sequence until direct working download is found
        foreach ($allPageCandidates as $cUrl) {
            $resolved = null;
            if (str_contains($cUrl, 'hubcdn')) {
                $resolved = self::resolveHubCdn($cUrl);
            } elseif (str_contains($cUrl, 'hubcloud')) {
                $resolved = self::resolveHubCloud($cUrl);
            } elseif (str_contains($cUrl, 'hubdrive')) {
                $resolved = self::resolveHubDrive($cUrl);
            }

            if ($resolved && !empty($resolved['servers'])) {
                // Filter only direct servers
                $directOnly = array_values(array_filter($resolved['servers'], fn($s) => !empty($s['is_direct']) && $s['is_direct'] === true));
                if (!empty($directOnly)) {
                    $primaryUrl = $directOnly[0]['download_url'];
                    $primaryServers = $directOnly;
                    break; // Found working direct download servers!
                }
            }
        }

        // Fallback to direct mirrors if primary candidates are offline
        if (empty($primaryServers) && !empty($allPageMirrors)) {
            $primaryServers = $allPageMirrors;
            $primaryUrl = $primaryServers[0]['download_url'];
        }

        if (!empty($qualities) || !empty($primaryServers)) {
            return [
                'is_direct'              => !empty($primaryServers),
                'download_type'          => 'direct_cdn',
                'primary_download_url'   => $primaryUrl,
                'direct_download_url'    => $primaryUrl,
                'servers'                => $primaryServers,
                'qualities'              => $qualities,
                'original_url'           => $url
            ];
        }

        return null;
    }

    public static function resolveHubCdn(string $url): ?array {
        $html = self::fetchHTML($url, ['Referer: https://new1.hdhub4u.af/']);
        if (!$html) return null;

        if (preg_match('/[?&]r=([a-zA-Z0-9+\/]+={0,2})/i', $html, $rMatch)) {
            $decoded = base64_decode($rMatch[1]);
            if (preg_match('/link=(https?:\/\/[^\s&]+)/i', $decoded, $lMatch)) {
                $directFileUrl = urldecode($lMatch[1]);
                return [
                    'status'               => true,
                    'count'                => 1,
                    'primary_download_url' => $directFileUrl,
                    'is_direct'            => true,
                    'servers'              => [
                        [
                            'server_name'  => '⚡ Instant Fast DL (10Gbps CDN)',
                            'download_url' => $directFileUrl,
                            'is_direct'    => true
                        ]
                    ]
                ];
            }
        }
        return null;
    }

    public static function resolveHubDrive(string $url): ?array {
        $html = self::fetchHTML($url, ['Referer: https://new1.hdhub4u.af/']);
        if (!$html) return null;

        if (preg_match('/href=["\'](https?:\/\/hubcloud\.[^\/]+\/drive\/[^"\']+)["\']/i', $html, $hcMatch)) {
            return self::resolveHubCloud($hcMatch[1]);
        }

        if (preg_match('/href=["\'](https?:\/\/hubcloud\.[^\/]+\/tg\/[^"\']+)["\']/i', $html, $tgMatch)) {
            return [
                'status'               => true,
                'count'                => 1,
                'primary_download_url' => $tgMatch[1],
                'is_direct'            => false,
                'servers'              => [
                    [
                        'server_name'  => 'Telegram Direct Download',
                        'download_url' => $tgMatch[1],
                        'is_direct'    => false
                    ]
                ]
            ];
        }

        return null;
    }

    public static function resolveBuzz(string $url): ?string {
        $html = self::fetchHTML($url, ['Referer: https://hubcloud.cx/', 'Referer: https://hblinks.co/']);
        if (!$html) return null;

        if (preg_match('~hx-get=["\'](/[^"\']+/download\?t=[^"\']+)["\']~i', $html, $hxMatch)) {
            $downloadUrl = "https://bzzhr.co" . $hxMatch[1];
            $ch = curl_init($downloadUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 8);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Referer: {$url}",
                "hx-request: true",
                "Accept: */*",
                "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36"
            ]);
            $res = curl_exec($ch);
            curl_close($ch);

            if ($res && preg_match('/(?:hx-redirect|location):\s*(https?:\/\/[^\r\n]+)/i', $res, $rMatch)) {
                return trim($rMatch[1]);
            }
        }
        return null;
    }

    public static function resolveHubCloud(string $hubCloudUrl): ?array {
        $hubCloudUrl = str_replace(['hubcloud.ist', 'hubcloud.club', 'hubcloud.vg'], 'hubcloud.cx', $hubCloudUrl);
        $html = self::fetchHTML($hubCloudUrl, ['Referer: https://gamerxyt.com/', 'Referer: https://hblinks.co/']);
        if (!$html) return null;

        $generatorUrl = null;
        if (preg_match('/href=["\'](https?:\/\/[^"\']*hubcloud\.php[^"\']+)["\']/i', $html, $match)) {
            $generatorUrl = $match[1];
        } else {
            $generatorUrl = $hubCloudUrl;
        }

        $genHtml = self::fetchHTML($generatorUrl, ['Referer: https://gamerxyt.com/', "Referer: {$hubCloudUrl}"]);
        if (!$genHtml) return null;

        $servers = [];
        $dom = new \DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($genHtml, 'HTML-ENTITIES', 'UTF-8'));
        $xpath = new \DOMXPath($dom);

        $btnNodes = $xpath->query("//a[contains(@href, 'http')]");
        foreach ($btnNodes as $btn) {
            $href = $btn->getAttribute('href');
            $label = trim($btn->textContent);

            if (empty($href) || str_starts_with($href, '#') || str_contains($href, 'javascript:') || str_contains($href, 'tinyurl') || str_contains($href, 'one.one.one') || str_contains($href, 'google.com/search') || str_contains($href, 'bonuscaf') || str_contains($href, 'snvhost.com') || str_contains($href, 'telegram') || str_contains($href, 'tg/go')) {
                continue;
            }

            // 1. Buzz Server Check (resolve to direct ts.bzzhr.co or skip landing page)
            if (str_contains($href, 'bzzhr.co') || str_contains($href, 'buzzheavier')) {
                $buzzDirect = self::resolveBuzz($href);
                if ($buzzDirect) {
                    $servers[] = [
                        'server_name'  => 'Buzz Server (10Gbps Direct)',
                        'download_url' => $buzzDirect,
                        'is_direct'    => true
                    ];
                }
                continue;
            }

            if (stripos($label, 'Download') !== false || stripos($label, 'Server') !== false || stripos($label, 'FSL') !== false || stripos($label, 'Pixel') !== false || stripos($label, 'Zip') !== false || stripos($label, 'Fast') !== false) {
                $isDirect = false;
                $finalUrl = $href;

                if (preg_match('/video-downloads\.googleusercontent\.com|storage\.googleapis\.com|googleusercontent\.com|fsl-stream\.fsl\.|r2\.cloudflarestorage\.com|bunker\.monster|pongala\.life|workers\.dev/i', $href)) {
                    $isDirect = true;
                } elseif (preg_match('/pixeldrain\.(?:com|dev)\/u\/([a-zA-Z0-9]+)/i', $href, $pMatch)) {
                    $finalUrl = "https://pixeldrain.com/api/file/{$pMatch[1]}?download";
                    $isDirect = true;
                } elseif (preg_match('/pixel\.hubcloud|gpdl\.hubcloud|gamerxyt/i', $href)) {
                    $unwrapped = self::getFinalRedirectUrl($href);
                    if (!empty($unwrapped)) {
                        $finalUrl = $unwrapped;
                        if (preg_match('/googleusercontent\.com|storage\.googleapis\.com|fsl|pixeldrain|r2\.dev|cloudflarestorage|bunker|pongala|workers\.dev/i', $finalUrl)) {
                            $isDirect = true;
                        }
                    }
                }

                if ($isDirect) {
                    if (str_contains($finalUrl, 'googleusercontent.com') || str_contains($finalUrl, 'storage.googleapis.com')) {
                        $cleanServerName = '⚡ 10Gbps Google Fast Server';
                    } elseif (str_contains($finalUrl, 'workers.dev') || str_contains($finalUrl, 'cloudflarestorage') || str_contains($finalUrl, 'r2.dev')) {
                        preg_match('/([0-9.]+\s*(?:GB|MB))/i', $label, $sm);
                        $sizeStr = !empty($sm[1]) ? " ({$sm[1]})" : "";
                        $cleanServerName = "⚡ Cloud High-Speed Mirror{$sizeStr}";
                    } elseif (str_contains($finalUrl, 'pixeldrain')) {
                        $cleanServerName = '⚡ PixelDrain Direct';
                    } elseif (str_contains($finalUrl, 'fsl')) {
                        $cleanServerName = '⚡ FSL Fast Stream Server';
                    } else {
                        $cleanServerName = trim(preg_replace('/^Download\s*\[?|\]?$/i', '', $label));
                        $cleanServerName = trim(str_replace(['[', ']'], ' ', $cleanServerName));
                        $cleanServerName = "⚡ " . ($cleanServerName ?: "Direct CDN Server");
                    }

                    $servers[] = [
                        'server_name'  => $cleanServerName,
                        'download_url' => $finalUrl,
                        'is_direct'    => true
                    ];
                }
            }
        }

        $directOnlyServers = array_values(array_filter($servers, fn($s) => !empty($s['is_direct']) && $s['is_direct'] === true));
        $primaryUrl = !empty($directOnlyServers) ? $directOnlyServers[0]['download_url'] : null;

        return [
            'status'               => true,
            'count'                => count($directOnlyServers),
            'primary_download_url' => $primaryUrl,
            'servers'              => $directOnlyServers
        ];
    }

    public static function scrapeHomeFeed(int $page = 1): array {
        $baseUrl = self::getBaseUrl();
        $url = ($page === 1) ? $baseUrl . '/' : $baseUrl . "/page/{$page}/";
        $html = self::fetchHTML($url);
        if (!$html) return ['posts' => []];
        return ['posts' => self::parsePostCards($html)];
    }

    public static function searchPosts(string $query, int $page = 1): array {
        $baseUrl = self::getBaseUrl();
        $cleanQ = urlencode($query);
        $url = ($page === 1) ? "{$baseUrl}/search/{$cleanQ}/" : "{$baseUrl}/search/{$cleanQ}/page/{$page}/";
        $html = self::fetchHTML($url);
        if (!$html) return ['posts' => []];
        return ['posts' => self::parsePostCards($html)];
    }

    public static function scrapeCategoryFeed(string $category, int $page = 1): array {
        $baseUrl = self::getBaseUrl();
        $url = ($page === 1) ? "{$baseUrl}/category/{$category}/" : "{$baseUrl}/category/{$category}/page/{$page}/";
        $html = self::fetchHTML($url);
        if (!$html) return ['posts' => []];
        return ['posts' => self::parsePostCards($html)];
    }

    public static function parsePostCards(string $html): array {
        $items = [];
        $dom = new \DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        $xpath = new \DOMXPath($dom);

        $posts = $xpath->query("//li[contains(@class, 'thumb')] | //li[contains(@class, 'recent-posts')] | //article | //div[contains(@class, 'post')]");

        foreach ($posts as $post) {
            $aNodes = $xpath->query(".//figcaption//a | .//figure//a | .//a", $post);
            if ($aNodes->length === 0) continue;
            $aNode = $aNodes->item(0);
            $href = $aNode->getAttribute('href');

            $pNodes = $xpath->query(".//figcaption//p | .//p", $post);
            $title = '';
            if ($pNodes->length > 0) {
                $title = trim($pNodes->item(0)->textContent);
            }
            if (empty($title)) {
                $title = $aNode->getAttribute('title') ?: trim($aNode->textContent);
            }

            $imgNodes = $xpath->query(".//figure//img | .//img", $post);
            $img = '';
            if ($imgNodes->length > 0) {
                $imgNode = $imgNodes->item(0);
                $img = $imgNode->getAttribute('src') ?: ($imgNode->getAttribute('data-src') ?: $imgNode->getAttribute('data-lazy-src'));
                if (empty($title)) {
                    $title = $imgNode->getAttribute('title') ?: $imgNode->getAttribute('alt');
                }
            }

            $quality = '';
            $qNodes = $xpath->query(".//*[contains(@class, 'quality') or contains(@class, 'badge')]", $post);
            if ($qNodes->length > 0) $quality = trim($qNodes->item(0)->textContent);

            $slug = trim(parse_url($href, PHP_URL_PATH) ?? '', '/');
            if (str_contains($slug, '/')) {
                $parts = explode('/', $slug);
                $slug = end($parts);
            }

            $isSeries = (bool)preg_match('/season|episode|s0\d|complete|all-episodes/i', $title . ' ' . $slug);

            if (!empty($title) && !empty($href) && !empty($slug)) {
                $items[] = [
                    'title'      => html_entity_decode(trim($title), ENT_QUOTES, 'UTF-8'),
                    'slug'       => $slug,
                    'poster_url' => $img,
                    'post_url'   => $href,
                    'quality'    => $quality ?: 'HD',
                    'is_series'  => $isSeries
                ];
            }
        }

        return $items;
    }
}
