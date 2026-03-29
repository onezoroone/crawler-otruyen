<?php

namespace Nqt\CrawlerOtruyen\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Nqt\CrawlerOtruyen\Contracts\CrawlerSourceContract;
use Nqt\CrawlerOtruyen\CrawlerSourceManager;

/**
 * Import truyện/chương từ terminal — dùng nguồn crawl đã đăng ký (mặc định OTruyen).
 */
class CrawlerOtruyenImportCommand extends Command
{
    protected $signature = 'crawler-otruyen:import
                            {--source= : Khóa nguồn crawl (mặc định config crawler-otruyen.default)}
                            {--list : Lấy danh sách truyện từ API danh sách (theo trang)}
                            {--list-url= : URL API danh sách (mặc định theo nguồn)}
                            {--page-start=1 : Trang bắt đầu}
                            {--page-end=1 : Trang kết thúc}
                            {--slug=* : Slug hoặc URL truyện (lặp tùy ý)}
                            {--file= : File text: mỗi dòng một slug hoặc URL chi tiết}
                            {--image-mode= : Ghi đè chế độ ảnh: remote | local | s3}
                            {--rewrite : Bật viết lại mô tả OpenAI (ghi đè Settings)}
                            {--no-rewrite : Tắt viết lại mô tả (ghi đè Settings)}';

    protected $description = 'Cào & import manga/chapter — nguồn crawl lấy từ config (crawler-otruyen.sources)';

    public function handle(CrawlerSourceManager $manager): int
    {
        @set_time_limit(0);
        $mem = config('crawler-otruyen.image_memory_limit');
        if (is_string($mem) && $mem !== '') {
            @ini_set('memory_limit', $mem);
        }

        $this->logCrawlerLine(
            'info',
            'crawler-otruyen:import bắt đầu',
            ['started_at' => now()->toDateTimeString()]
        );

        $source = $this->resolveSource($manager);

        if ($this->option('list')) {
            $defaultList = $source->defaultListUrl() ?? '';
            $listUrl = trim((string) ($this->option('list-url') ?: $defaultList));
            if ($listUrl === '') {
                $this->error('Nguồn này không có URL danh sách mặc định; truyền --list-url=.');

                return self::FAILURE;
            }
            $start = (int) $this->option('page-start');
            $end = (int) $this->option('page-end');
            if ($end < $start) {
                $this->error('Giá trị --page-end phải lớn hơn hoặc bằng --page-start.');

                return self::FAILURE;
            }
            $this->info("Nguồn: {$source->label()} ({$source->id()})");
            $this->info("Chế độ danh sách: {$listUrl} (trang {$start} → {$end})");
            $mangas = $source->fetchCatalog($listUrl, $start, $end);
        } else {
            $lines = [];
            $fileOpt = $this->option('file');
            if ($fileOpt !== null && $fileOpt !== '') {
                $resolved = $this->resolveReadableFilePath((string) $fileOpt);
                if ($resolved === null) {
                    $this->error("Không đọc được file: {$fileOpt}");

                    return self::FAILURE;
                }
                $content = file_get_contents($resolved);
                if ($content === false) {
                    $this->error("Không đọc được nội dung file: {$resolved}");

                    return self::FAILURE;
                }
                $lines = array_merge($lines, preg_split('/\r\n|\r|\n/', $content) ?: []);
            }
            foreach ((array) $this->option('slug') as $s) {
                $lines[] = $s;
            }
            $mangas = $source->resolveMangasFromDetailLines(implode("\n", $lines));
        }

        $mangas = collect($mangas)
            ->unique('slug')
            ->values()
            ->all();

        if ($mangas === []) {
            $this->error('Không có truyện nào để import. Dùng --list hoặc --slug / --file.');

            return self::FAILURE;
        }

        $slugs = array_map(fn (array $m): string => (string) $m['slug'], $mangas);
        $this->info('Sẽ import '.count($slugs).' truyện (nguồn '.$source->id().').');

        if ($this->option('rewrite') && $this->option('no-rewrite')) {
            $this->error('Không dùng đồng thời --rewrite và --no-rewrite.');

            return self::FAILURE;
        }
        $imgOpt = $this->option('image-mode');
        if ($imgOpt !== null && $imgOpt !== '') {
            $m = strtolower(trim((string) $imgOpt));
            if (! in_array($m, ['remote', 'local', 's3'], true)) {
                $this->error('Giá trị --image-mode phải là remote, local hoặc s3.');

                return self::FAILURE;
            }
        }

        $importOpts = $this->buildImportOptionOverrides();
        if ($importOpts === null) {
            $this->comment('Tùy chọn ảnh / viết lại: theo Settings / .env.');
        } else {
            $this->comment('Tùy chọn ghi đè: '.json_encode($importOpts, JSON_UNESCAPED_UNICODE));
        }
        $this->newLine();

        $result = $source->importMangas($slugs, function (array $e): void {
            $this->writeLogEntry($e);
        }, $importOpts);

        $this->newLine();
        $this->table(
            ['Chỉ số', 'Giá trị'],
            [
                ['Truyện thành công', (string) ($result['mangas'] ?? 0)],
                ['Truyện lỗi (toàn phần)', (string) ($result['manga_failures'] ?? 0)],
                ['Chương ghi OK', (string) ($result['chapters'] ?? 0)],
                ['Chương lỗi', (string) ($result['chapter_failures'] ?? 0)],
            ]
        );

        $this->logCrawlerLine(
            'info',
            'crawler-otruyen:import kết thúc',
            [
                'finished_at' => now()->toDateTimeString(),
                'mangas_ok' => $result['mangas'] ?? 0,
                'manga_failures' => $result['manga_failures'] ?? 0,
                'chapters_ok' => $result['chapters'] ?? 0,
                'chapter_failures' => $result['chapter_failures'] ?? 0,
            ]
        );

        if (($result['mangas'] ?? 0) === 0 && count($slugs) > 0) {
            $this->logCrawlerLine(
                'warning',
                'crawler-otruyen:import thoát mã lỗi: không có truyện import thành công',
                ['finished_at' => now()->toDateTimeString(), 'slugs_count' => count($slugs)]
            );

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Ghi log daily (kênh crawler_otruyen) — mỗi dòng có timestamp đầy đủ ngày giờ theo app timezone.
     *
     * @param  array<string, mixed>  $context
     */
    protected function logCrawlerLine(string $level, string $message, array $context = []): void
    {
        Log::channel('crawler_otruyen')->log($level, $message, $context);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function buildImportOptionOverrides(): ?array
    {
        $overrides = [];
        $mode = $this->option('image-mode');
        if ($mode !== null && $mode !== '') {
            $overrides['image_mode'] = strtolower(trim((string) $mode));
        }
        if ($this->option('rewrite')) {
            $overrides['content_rewrite_enabled'] = true;
        }
        if ($this->option('no-rewrite')) {
            $overrides['content_rewrite_enabled'] = false;
        }

        return $overrides !== [] ? $overrides : null;
    }

    protected function resolveSource(CrawlerSourceManager $manager): CrawlerSourceContract
    {
        $opt = $this->option('source');
        if ($opt !== null && $opt !== '') {
            return $manager->driver((string) $opt);
        }

        return $manager->driver();
    }

    protected function writeLogEntry(array $entry): void
    {
        $msg = (string) ($entry['message'] ?? '');
        $state = (string) ($entry['state'] ?? '');

        match ($state) {
            'error' => $this->error($msg),
            'warning' => $this->warn($msg),
            default => $this->line($msg),
        };

        $level = match ($state) {
            'error' => 'error',
            'warning' => 'warning',
            default => 'info',
        };

        $this->logCrawlerLine($level, $msg, [
            'state' => $state,
            'scope' => $entry['scope'] ?? null,
            'slug' => $entry['slug'] ?? null,
        ]);
    }

    protected function resolveReadableFilePath(string $path): ?string
    {
        if ($path !== '' && is_readable($path) && is_file($path)) {
            return $path;
        }
        $base = base_path(trim($path, '/'));
        if (is_readable($base) && is_file($base)) {
            return $base;
        }

        return null;
    }
}
