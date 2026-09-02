<?php
namespace App\Services;

class VegaMoviesService {
    public const BASE_URL = 'https://vegamoviess.fo';

    /**
     * Resilient HTTP GET / POST client
     */
    public static function fetch(string $url, array $headers = [], ?array $postData = null): array {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_ENCODING, 'gzip, deflate');
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36');

        if (!empty($postData)) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        }

        $defaultHeaders = [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9',
            'Referer: ' . self::BASE_URL . '/'
        ];

        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($defaultHeaders, $headers));
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        return [
            'status' => ($code >= 200 && $code < 400),
            'code'   => $code,
            'url'    => $effectiveUrl,
            'body'   => $body ?: ''
        ];
    }

    /**
     * Get Latest Catalog Releases
     */
    public static function getLatest(int $page = 1): array {
        $url = ($page <= 1) ? self::BASE_URL . '/' : self::BASE_URL . "/page/{$page}/";
        $res = self::fetch($url);

        if (!$res['status'] || empty($res['body'])) {
            return [
                'posts'       => [],
                'page'        => $page,
                'total_pages' => 1,
                'source'      => 'vegamovies'
            ];
        }

        return self::parsePostList($res['body'], $page);
    }

    /**
     * Search VegaMovies Catalog
     */
    public static function search(string $query, int $page = 1): array {
        $query = trim($query);
        if (empty($query)) {
            return [
                'posts'       => [],
                'page'        => 1,
                'total_pages' => 1,
                'source'      => 'vegamovies'
            ];
        }

        $searchStart = max(0, ($page - 1) * 10);
        $resultFrom = $searchStart + 1;

        $postData = [
            'do'            => 'search',
            'subaction'     => 'search',
            'search_start'  => (string)$searchStart,
            'full_search'   => '0',
            'result_from'   => (string)$resultFrom,
            'story'         => $query
        ];

        $res = self::fetch(self::BASE_URL . '/index.php?do=search', [], $postData);

        if (!$res['status'] || empty($res['body'])) {
            return [
                'posts'       => [],
                'page'        => $page,
                'total_pages' => 1,
                'source'      => 'vegamovies'
            ];
        }

        return self::parsePostList($res['body'], $page);
    }

    /**
     * Get Single Post Details
     */
    public static function getDetails(string $slug): ?array {
        $cleanSlug = preg_replace('/\.html$/i', '', trim($slug));
        $url = self::BASE_URL . "/{$cleanSlug}.html";

        $res = self::fetch($url);
        if (!$res['status'] || empty($res['body'])) {
            return null;
        }

        $body = $res['body'];

        // Title
        $title = '';
        if (preg_match('/<h1[^>]*class=["\']header-single["\'][^>]*>(.*?)<\/h1>/is', $body, $tm)) {
            $title = trim(strip_tags($tm[1]));
        } elseif (preg_match('/<title>(.*?)<\/title>/is', $body, $tm)) {
            $title = trim(str_replace('Vegamovies', '', strip_tags($tm[1])));
            $title = trim($title, " |-");
        }

        // Poster
        $poster = '';
        if (preg_match('/<div[^>]*class=["\']full-story["\'][^>]*>.*?<img[^>]*src=["\']([^"\']+)["\']/is', $body, $pm)) {
            $poster = $pm[1];
        }
        if (empty($poster) || str_contains($poster, 'logo') || str_contains($poster, 'vegav2.png')) {
            if (preg_match('/<img[^>]*src=["\'](https?:\/\/[^"\']*(?:wp-content|uploads|catimages|imgur|images|posters|media)[^"\']*)["\']/is', $body, $pm2)) {
                $poster = $pm2[1];
            }
        }
        if (!empty($poster) && str_starts_with($poster, '/')) {
            $poster = self::BASE_URL . $poster;
        }

        // Synopsis
        $synopsis = 'Stream and download latest HD release on MaxPlex.';
        if (preg_match('/(?:Synopsis|Storyline|Plot|Story):?\s*(?:<\/[^>]+>)?\s*<p[^>]*>(.*?)<\/p>/is', $body, $sm)) {
            $synopsis = trim(strip_tags($sm[1]));
        }

        // Release Year
        $releaseYear = date('Y');
        if (preg_match('/\b(19\d\d|20\d\d)\b/', $title, $ym)) {
            $releaseYear = $ym[1];
        }

        // Extract Download & Stream Links (NexDrive, VGMLinks, FastDL, HubCloud)
        $downloads = [];
        $episodes = [];

        preg_match_all('/<a\s+[^>]*href=["\'](https:\/\/[^"\']*(?:nexdrive|vgmlinks|fast-dl|fastdl|hubcloud|gdflix|vcloud|filepress)[^"\']*)["\'][^>]*>(.*?)<\/a>/is', $body, $dlMatches, PREG_SET_ORDER);

        foreach ($dlMatches as $dl) {
            $href = $dl[1];
            $label = trim(strip_tags($dl[2]));

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

            $sizeStr = '';
            if (preg_match('/\[([0-9.]+\s*(?:MB|GB))\]/i', $label, $szM)) {
                $sizeStr = " ({$szM[1]})";
            }

            $downloads[] = [
                'quality'               => $qualityTag,
                'label'                 => ($qualityTag . $sizeStr) ?: $label,
                'url'                   => $href,
                'is_pack'               => stripos($label, 'zip') !== false || stripos($label, 'pack') !== false || stripos($label, 'all episodes') !== false,
                'download_resolver_api' => '/api/v1/media/download?url=' . urlencode($href)
            ];
        }

        return [
            'id'           => $cleanSlug,
            'title'        => $title,
            'slug'         => $cleanSlug,
            'source'       => 'vegamovies',
            'poster'       => $poster ?: 'https://max-plex.github.io/assets/logo.png',
            'synopsis'     => $synopsis,
            'release_year' => $releaseYear,
            'rating'       => '8.0/10',
            'genres'       => ['Action', 'Drama', 'Hindi Dubbed', 'Hollywood', 'Bollywood'],
            'downloads'    => $downloads,
            'episodes'     => $episodes,
            'streaming'    => []
        ];
    }

    /**
     * Resolve NexDrive URLs
     */
    public static function resolveNexDrive(string $nexDriveUrl): ?array {
        $res = self::fetch($nexDriveUrl);
        if (!$res['status'] || empty($res['body'])) {
            return null;
        }

        $body = $res['body'];
        $servers = [];

        // Look for Fast-DL / G-Direct
        if (preg_match('/<a\s+[^>]*href=["\'](https?:\/\/[^"\']*(?:fast-dl|fastdl)[^"\']*)["\'][^>]*>(.*?)<\/a>/is', $body, $fdm)) {
            $fdUrl = $fdm[1];
            $servers[] = [
                'server_name'  => '⚡ G-Direct Fast Server',
                'download_url' => $fdUrl,
                'is_direct'    => true
            ];
        }

        // Look for HubCloud / GDFlix / VGMLinks
        if (preg_match_all('/<a\s+[^>]*href=["\'](https?:\/\/[^"\']*(?:hubcloud|gdflix|vgmlinks|vcloud)[^"\']*)["\'][^>]*>(.*?)<\/a>/is', $body, $othMatches, PREG_SET_ORDER)) {
            foreach ($othMatches as $om) {
                $hUrl = $om[1];
                $name = trim(strip_tags($om[2])) ?: 'Cloud High-Speed';
                $servers[] = [
                    'server_name'  => $name,
                    'download_url' => $hUrl,
                    'is_direct'    => true
                ];
            }
        }

        if (empty($servers)) {
            return null;
        }

        return [
            'status'               => 1,
            'count'                => count($servers),
            'primary_download_url' => $servers[0]['download_url'] ?? '',
            'servers'              => $servers
        ];
    }

    /**
     * Helper to parse HTML post list
     */
    private static function parsePostList(string $html, int $page): array {
        $posts = [];
        preg_match_all('/<a\s+[^>]*href=["\']https:\/\/vegamoviess\.fo\/([0-9]+-[^"\']+\.html)["\'][^>]*>(.*?)<\/a>/is', $html, $links, PREG_SET_ORDER);

        foreach ($links as $l) {
            $slugWithHtml = $l[1];
            $inner = $l[2];
            $cleanSlug = preg_replace('/\.html$/i', '', $slugWithHtml);

            if (isset($posts[$cleanSlug])) {
                continue;
            }

            $img = '';
            if (preg_match('/<img[^>]*src=["\']([^"\']+)["\']/i', $inner, $im)) {
                $img = $im[1];
            }

            $title = '';
            if (preg_match('/<img[^>]*alt=["\']([^"\']+)["\']/i', $inner, $im)) {
                $title = $im[1];
            }
            if (empty($title)) {
                $title = trim(strip_tags($inner));
            }

            if (!empty($title) && strlen($title) > 4) {
                if (!empty($img) && str_starts_with($img, '/')) {
                    $img = self::BASE_URL . $img;
                }

                $releaseYear = date('Y');
                if (preg_match('/\b(19\d\d|20\d\d)\b/', $title, $ym)) {
                    $releaseYear = $ym[1];
                }

                $posts[$cleanSlug] = [
                    'id'           => $cleanSlug,
                    'title'        => $title,
                    'slug'         => $cleanSlug,
                    'poster'       => $img ?: 'https://max-plex.github.io/assets/logo.png',
                    'url'          => self::BASE_URL . "/{$slugWithHtml}",
                    'release_year' => $releaseYear,
                    'source'       => 'vegamovies'
                ];
            }
        }

        return [
            'posts'       => array_values($posts),
            'page'        => $page,
            'total_pages' => 100,
            'total_items' => count($posts),
            'source'      => 'vegamovies'
        ];
    }
}
