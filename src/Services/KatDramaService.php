<?php
namespace App\Services;

class KatDramaService {
    private const BASE_URL = 'https://new.katdrama.my';
    private const KMHD_URL = 'https://links.kmhd.me';
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    /**
     * Reusable HTTP helper with custom User-Agent and headers
     */
    public static function fetch(string $url, array $options = []): ?array {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, $options['follow'] ?? true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $options['timeout'] ?? 20);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HEADER, true);

        $headers = array_merge([
            'User-Agent: ' . self::USER_AGENT,
            'Accept: text/html,application/xhtml+xml,application/json,*/*',
            'Referer: ' . self::BASE_URL . '/'
        ], $options['headers'] ?? []);

        if (!empty($options['post'])) {
            curl_setopt($ch, CURLOPT_POST, true);
            if (isset($options['body'])) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $options['body']);
            }
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false || !empty($err)) {
            return null;
        }

        $headerText = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);

        // Extract set-cookie headers
        $cookies = [];
        if (preg_match_all('/^set-cookie:\s*([^;\r\n]+)/im', $headerText, $cMatches)) {
            $cookies = $cMatches[1];
        }

        return [
            'status'  => $httpCode,
            'headers' => $headerText,
            'cookies' => $cookies,
            'body'    => $body
        ];
    }

    /**
     * Helper to safely extract and parse SvelteKit stream resolve payload from HTML
     */
    public static function parseSvelteResolve(string $html): ?array {
        if (!preg_match('/\.resolve\(\s*(\{[\s\S]*?\})\s*\)\s*<\/script>/i', $html, $m)) {
            return null;
        }

        $jsObj = $m[1];
        $json = preg_replace('/:\s*void\s*0\b/i', ':null', $jsObj);
        $json = preg_replace('/([{,])\s*([a-zA-Z0-9_$]+)\s*:/', '$1"$2":', $json);

        return json_decode($json, true);
    }

    /**
     * Get K-Drama Catalog Feed (with pagination)
     */
    public static function getFeed(int $page = 1, ?string $category = null): array {
        $url = ($page === 1) ? self::BASE_URL . '/' : self::BASE_URL . "/page/{$page}";
        if (!empty($category)) {
            $catClean = urlencode(trim($category, '/'));
            $url = ($page === 1) ? self::BASE_URL . "/category/{$catClean}/" : self::BASE_URL . "/category/{$catClean}/page/{$page}";
        }

        $res = self::fetch($url);
        if (!$res || empty($res['body'])) {
            return ['posts' => [], 'page' => $page, 'total_pages' => 1];
        }

        $parsed = self::parseSvelteResolve($res['body']);
        $rawItems = $parsed['data']['data']['items'] ?? [];
        $totalPages = (int)($parsed['data']['data']['totalPages'] ?? 1);

        $items = [];
        foreach ($rawItems as $it) {
            $slug = $it['slug'] ?? '';
            $title = $it['post_title'] ?? $slug;
            $items[] = [
                'title'        => $title,
                'slug'         => $slug,
                'poster'       => $it['thumbnail_image'] ?? null,
                'url'          => self::BASE_URL . "/{$slug}",
                'is_series'    => true,
                'categories'   => $it['categories'] ?? ['k-drama'],
                'source'       => 'katdrama',
                'release_year' => self::extractYear($title)
            ];
        }

        return [
            'posts'       => $items,
            'page'        => $page,
            'total_pages' => $totalPages,
            'source'      => 'katdrama'
        ];
    }

    /**
     * Search K-Dramas by keyword using ?q={query}
     */
    public static function search(string $query, int $page = 1): array {
        $cleanQ = urlencode($query);
        $url = ($page === 1) ? self::BASE_URL . "/?q={$cleanQ}" : self::BASE_URL . "/page/{$page}?q={$cleanQ}";

        $res = self::fetch($url);
        if (!$res || empty($res['body'])) {
            return ['posts' => []];
        }

        $parsed = self::parseSvelteResolve($res['body']);
        if (!$parsed || empty($parsed['data']['success'])) {
            return ['posts' => []];
        }

        $rawItems = $parsed['data']['data']['items'] ?? ($parsed['data']['data']['posts'] ?? []);

        $items = [];
        foreach ($rawItems as $it) {
            $slug = $it['slug'] ?? '';
            $title = $it['post_title'] ?? $slug;
            $items[] = [
                'title'        => $title,
                'slug'         => $slug,
                'poster'       => $it['thumbnail_image'] ?? null,
                'url'          => self::BASE_URL . "/{$slug}",
                'is_series'    => true,
                'categories'   => $it['categories'] ?? ['k-drama'],
                'source'       => 'katdrama',
                'release_year' => self::extractYear($title)
            ];
        }

        return [
            'posts'       => $items,
            'page'        => $page,
            'total_pages' => (int)($parsed['data']['data']['totalPages'] ?? 1),
            'total_items' => (int)($parsed['data']['data']['totalItems'] ?? count($items))
        ];
    }

    /**
     * Get Details for a K-Drama by Slug
     */
    public static function getDetails(string $slug): ?array {
        $cleanSlug = trim($slug, '/');
        $url = self::BASE_URL . "/{$cleanSlug}";

        $res = self::fetch($url);
        if (!$res || empty($res['body'])) {
            return null;
        }

        $parsed = self::parseSvelteResolve($res['body']);
        $post = $parsed['data']['data'] ?? null;
        if (!$post && !isset($post['post_content'])) return null;

        $postContent = $post['post_content'] ?? '';

        // Extract title from postContent or slug
        $postTitle = $post['post_title'] ?? '';
        if (empty($postTitle)) {
            if (preg_match('/<h[1-4][^>]*>(.*?)<\/h[1-4]>/is', $postContent, $tm)) {
                $postTitle = trim(strip_tags($tm[1]));
            }
            if (empty($postTitle)) {
                $postTitle = ucwords(str_replace('-', ' ', $cleanSlug));
            }
        }

        // Extract poster
        $thumbnail = $post['thumbnail_image'] ?? null;
        if (empty($thumbnail)) {
            if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $postContent, $im)) {
                $thumbnail = $im[1];
            }
        }

        $categories = $post['categories'] ?? ['k-drama'];
        $synopsis = self::extractSynopsis($postContent);

        // Extract all download packs, episode links, and streams
        $downloads = [];
        $episodes  = [];
        $streams   = [];

        // Check for embedded play iframe
        if (preg_match('/<iframe\s+[^>]*src=["\']([^"\']+)["\']/i', $postContent, $ifMatch)) {
            $streams[] = [
                'server_name' => '⚡ KMHD Stream Player',
                'url'         => $ifMatch[1],
                'is_embed'    => true
            ];
        }

        // 1. Structured Episode-wise Extraction
        $epPattern = '/<(?:p|h[1-6]|div)[^>]*>(?:\s*<[^>]+>)*\s*(?:Episode|EP|Ep)\s*(\d+)(?:\s*\((?:END|FINALE)\))?\s*(?:<\/span>|<\/b>|<\/strong>)?\s*(?:-\s*([^<]+))?.*?(?:<\/(?:p|h[1-6]|div)>)\s*<(?:p|h[1-6]|div)[^>]*>(.*?)<\/(?:p|h[1-6]|div)>/is';
        if (preg_match_all($epPattern, $postContent, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $epNum = (int)$m[1];
                $epSubTitle = trim(strip_tags($m[2] ?? ''));
                $linksHtml = $m[3];

                $epTitle = "Episode {$epNum}" . (!empty($epSubTitle) ? " - " . trim($epSubTitle, " -.") : '');
                $qualities = [];

                if (preg_match_all('/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $linksHtml, $aMatches, PREG_SET_ORDER)) {
                    foreach ($aMatches as $am) {
                        $href = $am[1];
                        $label = trim(strip_tags($am[2]));

                        if (str_contains($href, 'catimages') || str_contains($href, 'imdb.com') || str_contains($href, 'youtube.com') || str_contains($href, 'telegram') || str_contains($href, 'katmoviehd')) {
                            continue;
                        }

                        if (str_contains($href, 'kmhd') || str_contains($href, 'gdflix') || str_contains($href, 'katdrive')) {
                            $qualityTag = 'HD';
                            if (stripos($label, '2160p') !== false || stripos($label, '4K') !== false) {
                                $qualityTag = '4K UHD';
                            } elseif (stripos($label, '1080p') !== false) {
                                $qualityTag = (stripos($label, 'HEVC') !== false || stripos($label, '10bit') !== false) ? '1080P HEVC' : '1080P';
                            } elseif (stripos($label, '720p') !== false) {
                                $qualityTag = (stripos($label, 'HEVC') !== false || stripos($label, '10bit') !== false) ? '720P HEVC' : '720P';
                            } elseif (stripos($label, '480p') !== false) {
                                $qualityTag = '480P';
                            }

                            $qualities[] = [
                                'quality'               => $qualityTag,
                                'label'                 => $label ?: $qualityTag,
                                'url'                   => $href,
                                'download_resolver_api' => '/api/v1/media/download?url=' . urlencode($href)
                            ];

                            // Add to downloads with episode prefix
                            $downloads[] = [
                                'episode'               => $epNum,
                                'quality'               => $qualityTag,
                                'label'                 => "Episode {$epNum} - " . ($label ?: $qualityTag),
                                'url'                   => $href,
                                'is_pack'               => false,
                                'download_resolver_api' => '/api/v1/media/download?url=' . urlencode($href)
                            ];
                        }
                    }
                }

                if (!empty($qualities)) {
                    $episodes[] = [
                        'episode_number'        => $epNum,
                        'episode_title'         => $epTitle,
                        'season_number'         => 1,
                        'qualities'             => $qualities,
                        'url'                   => $qualities[0]['url'] ?? null,
                        'download_resolver_api' => $qualities[0]['download_resolver_api'] ?? null
                    ];
                }
            }
        }

        // 2. Extract Batch / Zip Packs
        if (preg_match_all('/<a\s+[^>]*href=["\']([^"\']*(?:pack|zip|batch)[^"\']*)["\'][^>]*>(.*?)<\/a>/is', $postContent, $packMatches, PREG_SET_ORDER)) {
            foreach ($packMatches as $pm) {
                $href = $pm[1];
                $label = trim(strip_tags($pm[2]));
                $downloads[] = [
                    'episode'               => null,
                    'quality'               => 'Batch Pack',
                    'label'                 => $label ?: 'Batch Zip Pack',
                    'url'                   => $href,
                    'is_pack'               => true,
                    'download_resolver_api' => '/api/v1/media/download?url=' . urlencode($href)
                ];
            }
        }

        // 3. Fallback if no episodes were detected (e.g. Single Movies)
        if (empty($episodes) && empty($downloads)) {
            if (preg_match_all('/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $postContent, $aMatches, PREG_SET_ORDER)) {
                foreach ($aMatches as $am) {
                    $href = $am[1];
                    $label = trim(strip_tags($am[2]));

                    if (str_contains($href, 'catimages.org') || str_contains($href, 'imdb.com') || str_contains($href, 'youtube.com') || str_contains($href, 'telegram') || str_contains($href, 'katmoviehd.blue')) {
                        continue;
                    }

                    if (str_contains($href, 'kmhd') || str_contains($href, 'gdflix') || str_contains($href, 'katdrive')) {
                        $qualityTag = 'HD';
                        if (stripos($label, '2160p') !== false || stripos($label, '4K') !== false) {
                            $qualityTag = '4K UHD';
                        } elseif (stripos($label, '1080p') !== false) {
                            $qualityTag = (stripos($label, 'HEVC') !== false || stripos($label, '10bit') !== false) ? '1080P HEVC' : '1080P';
                        } elseif (stripos($label, '720p') !== false) {
                            $qualityTag = (stripos($label, 'HEVC') !== false || stripos($label, '10bit') !== false) ? '720P HEVC' : '720P';
                        } elseif (stripos($label, '480p') !== false) {
                            $qualityTag = '480P';
                        }

                        $downloads[] = [
                            'quality'               => $qualityTag,
                            'label'                 => $label ?: $qualityTag,
                            'url'                   => $href,
                            'is_pack'               => str_contains($href, '/pack/'),
                            'download_resolver_api' => '/api/v1/media/download?url=' . urlencode($href)
                        ];
                    }
                }
            }
        }

        return [
            'id'           => $cleanSlug,
            'title'        => $postTitle,
            'slug'         => $cleanSlug,
            'source'       => 'katdrama',
            'poster'       => $thumbnail,
            'synopsis'     => $synopsis,
            'release_year' => self::extractYear($postTitle),
            'rating'       => '8.2/10',
            'genres'       => $categories,
            'languages'    => ['Hindi Dubbed', 'Korean (Original)'],
            'is_series'    => true,
            'downloads'    => $downloads,
            'episodes'     => $episodes,
            'streaming'    => $streams
        ];
    }

    /**
     * Resolve KMHD Links (Packs, Files, Archives, and Play Streams)
     * Performs unlock bypass and executes multi-mirror sequential fallback.
     */
    public static function resolveKmhd(string $kmhdUrl): ?array {
        // 0. Direct KatDrive Link
        if (str_contains($kmhdUrl, 'katdrive')) {
            return self::resolveKatDrive($kmhdUrl);
        }

        // 1. KMHD Archive Page (/archives/{id})
        if (str_contains($kmhdUrl, '/archives/')) {
            return self::resolveKmhdArchive($kmhdUrl);
        }

        // Normalize alias domains (kmhd.eu -> kmhd.me)
        $normalizedUrl = str_replace(['links.kmhd.eu', 'gd.kmhd.eu'], 'links.kmhd.me', $kmhdUrl);
        $parsedUrl = parse_url($normalizedUrl);
        $path = $parsedUrl['path'] ?? '';

        // 2. Pack of Episodes (/pack/{id})
        if (str_starts_with($path, '/pack/')) {
            return self::resolveKmhdPack($normalizedUrl);
        }

        // 3. Play Stream (/play?id=...)
        if (str_starts_with($path, '/play')) {
            return self::resolveKmhdPlay($normalizedUrl);
        }

        // 4. Single File or Episode (/file/{id})
        return self::resolveKmhdFile($normalizedUrl);
    }

    /**
     * Resolve KatDrive page (e.g. katdrive.click/file/{id}) to HubCloud direct 10Gbps link
     */
    public static function resolveKatDrive(string $katDriveUrl): ?array {
        $html = ScraperService::fetchHTML($katDriveUrl);
        if (empty($html)) return null;

        $fileName = 'KatDrive File';
        if (preg_match('/<title>\s*(?:Katdrive\s*\|\s*)?(.*?)\s*<\/title>/is', $html, $tm)) {
            $fileName = trim($tm[1]);
        }

        // Search for HubCloud drive link in KatDrive
        if (preg_match('/href=["\'](https?:\/\/[^\s"\'<>]*hubcloud[^\s"\'<>]*(?:\/drive\/|\?id=)[^\s"\'<>]*)["\']/i', $html, $hm)) {
            $hubCloudUrl = $hm[1];
            $directRes = ScraperService::resolveHubCloud($hubCloudUrl);
            if ($directRes && !empty($directRes['servers'])) {
                return [
                    'is_direct'            => true,
                    'download_type'        => 'katdrive_hubcloud_direct',
                    'file_name'            => $fileName,
                    'primary_download_url' => $directRes['primary_download_url'] ?? null,
                    'direct_download_url'  => $directRes['primary_download_url'] ?? null,
                    'servers'              => $directRes['servers']
                ];
            }
        }

        return null;
    }

    /**
     * Resolve KMHD Archive page (e.g. kmhd.eu/archives/{id}) containing multi-mirrors
     */
    public static function resolveKmhdArchive(string $archiveUrl): ?array {
        $html = ScraperService::fetchHTML($archiveUrl);
        if (empty($html)) return null;

        $pageTitle = 'K-Drama Download';
        if (preg_match('/<title>(.*?)<\/title>/is', $html, $tm)) {
            $pageTitle = trim(str_replace(['– KMHD', '- KMHD', '&#8211; KMHD'], '', html_entity_decode($tm[1])));
        }

        $servers = [];
        $primaryDirectUrl = null;

        // 1. Extract KatDrive link and attempt 10Gbps direct resolution
        if (preg_match('/href=["\'](https?:\/\/[^\s"\'<>]*katdrive[^\s"\'<>]*)/i', $html, $km)) {
            $katDriveUrl = $km[1];
            $kdRes = self::resolveKatDrive($katDriveUrl);
            if ($kdRes && !empty($kdRes['servers'])) {
                $primaryDirectUrl = $kdRes['primary_download_url'] ?? null;
                foreach ($kdRes['servers'] as $srv) {
                    $servers[] = $srv;
                }
            }
        }

        // 2. Extract HubCloud links directly from archive page if any
        if (preg_match_all('/href=["\'](https?:\/\/[^\s"\'<>]*hubcloud[^\s"\'<>]*(?:\/drive\/|\/file\/)[^\s"\'<>]*)["\']/i', $html, $hAll)) {
            foreach (array_unique($hAll[1]) as $hubUrl) {
                if (!$primaryDirectUrl) {
                    $hubRes = ScraperService::resolveHubCloud($hubUrl);
                    if ($hubRes && !empty($hubRes['servers'])) {
                        $primaryDirectUrl = $hubRes['primary_download_url'] ?? null;
                        foreach ($hubRes['servers'] as $srv) {
                            $servers[] = $srv;
                        }
                    }
                }
            }
        }

        // 3. Check for any direct Pixeldrain, Google, or R2 links in page
        if (preg_match_all('/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $href = $m[1];
                $text = trim(strip_tags($m[2]));

                if (empty($href) || str_starts_with($href, '#') || str_contains($href, 'kmhd.eu') || str_contains($href, 'wp-') || str_contains($href, 'catimages')) {
                    continue;
                }

                // Check for direct Pixeldrain download
                if (preg_match('/pixeldrain\.(?:com|dev)\/u\/([a-zA-Z0-9]+)/i', $href, $pMatch)) {
                    $servers[] = [
                        'server_name'  => '⚡ PixelDrain Direct Server',
                        'download_url' => "https://pixeldrain.com/api/file/{$pMatch[1]}?download",
                        'is_direct'    => true
                    ];
                } elseif (preg_match('/storage\.googleapis\.com|googleusercontent\.com|fsl-stream\.fsl\.|r2\.cloudflarestorage\.com|bunker\.monster|pongala\.life|workers\.dev/i', $href)) {
                    $servers[] = [
                        'server_name'  => '⚡ Fast Direct CDN',
                        'download_url' => $href,
                        'is_direct'    => true
                    ];
                }
            }
        }

        // Filter: STRICTLY return ONLY is_direct = true servers
        $directServers = array_values(array_filter($servers, function($s) {
            return !empty($s['is_direct']) && $s['is_direct'] === true;
        }));

        if (empty($directServers)) {
            return null;
        }

        $primaryDirectUrl = $directServers[0]['download_url'] ?? null;

        return [
            'is_direct'            => true,
            'download_type'        => 'archive_direct_mirrors',
            'file_name'            => $pageTitle,
            'primary_download_url' => $primaryDirectUrl,
            'direct_download_url'  => $primaryDirectUrl,
            'servers'              => $directServers,
            'original_url'         => $archiveUrl
        ];
    }

    /**
     * Resolve KMHD Episode Pack to individual playable/downloadable episodes
     */
    private static function resolveKmhdPack(string $packUrl): ?array {
        $parsedUrl = parse_url($packUrl);
        $host = ($parsedUrl['scheme'] ?? 'https') . '://' . ($parsedUrl['host'] ?? 'links.kmhd.me');

        $res = self::fetch($packUrl);
        if (!$res || empty($res['body'])) return null;

        $parsed = self::parseSvelteResolve($res['body']);
        $packData = $parsed['data'] ?? [];
        $info = $packData['info'] ?? [];
        $packName = $packData['name'] ?? 'K-Drama Episode Pack';

        $episodes = [];
        foreach ($info as $epKey => $epVal) {
            $epName = $epVal['name'] ?? $epKey;
            $epFileUrl = "{$host}/file/{$epKey}";

            $episodes[] = [
                'id'                    => $epKey,
                'episode_name'          => $epName,
                'file_url'              => $epFileUrl,
                'download_resolver_api' => '/api/v1/media/download?url=' . urlencode($epFileUrl)
            ];
        }

        return [
            'is_direct'              => false,
            'is_pack'                => true,
            'download_type'          => 'episode_pack',
            'pack_name'              => $packName,
            'total_episodes'         => count($episodes),
            'episodes'               => $episodes,
            'original_url'           => $packUrl
        ];
    }

    /**
     * Resolve KMHD Play Page to streaming provider embeds
     */
    private static function resolveKmhdPlay(string $playUrl): ?array {
        $res = self::fetch($playUrl);
        if (!$res || empty($res['body'])) return null;

        $parsed = self::parseSvelteResolve($res['body']);
        $info = $parsed['data']['info'] ?? [];
        $streamEpisodes = [];

        foreach ($info as $epKey => $epVal) {
            $epName = $epVal['name'] ?? $epKey;
            $wishId = $epVal['streamwish_res'] ?? null;
            $tapeId = $epVal['streamtape_res'] ?? null;

            $servers = [];
            if (!empty($wishId) && $wishId !== 'None') {
                $servers[] = [
                    'server_name' => 'StreamWish (Fast Stream)',
                    'stream_url'  => "https://streamwish.to/e/{$wishId}",
                    'is_embed'    => true
                ];
            }
            if (!empty($tapeId) && $tapeId !== 'None') {
                $servers[] = [
                    'server_name' => 'StreamTape (HD)',
                    'stream_url'  => "https://streamtape.com/e/{$tapeId}",
                    'is_embed'    => true
                ];
            }

            $streamEpisodes[] = [
                'episode_id'   => $epKey,
                'episode_name' => $epName,
                'streams'      => $servers
            ];
        }

        return [
            'is_direct'      => false,
            'download_type'  => 'stream_player_episodes',
            'total_episodes' => count($streamEpisodes),
            'episodes'       => $streamEpisodes,
            'original_url'   => $playUrl
        ];
    }

    /**
     * Resolve KMHD File (/file/{id}) with unlock bypass and multi-mirror fallback
     */
    private static function resolveKmhdFile(string $fileUrl): ?array {
        $parsed = parse_url($fileUrl);
        $path = $parsed['path'] ?? '';
        if (empty($path)) return null;

        $host = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? 'links.kmhd.me');

        // Step 1: Unlock gateway
        $redirectB64 = base64_encode($path);
        $unlockUrl = "{$host}/locked?/unlock&redirect=" . urlencode($redirectB64);

        $unlockRes = self::fetch($unlockUrl, [
            'post'    => true,
            'body'    => '',
            'follow'  => false,
            'headers' => [
                'Content-Type: application/x-www-form-urlencoded',
                'Referer: ' . "{$host}/locked?redirect={$redirectB64}",
                'Origin: ' . $host
            ]
        ]);

        $cookieHeader = 'unlocked=true';
        if (!empty($unlockRes['cookies'])) {
            $cookieHeader = implode('; ', $unlockRes['cookies']);
        }

        // Step 2: Fetch unlocked file page
        $fileRes = self::fetch("{$host}{$path}", [
            'headers' => [
                'Cookie: ' . $cookieHeader,
                'Referer: ' . "{$host}/locked?redirect={$redirectB64}"
            ]
        ]);

        if (!$fileRes || empty($fileRes['body'])) return null;

        $parsed = self::parseSvelteResolve($fileRes['body']);
        $val = $parsed['data']['val'] ?? [];
        $uploadLinks = $val['upload_links'] ?? [];
        $fileName = $val['name'] ?? 'K-Drama File';
        $fileSize = $val['size'] ?? 0;

        // Step 3: Multi-Mirror Fallback Collection
        $candidateMirrors = [];

        // 1. HubCloud Mirror (Primary 10Gbps Google CDN / Cloudflare R2)
        if (!empty($uploadLinks['hubdrive_res']) && $uploadLinks['hubdrive_res'] !== 'None') {
            $candidateMirrors[] = [
                'type' => 'hubcloud',
                'url'  => 'https://hubcloud.cx/drive/' . $uploadLinks['hubdrive_res']
            ];
        }

        // 2. FFast Direct Mirror
        if (!empty($uploadLinks['ffast_res']) && $uploadLinks['ffast_res'] !== 'None') {
            $candidateMirrors[] = [
                'type' => 'ffast',
                'url'  => 'https://fuckingfast.net/' . $uploadLinks['ffast_res']
            ];
        }

        // 3. GDFlix Mirror
        if (!empty($uploadLinks['gdflix_res']) && $uploadLinks['gdflix_res'] !== 'None') {
            $candidateMirrors[] = [
                'type' => 'gdflix',
                'url'  => 'https://gd.kmhd.eu/file/' . $uploadLinks['gdflix_res']
            ];
        }

        // 4. KatDrive Mirror
        if (!empty($uploadLinks['katdrive_res']) && $uploadLinks['katdrive_res'] !== 'None') {
            $candidateMirrors[] = [
                'type' => 'katdrive',
                'url'  => 'https://katdrive.click/file/' . $uploadLinks['katdrive_res']
            ];
        }

        // 5. Send.cm Mirror
        if (!empty($uploadLinks['sendcm_res']) && $uploadLinks['sendcm_res'] !== 'None') {
            $candidateMirrors[] = [
                'type' => 'sendcm',
                'url'  => 'https://send.cm/' . $uploadLinks['sendcm_res']
            ];
        }

        // Step 4: Execute Sequential Fallback
        $resolvedServers = [];
        $primaryUrl = null;

        foreach ($candidateMirrors as $mirror) {
            if ($mirror['type'] === 'hubcloud') {
                $hcRes = ScraperService::resolveHubCloud($mirror['url']);
                if ($hcRes && !empty($hcRes['servers'])) {
                    $directOnly = array_values(array_filter($hcRes['servers'], fn($s) => !empty($s['is_direct']) && $s['is_direct'] === true));
                    if (!empty($directOnly)) {
                        $resolvedServers = array_merge($resolvedServers, $directOnly);
                        $primaryUrl = $directOnly[0]['download_url'];
                        break; // Primary 10Gbps resolved!
                    }
                }
            } elseif ($mirror['type'] === 'katdrive') {
                $kdRes = self::resolveKatDrive($mirror['url']);
                if ($kdRes && !empty($kdRes['servers'])) {
                    $directOnly = array_values(array_filter($kdRes['servers'], fn($s) => !empty($s['is_direct']) && $s['is_direct'] === true));
                    if (!empty($directOnly)) {
                        $resolvedServers = array_merge($resolvedServers, $directOnly);
                        if (!$primaryUrl) $primaryUrl = $directOnly[0]['download_url'];
                        break;
                    }
                }
            } elseif ($mirror['type'] === 'ffast') {
                $resolvedServers[] = [
                    'server_name'  => '⚡ FuckingFast Direct (High-Speed)',
                    'download_url' => $mirror['url'],
                    'is_direct'    => true
                ];
                if (!$primaryUrl) $primaryUrl = $mirror['url'];
            }
        }

        // Strictly filter to ensure only is_direct = true servers
        $directServers = array_values(array_filter($resolvedServers, fn($s) => !empty($s['is_direct']) && $s['is_direct'] === true));

        if (empty($directServers)) {
            return null;
        }

        $primaryUrl = $directServers[0]['download_url'];

        return [
            'is_direct'            => true,
            'download_type'        => 'kdrama_direct_cdn',
            'file_name'            => $fileName,
            'file_size_bytes'      => $fileSize,
            'primary_download_url' => $primaryUrl,
            'direct_download_url'  => $primaryUrl,
            'servers'              => $directServers,
            'original_url'         => $fileUrl
        ];
    }

    private static function extractYear(string $title): string {
        if (preg_match('/\b(19\d\d|20\d\d)\b/', $title, $m)) {
            return $m[1];
        }
        return date('Y');
    }

    private static function extractSynopsis(string $html): string {
        if (preg_match('/<p[^>]*>(.*?)<\/p>/is', $html, $m)) {
            $text = trim(strip_tags($m[1]));
            if (strlen($text) > 30) return $text;
        }
        return 'Watch and download the latest K-Drama episodes in Hindi Dubbed & Korean Dual Audio.';
    }
}
