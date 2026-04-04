<?php

namespace Nqt\CrawlerOtruyen\Services;

use App\Models\Author;
use App\Models\Chapter;
use App\Models\Genre;
use App\Models\Manga;
use App\Models\Tag;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Nqt\CrawlerOtruyen\Exceptions\CrawlerImportAbortedException;

/**
 * Bước 3: đồng bộ Manga + Chapter từ API OTruyen vào DB app.
 */
class OtruyenMangaImporter
{
    /** Ghi đè tùy chọn theo lần import (wizard / CLI); null = chỉ dùng Settings/config. */
    protected ?CrawlerImportOptions $runtimeImportOptions = null;

    public function __construct(
        protected OtruyenApiClient $client,
        protected CrawlerImportOptions $importOptions,
        protected OpenAiContentRewriter $contentRewriter,
        protected CrawlerImageStorageService $imageStorage
    ) {}

    protected function effectiveImportOptions(): CrawlerImportOptions
    {
        return $this->runtimeImportOptions ?? $this->importOptions;
    }

    /**
     * @param  array<int, string>  $slugs
     * @param  callable|null  $onLog  Gọi mỗi dòng log (stream SSE / UI)
     * @param  array<string, mixed>|null  $importOptionOverrides  Ghi đè image_mode, content_rewrite_enabled
     * @return array{
     *     mangas: int,
     *     chapters: int,
     *     errors: list<array{slug: string, message: string}>,
     *     log: list<array<string, mixed>>,
     *     manga_failures: int,
     *     chapter_failures: int,
     *     aborted?: bool
     * }
     */
    public function importMangas(array $slugs, ?callable $onLog = null, ?array $importOptionOverrides = null): array
    {
        $this->runtimeImportOptions = null;
        if ($importOptionOverrides !== null && $importOptionOverrides !== []) {
            $this->runtimeImportOptions = $this->importOptions->withOverrides($importOptionOverrides);
        }

        try {
            return $this->runImportMangas($slugs, $onLog);
        } finally {
            $this->runtimeImportOptions = null;
        }
    }

    /**
     * @param  array<int, string>  $slugs
     * @return array<string, mixed>
     */
    protected function runImportMangas(array $slugs, ?callable $onLog): array
    {
        ignore_user_abort(false);

        $mangasDone = 0;
        $chaptersDone = 0;
        $mangaFailures = 0;
        $chapterFailures = 0;
        $errors = [];
        $log = [];
        $aborted = false;

        $emit = function (array $entry) use (&$log, $onLog): void {
            $log[] = $entry;
            if ($onLog !== null) {
                $onLog($entry);
            }
        };

        $unique = array_values(array_filter(array_unique($slugs), fn (string $s): bool => $s !== ''));
        $total = count($unique);

        $emit([
            'state' => 'running',
            'scope' => 'batch',
            'message' => "Bắt đầu import {$total} truyện.",
        ]);

        foreach ($unique as $index => $slug) {
            try {
                $this->assertClientStillConnected($emit);
            } catch (CrawlerImportAbortedException) {
                $aborted = true;
                break;
            }

            $pos = $index + 1;
            $emit([
                'state' => 'pending',
                'scope' => 'manga',
                'slug' => $slug,
                'message' => "[Chờ] ({$pos}/{$total}) Truyện `{$slug}` — trong hàng đợi.",
            ]);

            $emit([
                'state' => 'running',
                'scope' => 'manga',
                'slug' => $slug,
                'message' => "[Đang chạy] ({$pos}/{$total}) Đang tải & ghi truyện `{$slug}`…",
            ]);

            try {
                [$title, $chapterCount, $chErr] = $this->importOneManga($slug, $emit, $pos, $total);
                $chaptersDone += $chapterCount;
                $chapterFailures += $chErr;
                $mangasDone++;
                $emit([
                    'state' => 'success',
                    'scope' => 'manga',
                    'slug' => $slug,
                    'manga_title' => $title,
                    'chapters_ok' => $chapterCount,
                    'chapters_fail' => $chErr,
                    'message' => "[Thành công] Truyện «{$title}» (`{$slug}`) — {$chapterCount} chương ghi OK".
                        ($chErr > 0 ? ", {$chErr} chương lỗi." : '.'),
                ]);
            } catch (CrawlerImportAbortedException) {
                $aborted = true;
                break;
            } catch (\Throwable $e) {
                $mangaFailures++;
                $errors[] = [
                    'slug' => $slug,
                    'message' => $e->getMessage(),
                ];
                $emit([
                    'state' => 'error',
                    'scope' => 'manga',
                    'slug' => $slug,
                    'message' => "[Thất bại] Truyện `{$slug}`: ".$e->getMessage(),
                ]);
            }
        }

        if ($aborted) {
            $emit([
                'state' => 'warning',
                'scope' => 'batch',
                'message' => 'Đã dừng cào theo yêu cầu (kết nối đã đóng). Phần đã xử lý vẫn được lưu.',
            ]);
        } else {
            $emit([
                'state' => 'success',
                'scope' => 'batch',
                'message' => "Hoàn tất: {$mangasDone} truyện OK, {$mangaFailures} truyện lỗi; {$chaptersDone} chương OK, {$chapterFailures} chương lỗi.",
            ]);
        }

        return [
            'mangas' => $mangasDone,
            'chapters' => $chaptersDone,
            'errors' => $errors,
            'log' => $log,
            'manga_failures' => $mangaFailures,
            'chapter_failures' => $chapterFailures,
            'aborted' => $aborted,
        ];
    }

    /**
     * Client đóng tab / bấm Dừng → ngắt kết nối; PHP có thể phát hiện qua connection_aborted().
     *
     * @param  callable(array<string, mixed>): void  $emit
     *
     * @throws CrawlerImportAbortedException
     */
    protected function assertClientStillConnected(callable $emit): void
    {
        if (connection_aborted()) {
            $emit([
                'state' => 'warning',
                'scope' => 'batch',
                'message' => '[Đã dừng] Phát hiện ngắt kết nối — dừng cào.',
            ]);
            throw new CrawlerImportAbortedException('Client disconnected.');
        }
    }

    /**
     * @param  callable(array<string, mixed>): void  $emit
     * @return array{0: string, 1: int, 2: int} [title, chapters_ok, chapters_fail]
     */
    protected function importOneManga(string $slug, callable $emit, int $position, int $total): array
    {
        $detailUrl = 'https://otruyenapi.com/v1/api/truyen-tranh/'.rawurlencode($slug);
        $payload = $this->client->getJson($detailUrl);
        $item = $payload['data']['item'] ?? null;
        if (! is_array($item)) {
            throw new \RuntimeException('Thiếu data.item trong chi tiết truyện.');
        }

        $cdnImage = rtrim((string) ($payload['data']['APP_DOMAIN_CDN_IMAGE'] ?? 'https://img.otruyenapi.com'), '/');
        $title = (string) ($item['name'] ?? $slug);

        $manga = DB::transaction(function () use ($item, $cdnImage) {
            $manga = $this->upsertManga($item, $cdnImage);
            $this->syncGenres($manga, $item['category'] ?? []);
            $this->syncAuthors($manga, $item['author'] ?? []);
            $this->syncTags($manga, $item['tag'] ?? ['otruyen']);

            return $manga;
        });

        $emit([
            'state' => 'success',
            'scope' => 'manga_meta',
            'slug' => $slug,
            'manga_title' => $title,
            'message' => "[OK] Đã lưu thông tin truyện «{$title}» (`{$slug}`). Bắt đầu đồng bộ chương…",
        ]);

        [$chapterOk, $chapterFail] = $this->importChapters(
            $manga,
            $item['chapters'] ?? [],
            $emit,
            $slug,
            $title,
            $position,
            $total
        );

        DB::transaction(function () use ($manga) {
            $manga->refresh();
            $max = Chapter::query()->where('manga_id', $manga->id)->max('chapter_number');
            if ($max !== null) {
                $manga->update(['latest_chapter_number' => (int) floor((float) $max)]);
            }
        });

        return [$title, $chapterOk, $chapterFail];
    }

    protected function upsertManga(array $item, string $cdnImage): Manga
    {
        $slug = $item['slug'] ?? '';
        $thumb = (string) ($item['thumb_url'] ?? '');
        $cover = $thumb !== ''
            ? $cdnImage.'/uploads/comics/'.$thumb
            : null;

        $opt = $this->effectiveImportOptions();
        if ($cover !== null && $cover !== '') {
            $slugSafe = preg_replace('/[^a-z0-9._-]+/i', '-', (string) $slug) ?: 'cover';
            $ext = pathinfo(parse_url($cover, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION) ?: 'jpg';
            $coverOpt = $opt->imageMode() === 'remote'
                ? $opt->withOverrides(['image_mode' => 'local'])
                : $opt;
            $cover = $this->imageStorage->mirrorUrl($cover, "images/{$slugSafe}/cover.{$ext}", $coverOpt);
        }

        $description = $item['content'] ?? '';

        if ($description !== null && $description !== '') {
            try {
                $description = $this->contentRewriter->rewriteSynopsis($description, $opt);
            } catch (\Throwable $e) {
                Log::warning('Crawler: mô tả OpenAI lỗi, giữ bản gốc.', ['message' => $e->getMessage()]);
            }
        }

        $status = $this->mapMangaStatus((string) ($item['status'] ?? 'ongoing'));

        $originRaw = $item['origin_name'] ?? [];
        $origin_name = is_array($originRaw)
            ? implode(', ', $originRaw)
            : (string) $originRaw;

        $attributes = [
            'title' => (string) ($item['name'] ?? $slug),
            'alternative_title' => $origin_name,
            'description' => $description,
            'cover_image' => $cover,
            'status' => $status,
            'last_chapter_at' => now(),
        ];

        $existing = Manga::query()->where('slug', $slug)->first();
        if ($existing === null && (bool) config('crawler-otruyen.auto_publish_manga', true)) {
            $attributes['published_at'] = now();
        }

        return Manga::query()->updateOrCreate(
            ['slug' => $slug],
            $attributes
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $categories
     */
    protected function syncGenres(Manga $manga, array $categories): void
    {
        $ids = [];
        foreach ($categories as $cat) {
            if (! is_array($cat)) {
                continue;
            }
            $gName = (string) ($cat['name'] ?? '');
            if ($gName === '') {
                continue;
            }
            $genre = Genre::query()->firstOrCreate(
                ['name_md5' => md5($gName)],
                ['name' => $gName]
            );
            $ids[] = $genre->id;
        }
        $manga->genres()->sync($ids);
    }

    /**
     * @param  array<int, array<string, mixed>>  $authors
     */
    protected function syncAuthors(Manga $manga, array $authors): void
    {
        $ids = [];
        foreach ($authors as $author) {
            $aName = (string) ($author ?? '');
            if ($aName === '') {
                continue;
            }
            $author = Author::query()->firstOrCreate(
                ['name_md5' => md5($aName)],
                ['name' => $aName]
            );
            $ids[] = $author->id;
        }
        $manga->authors()->sync($ids);
    }

    /**
     * @param  array<int, array<string, mixed>>  $tags
     */
    protected function syncTags(Manga $manga, array $tags): void
    {
        $ids = [];
        foreach ($tags as $tag) {
            $tName = (string) ($tag ?? '');
            if ($tName === '') {
                continue;
            }
            $tag = Tag::query()->firstOrCreate(
                ['slug' => Str::slug($tName)],
                ['name' => $tName]
            );
            $ids[] = $tag->id;
        }
        $manga->tags()->sync($ids);
    }

    /**
     * @param  array<int, array<string, mixed>>  $chaptersTree
     * @param  callable(array<string, mixed>): void  $emit
     * @return array{0: int, 1: int} [ok, fail]
     */
    protected function importChapters(
        Manga $manga,
        array $chaptersTree,
        callable $emit,
        string $mangaSlug,
        string $mangaTitle,
        int $mangaPosition,
        int $mangaTotal
    ): array {
        $ok = 0;
        $fail = 0;
        $max = (int) config('crawler-otruyen.max_chapters_per_manga', 0);
        $planned = $this->countPlannedChapterRows($chaptersTree, $max);
        $seen = 0;
        $io = $this->effectiveImportOptions();

        foreach ($chaptersTree as $serverBlock) {
            if (! is_array($serverBlock)) {
                continue;
            }
            $serverName = (string) ($serverBlock['server_name'] ?? 'default');
            $rows = $serverBlock['server_data'] ?? [];
            if (! is_array($rows)) {
                continue;
            }

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                if ($max > 0 && ($ok + $fail) >= $max) {
                    $emit([
                        'state' => 'warning',
                        'scope' => 'chapter',
                        'slug' => $mangaSlug,
                        'manga_title' => $mangaTitle,
                        'message' => "[Giới hạn] Đã đạt tối đa {$max} chương / truyện (cấu hình).",
                    ]);
                    break 2;
                }

                $chapterUrl = (string) ($row['chapter_api_data'] ?? '');
                if ($chapterUrl === '') {
                    continue;
                }

                $this->assertClientStillConnected($emit);

                $seen++;
                $chapterNumber = $this->parseChapterNumber((string) ($row['chapter_name'] ?? '0'));
                $title = trim((string) ($row['chapter_title'] ?? ''));

                $chapterLabel = 'Chương '.(string) ($row['chapter_name'] ?? '?').' '.$title;
                $chapterLabel = trim($chapterLabel);

                $chapProgress = $planned > 0 ? "{$seen}/{$planned}" : (string) $seen;
                $emit([
                    'state' => 'pending',
                    'scope' => 'chapter',
                    'slug' => $mangaSlug,
                    'manga_title' => $mangaTitle,
                    'chapter' => $chapterLabel,
                    'message' => "[Chờ] {$chapterLabel} — «{$mangaTitle}» (truyện {$mangaPosition}/{$mangaTotal}, chương {$chapProgress}).",
                ]);

                $emit([
                    'state' => 'running',
                    'scope' => 'chapter',
                    'slug' => $mangaSlug,
                    'manga_title' => $mangaTitle,
                    'chapter' => $chapterLabel,
                    'message' => "[Đang chạy] Đang tải {$chapterLabel} — «{$mangaTitle}»…",
                ]);

                $existingBefore = Chapter::query()
                    ->where('manga_id', $manga->id)
                    ->where('chapter_number', $chapterNumber)
                    ->first();

                $storageKey = $this->imageStorage->resolveStoredServerKey($serverName, $io);

                if ($this->chapterAlreadyHasServerImages($existingBefore, $storageKey)) {
                    $emit([
                        'state' => 'success',
                        'scope' => 'chapter',
                        'slug' => $mangaSlug,
                        'manga_title' => $mangaTitle,
                        'chapter' => $chapterLabel,
                        'message' => "[Bỏ qua] {$chapterLabel} — «{$mangaTitle}»: đã có ảnh «{$storageKey}»".($storageKey !== $serverName ? " (API: {$serverName})" : '').' (không gọi API).',
                    ]);
                    $ok++;

                    continue;
                }

                try {
                    $outcome = DB::transaction(function () use ($manga, $chapterUrl, $chapterNumber, $chapterLabel, $serverName, $storageKey, $io) {
                        $existing = Chapter::query()
                            ->where('manga_id', $manga->id)
                            ->where('chapter_number', $chapterNumber)
                            ->lockForUpdate()
                            ->first();

                        // Tránh race: luồng khác vừa ghi xong server này
                        if ($this->chapterAlreadyHasServerImages($existing, $storageKey)) {
                            return 'skipped';
                        }

                        $chapterPayload = $this->client->getJson($chapterUrl);
                        $chapterData = $chapterPayload['data']['item'] ?? null;
                        if (! is_array($chapterData)) {
                            throw new \RuntimeException('Thiếu dữ liệu chapter từ API.');
                        }

                        $newServerImages = $this->buildImageServers($serverName, $chapterPayload['data'] ?? []);
                        $newServerImages = $this->imageStorage->mirrorImageServers($newServerImages, (string) $manga->slug, $chapterNumber, $io);

                        $merged = is_array($existing?->image_servers) ? $existing->image_servers : [];
                        $replaceEmpty = (int) ($existing?->pages_count ?? 0) < 1;
                        foreach ($newServerImages as $name => $urls) {
                            $urls = is_array($urls) ? $urls : [];
                            if ($replaceEmpty) {
                                $merged[$name] = $urls;
                            } else {
                                if (! isset($merged[$name])) {
                                    $merged[$name] = [];
                                }
                                $merged[$name] = array_merge($merged[$name], $urls);
                            }
                        }

                        $pages = 0;
                        foreach ($merged as $urls) {
                            if (is_array($urls)) {
                                $pages += count($urls);
                            }
                        }

                        $slug = $existing?->slug
                            ?? $this->uniqueChapterSlug($manga->id, $chapterNumber, $chapterLabel);

                        Chapter::query()->updateOrCreate(
                            [
                                'manga_id' => $manga->id,
                                'chapter_number' => $chapterNumber,
                            ],
                            [
                                'title' => $chapterLabel,
                                'slug' => $slug,
                                'image_servers' => $merged,
                                'pages_count' => $pages,
                                'published_at' => $existing?->published_at ?? now(),
                            ]
                        );

                        return 'saved';
                    });

                    if ($outcome === 'skipped') {
                        $emit([
                            'state' => 'success',
                            'scope' => 'chapter',
                            'slug' => $mangaSlug,
                            'manga_title' => $mangaTitle,
                            'chapter' => $chapterLabel,
                            'message' => "[Bỏ qua] {$chapterLabel} — «{$mangaTitle}»: đã có ảnh «{$storageKey}»".($storageKey !== $serverName ? " (API: {$serverName})" : '').' (race).',
                        ]);
                    } else {
                        $emit([
                            'state' => 'success',
                            'scope' => 'chapter',
                            'slug' => $mangaSlug,
                            'manga_title' => $mangaTitle,
                            'chapter' => $chapterLabel,
                            'message' => "[Thành công] {$chapterLabel} — «{$mangaTitle}» đã ghi DB.",
                        ]);
                    }
                    $ok++;
                } catch (\Throwable $e) {
                    if ($e instanceof CrawlerImportAbortedException) {
                        throw $e;
                    }
                    $fail++;
                    $emit([
                        'state' => 'error',
                        'scope' => 'chapter',
                        'slug' => $mangaSlug,
                        'manga_title' => $mangaTitle,
                        'chapter' => $chapterLabel,
                        'message' => "[Thất bại] {$chapterLabel} — «{$mangaTitle}»: ".$e->getMessage(),
                    ]);
                }
            }
        }

        return [$ok, $fail];
    }

    /**
     * Chapter đã có ít nhất một URL ảnh hợp lệ cho server (nhánh CDN) này chưa.
     */
    protected function chapterAlreadyHasServerImages(?Chapter $chapter, string $serverName): bool
    {
        if ($chapter === null) {
            return false;
        }
        // pages_count = 0: coi như chưa có ảnh hợp lệ (cho phép import lại).
        if ((int) ($chapter->pages_count ?? 0) < 1) {
            return false;
        }
        $servers = $chapter->image_servers;
        if (! is_array($servers) || ! array_key_exists($serverName, $servers)) {
            return false;
        }
        $urls = $servers[$serverName];
        if (! is_array($urls)) {
            return false;
        }
        foreach ($urls as $u) {
            if (is_string($u) && trim($u) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $chaptersTree
     */
    protected function countPlannedChapterRows(array $chaptersTree, int $max): int
    {
        $n = 0;
        foreach ($chaptersTree as $serverBlock) {
            if (! is_array($serverBlock)) {
                continue;
            }
            $rows = $serverBlock['server_data'] ?? [];
            if (! is_array($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                if ((string) ($row['chapter_api_data'] ?? '') === '') {
                    continue;
                }
                $n++;
                if ($max > 0 && $n >= $max) {
                    return $max;
                }
            }
        }

        return $n;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, list<string>>
     */
    protected function buildImageServers(string $serverName, array $data): array
    {
        $item = $data['item'] ?? [];
        if (! is_array($item)) {
            return [$serverName => []];
        }

        $domain = rtrim((string) ($data['domain_cdn'] ?? $item['domain_cdn'] ?? ''), '/');
        if ($domain === '') {
            $domain = 'https://sv1.otruyencdn.com';
        }

        $path = trim((string) ($item['chapter_path'] ?? ''), '/');
        $images = $item['chapter_image'] ?? [];
        if (! is_array($images)) {
            return [$serverName => []];
        }

        $urls = [];
        foreach ($images as $img) {
            if (! is_array($img)) {
                continue;
            }
            $file = (string) ($img['image_file'] ?? '');
            if ($file === '') {
                continue;
            }
            $urls[] = $domain.'/'.$path.'/'.$file;
        }

        return [$serverName => $urls];
    }

    protected function uniqueChapterSlug(int $mangaId, float $chapterNumber, string $title): string
    {
        $base = Str::slug($title);
        if ($base === '') {
            $base = 'chap-'.rtrim(rtrim(number_format($chapterNumber, 2, '.', ''), '0'), '.');
        }

        $slug = $base;
        $i = 1;
        while (Chapter::query()->where('manga_id', $mangaId)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i;
            $i++;
        }

        return $slug;
    }

    protected function parseChapterNumber(string $raw): float
    {
        $raw = trim($raw);
        if (preg_match('/^([\d.]+)/', $raw, $m)) {
            return (float) $m[1];
        }

        $n = (float) preg_replace('/[^\d.]/', '', $raw);

        return $n;
    }

    protected function mapMangaStatus(string $s): string
    {
        return match ($s) {
            'completed' => 'completed',
            'ongoing' => 'ongoing',
            'coming_soon' => 'hiatus',
            'cancelled' => 'cancelled',
            default => 'ongoing',
        };
    }
}
