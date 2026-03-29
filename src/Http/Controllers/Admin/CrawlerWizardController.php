<?php

namespace Nqt\CrawlerOtruyen\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Nqt\CrawlerOtruyen\Contracts\CrawlerSourceContract;
use Nqt\CrawlerOtruyen\CrawlerSourceManager;
use Nqt\CrawlerOtruyen\Services\CrawlerImportOptions;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Wizard 3 bước trên một trang — API JSON + jQuery.
 * Nguồn crawl: CrawlerSourceManager (config crawler-otruyen.default + sources).
 */
class CrawlerWizardController extends Controller
{
    public function wizard(CrawlerSourceManager $manager, CrawlerImportOptions $importOptions): View
    {
        $defaultId = (string) config('crawler-otruyen.default', 'otruyen');
        $driver = $manager->driver($defaultId);

        return view('crawler-otruyen::wizard.app', [
            'defaultListUrl' => $driver->defaultListUrl(),
            'crawlerSources' => $manager->labels(),
            'defaultCrawlerSource' => $defaultId,
            'defaultImportImageMode' => $importOptions->imageMode(),
            'defaultImportContentRewrite' => $importOptions->contentRewriteEnabled(),
            /** URL API bước 1/2 — truyền từ controller để view giữ tên chung (dùng lại cho plugin cào khác). */
            'crawlWizardApiUrls' => [
                'step1' => route('crawler-otruyen.api.step1'),
                'step2' => route('crawler-otruyen.api.step2'),
            ],
        ]);
    }

    public function apiStep1(Request $request, CrawlerSourceManager $manager): JsonResponse
    {
        $sourceIds = array_keys(config('crawler-otruyen.sources', []));

        $validator = Validator::make($request->all(), [
            'crawler_source' => ['nullable', 'string', Rule::in($sourceIds)],
            'source_mode' => ['required', 'in:list,slug'],
            'list_url' => ['required_if:source_mode,list', 'nullable', 'string', 'url'],
            'list_page_start' => ['required_if:source_mode,list', 'nullable', 'integer', 'min:1', 'max:50000'],
            'list_page_end' => ['required_if:source_mode,list', 'nullable', 'integer', 'min:1', 'max:50000'],
            'detail_urls' => ['required_if:source_mode,slug', 'nullable', 'string', 'max:20000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $sourceId = (string) ($data['crawler_source'] ?? config('crawler-otruyen.default', 'otruyen'));
        $source = $manager->driver($sourceId);

        $mangas = [];

        if ($data['source_mode'] === 'list') {
            $listUrl = trim((string) ($data['list_url'] ?? ''));
            $start = (int) ($data['list_page_start'] ?? 1);
            $end = (int) ($data['list_page_end'] ?? $start);
            if ($end < $start) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Trang kết thúc phải lớn hơn hoặc bằng trang bắt đầu.',
                ], 422);
            }

            $mangas = $source->fetchCatalog($listUrl, $start, $end);
        } else {
            $detailBlock = trim((string) ($data['detail_urls'] ?? ''));
            if ($detailBlock === '') {
                return response()->json([
                    'ok' => false,
                    'message' => 'Nhập ít nhất một URL hoặc slug truyện (mỗi dòng một mục).',
                ], 422);
            }

            $mangas = $source->resolveMangasFromDetailLines($detailBlock);
        }

        $mangas = collect($mangas)
            ->unique('slug')
            ->values()
            ->all();

        if ($mangas === []) {
            return response()->json([
                'ok' => false,
                'message' => 'Không lấy được truyện nào. Kiểm tra URL hoặc thử lại.',
            ], 422);
        }

        session(['crawler_otruyen.mangas' => $mangas, 'crawler_otruyen.source' => $sourceId]);

        return response()->json([
            'ok' => true,
            'mangas' => $mangas,
            'count' => count($mangas),
            'crawler_source' => $sourceId,
        ]);
    }

    public function apiStep2(Request $request, CrawlerSourceManager $manager): JsonResponse|StreamedResponse
    {
        $sourceIds = array_keys(config('crawler-otruyen.sources', []));

        $validator = Validator::make($request->all(), [
            'crawler_source' => ['nullable', 'string', Rule::in($sourceIds)],
            'slug' => ['required', 'array', 'min:1'],
            'slug.*' => ['string', 'max:500'],
            'import_image_mode' => ['sometimes', 'nullable', Rule::in(['remote', 'local', 's3'])],
            'import_content_rewrite' => ['sometimes', 'nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $slugs = $data['slug'];
        $sourceId = (string) ($data['crawler_source'] ?? session('crawler_otruyen.source') ?? config('crawler-otruyen.default', 'otruyen'));
        $source = $manager->driver($sourceId);

        session(['crawler_otruyen.selected_slugs' => $slugs, 'crawler_otruyen.source' => $sourceId]);

        $importOpts = $this->importOptionOverridesFromValidated($data);

        @set_time_limit(0);

        $accept = (string) $request->header('Accept', '');
        if (str_contains($accept, 'text/event-stream')) {
            return $this->streamImportResult($slugs, $source, $importOpts);
        }

        $result = $source->importMangas($slugs, null, $importOpts);
        session(['crawler_otruyen.import_result' => $result]);

        return response()->json([
            'ok' => true,
            'result' => $result,
        ]);
    }

    /**
     * Ghi đè tùy chọn theo lần import (ưu tiên hơn Settings).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    protected function importOptionOverridesFromValidated(array $data): ?array
    {
        $overrides = [];
        if (isset($data['import_image_mode']) && $data['import_image_mode'] !== null && $data['import_image_mode'] !== '') {
            $overrides['image_mode'] = (string) $data['import_image_mode'];
        }
        if (array_key_exists('import_content_rewrite', $data)) {
            $overrides['content_rewrite_enabled'] = (bool) $data['import_content_rewrite'];
        }

        return $overrides !== [] ? $overrides : null;
    }

    /**
     * SSE: đẩy từng dòng log trong lúc import (pending / đang chạy / xong).
     *
     * @param  array<int, string>  $slugs
     */
    /**
     * @param  array<string, mixed>|null  $importOptions
     */
    protected function streamImportResult(array $slugs, CrawlerSourceContract $source, ?array $importOptions = null): StreamedResponse
    {
        return response()->stream(function () use ($slugs, $source, $importOptions) {
            $write = static function (array $payload): void {
                echo 'data: '.json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n\n";
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }
                flush();
            };

            $result = $source->importMangas($slugs, $write, $importOptions);
            session(['crawler_otruyen.import_result' => $result]);
            $write(['type' => 'complete', 'ok' => true, 'result' => $result]);
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-store',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
