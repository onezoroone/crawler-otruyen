<?php

namespace Nqt\CrawlerOtruyen\Services;

use Backpack\Settings\app\Models\Setting;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\Laravel\Facades\Image;
use Throwable;

/**
 * Tải ảnh về storage local / S3 hoặc giữ URL gốc (remote). HTTP: Guzzle (Client + Pool).
 */
class CrawlerImageStorageService
{
    /** Client dùng chung cho tải ảnh (timeout / User-Agent). */
    protected ?Client $guzzleClient = null;

    public function __construct(
        protected CrawlerImportOptions $options
    ) {}

    /**
     * @param  string  $relativePath  Đường dẫn trên disk (vd: images/slug/cover.jpg)
     * @param  CrawlerImportOptions|null  $importOptions  Ghi đè theo lần import; null = inject mặc định
     * @param  bool  $applyResize  Bìa = true (theo Settings); ảnh chapter = false
     */
    public function mirrorUrl(string $sourceUrl, string $relativePath, ?CrawlerImportOptions $importOptions = null, bool $applyResize = true): string
    {
        $opt = $importOptions ?? $this->options;
        $mode = $opt->imageMode();
        if ($mode === 'remote' || $sourceUrl === '') {
            return $sourceUrl;
        }

        $body = $this->fetchImageBody($sourceUrl, $this->maxDownloadAttempts());
        if ($body === null) {
            Log::warning('Crawler: không tải được ảnh sau nhiều lần thử, giữ URL gốc.', ['url' => $sourceUrl]);

            return $sourceUrl;
        }

        try {
            return $this->processDownloadedImage($body, $relativePath, $sourceUrl, $opt, $applyResize);
        } catch (Throwable $e) {
            Log::warning('Crawler: lỗi xử lý ảnh, giữ URL gốc.', ['url' => $sourceUrl, 'error' => $e->getMessage()]);

            return $sourceUrl;
        }
    }

    /**
     * Tên khóa lưu trong image_servers: remote = giữ tên server từ API; s3/public = nhãn cố định (VIP / Thường).
     */
    public function resolveStoredServerKey(string $apiServerName, ?CrawlerImportOptions $importOptions = null): string
    {
        $opt = $importOptions ?? $this->options;
        if ($opt->imageMode() === 'remote') {
            return $apiServerName;
        }

        return $this->mirrorStorageLabel($opt);
    }

    /**
     * Nhãn khi mirror lên disk (không dùng khi remote).
     */
    protected function mirrorStorageLabel(CrawlerImportOptions $opt): string
    {
        return $opt->imageMode() === 's3'
            ? (string) config('crawler-otruyen.image_server_label_s3', 'VIP')
            : (string) config('crawler-otruyen.image_server_label_public', 'LC');
    }

    /**
     * Ảnh chương: tải song song (Guzzle Pool), không resize, retry tối đa theo config.
     *
     * @param  array<string, list<string>>  $imageServers
     * @return array<string, list<string>>
     */
    public function mirrorImageServers(array $imageServers, string $mangaSlug, float $chapterNumber, ?CrawlerImportOptions $importOptions = null): array
    {
        $opt = $importOptions ?? $this->options;
        if ($opt->imageMode() === 'remote') {
            return $imageServers;
        }

        $slugSafe = preg_replace('/[^a-z0-9._-]+/i', '-', $mangaSlug) ?: 'manga';
        $ch = (string) $chapterNumber;
        $label = $this->mirrorStorageLabel($opt);

        $jobs = [];
        $idx = 0;
        foreach ($imageServers as $urls) {
            if (! is_array($urls)) {
                continue;
            }
            foreach ($urls as $url) {
                $url = (string) $url;
                $ext = $this->guessExtension($url);
                $rel = "images/{$slugSafe}/ch-{$ch}/{$idx}.{$ext}";
                $jobs[] = ['idx' => $idx, 'url' => $url, 'rel' => $rel];
                $idx++;
            }
        }

        $total = count($jobs);
        if ($total === 0) {
            return [$label => []];
        }

        $concurrency = max(1, (int) config('crawler-otruyen.image_download_concurrency', 8));
        $maxAttempts = $this->maxDownloadAttempts();
        $results = array_fill(0, $total, '');

        /** @var array<int, string> $poolBodies key = idx job */
        $poolBodies = [];
        $client = $this->guzzleClient();

        $requests = static function () use ($jobs) {
            foreach ($jobs as $job) {
                yield new Request('GET', $job['url']);
            }
        };

        $pool = new Pool($client, $requests(), [
            'concurrency' => $concurrency,
            'fulfilled' => function ($response, $index) use (&$poolBodies, $jobs): void {
                $job = $jobs[$index];
                $i = $job['idx'];
                $code = $response->getStatusCode();
                if ($code >= 200 && $code < 300) {
                    $b = (string) $response->getBody();
                    if ($b !== '') {
                        $poolBodies[$i] = $b;
                    }
                }
            },
            'rejected' => static function ($reason, $index): void {
                // Retry trong vòng lặp job bên dưới
            },
        ]);

        $pool->promise()->wait();

        foreach ($jobs as $job) {
            $i = $job['idx'];
            $body = $poolBodies[$i] ?? null;
            if ($body === null) {
                $body = $this->fetchImageBody($job['url'], max(0, $maxAttempts - 1));
            }
            if ($body === null) {
                Log::warning('Crawler: chapter ảnh lỗi sau thử lại, giữ URL gốc.', ['url' => $job['url']]);
                $results[$i] = $job['url'];

                continue;
            }
            try {
                $results[$i] = $this->processDownloadedImage($body, $job['rel'], $job['url'], $opt, false);
            } catch (Throwable $e) {
                Log::warning('Crawler: lỗi encode WebP chapter, giữ URL gốc.', ['url' => $job['url'], 'error' => $e->getMessage()]);
                $results[$i] = $job['url'];
            }
        }

        return [$label => $results];
    }

    protected function guzzleClient(): Client
    {
        return $this->guzzleClient ??= new Client([
            'timeout' => (float) config('crawler-otruyen.image_download_timeout', 120),
            'connect_timeout' => 15.0,
            'headers' => $this->defaultHttpHeaders(),
            'http_errors' => false,
        ]);
    }

    /**
     * Tải body ảnh — tối đa $maxAttempts lần (tuần tự, có chờ ngắn).
     */
    protected function fetchImageBody(string $url, int $maxAttempts): ?string
    {
        $maxAttempts = max(0, $maxAttempts);
        if ($maxAttempts === 0) {
            return null;
        }

        $client = $this->guzzleClient();
        $lastError = null;
        for ($a = 1; $a <= $maxAttempts; $a++) {
            try {
                $response = $client->get($url);
                $code = $response->getStatusCode();
                if ($code >= 200 && $code < 300) {
                    $body = (string) $response->getBody();
                    if ($body !== '') {
                        return $body;
                    }
                }
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
            }
            if ($a < $maxAttempts) {
                usleep(150000);
            }
        }

        if ($lastError !== null) {
            Log::debug('Crawler: fetchImageBody hết lần thử.', ['url' => $url, 'error' => $lastError]);
        }

        return null;
    }

    protected function processDownloadedImage(string $body, string $relativePath, string $sourceUrl, CrawlerImportOptions $opt, bool $applyResize): string
    {
        $this->ensureMemoryLimitForImageProcessing();

        $mode = $opt->imageMode();
        $normalized = $this->ensureWebpExtension(
            $this->normalizeRelativePath($relativePath, $sourceUrl)
        );

        $disk = Storage::disk($mode === 's3' ? 's3' : 'public');
        if (! $disk instanceof FilesystemAdapter) {
            throw new \RuntimeException('Disk không hỗ trợ url().');
        }

        $image = Image::read($body);
        $maxEdge = max(0, (int) config('crawler-otruyen.image_max_edge_pixels', 0));
        if ($maxEdge > 0) {
            $image = $this->scaleDownIfExceedsMaxEdge($image, $maxEdge);
        }
        if ($applyResize) {
            $image = $this->applyResize($image, $opt);
        }
        $webpImage = $image->toWebp($this->webpQuality());

        if ($mode === 's3') {
            $disk->put($normalized, $webpImage, ['visibility' => 'public']);

            return $disk->url($normalized);
        }

        $disk->put($normalized, $webpImage);

        // Đường dẫn gốc site, không gắn APP_URL — đổi domain không cần sửa DB
        return $this->localPublicWebPath($normalized);
    }

    /**
     * Đường dẫn web tới file trên disk public (symlink public/storage).
     */
    protected function localPublicWebPath(string $relativeToPublicDisk): string
    {
        return '/storage/'.ltrim($relativeToPublicDisk, '/');
    }

    protected function defaultHttpHeaders(): array
    {
        return [
            'User-Agent' => (string) config('crawler-otruyen.user_agent', 'TruyenApp-Crawler/1.0'),
        ];
    }

    protected function maxDownloadAttempts(): int
    {
        return max(1, min(10, (int) config('crawler-otruyen.image_download_max_attempts', 3)));
    }

    protected function normalizeRelativePath(string $relativePath, string $sourceUrl): string
    {
        $relativePath = ltrim($relativePath, '/');
        if (str_contains($relativePath, '..')) {
            return 'images/'.md5($sourceUrl).'.webp';
        }

        return $relativePath;
    }

    /**
     * Đổi đuôi file lưu thành .webp (nội dung đã encode WebP trong mirrorUrl).
     */
    protected function ensureWebpExtension(string $relativePath): string
    {
        $relativePath = ltrim($relativePath, '/');
        if (preg_match('/\.webp$/i', $relativePath)) {
            return $relativePath;
        }
        if (preg_match('/\.[a-z0-9]+$/i', $relativePath)) {
            return (string) preg_replace('/\.[^.]+$/i', '.webp', $relativePath);
        }

        return $relativePath.'.webp';
    }

    /**
     * Resize theo Settings/config: cả W và H > 0 → cover (crop giữa); một cạnh → scaleDown.
     */
    /**
     * Tránh hết RAM khi ảnh scan quá lớn (GD giữ full bitmap trước khi encode WebP).
     */
    protected function ensureMemoryLimitForImageProcessing(): void
    {
        $limit = config('crawler-otruyen.image_memory_limit');
        if ($limit === null || $limit === '') {
            return;
        }
        @ini_set('memory_limit', (string) $limit);
    }

    /**
     * Thu nhỏ nếu một cạnh vượt ngưỡng — giữ tỷ lệ, giảm RAM lúc imagewebp().
     */
    protected function scaleDownIfExceedsMaxEdge(ImageInterface $image, int $maxEdge): ImageInterface
    {
        if ($maxEdge <= 0) {
            return $image;
        }
        $w = $image->width();
        $h = $image->height();
        if ($w <= $maxEdge && $h <= $maxEdge) {
            return $image;
        }
        if ($w >= $h) {
            return $image->scaleDown(width: $maxEdge);
        }

        return $image->scaleDown(height: $maxEdge);
    }

    protected function applyResize(ImageInterface $image, CrawlerImportOptions $opt): ImageInterface
    {
        $w = $opt->imageResizeWidth();
        $h = $opt->imageResizeHeight();
        if ($w <= 0 && $h <= 0) {
            return $image;
        }
        if ($w > 0 && $h > 0) {
            return $image->cover($w, $h);
        }
        if ($w > 0) {
            return $image->scaleDown(width: $w);
        }

        return $image->scaleDown(height: $h);
    }

    /**
     * Chất lượng nén WebP (1–100): Settings image_compression_ratio nếu có, không thì config webp_quality.
     */
    protected function webpQuality(): int
    {
        $fallback = max(1, min(100, (int) config('crawler-otruyen.webp_quality', 82)));
        if (! class_exists(Setting::class)) {
            return $fallback;
        }
        $v = Setting::get('image_compression_ratio', null);
        if ($v === null || $v === '') {
            return $fallback;
        }

        return max(1, min(100, (int) $v));
    }

    protected function guessExtension(string $url): string
    {
        return 'webp';
    }
}
