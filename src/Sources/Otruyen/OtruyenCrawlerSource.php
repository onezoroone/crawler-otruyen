<?php

namespace Nqt\CrawlerOtruyen\Sources\Otruyen;

use Nqt\CrawlerOtruyen\Contracts\CrawlerSourceContract;
use Nqt\CrawlerOtruyen\Services\OtruyenApiClient;
use Nqt\CrawlerOtruyen\Services\OtruyenCatalogService;
use Nqt\CrawlerOtruyen\Services\OtruyenMangaImporter;
use Nqt\CrawlerOtruyen\Support\OtruyenSlugResolver;

/**
 * Nguồn OTruyen API (mặc định của package).
 */
class OtruyenCrawlerSource implements CrawlerSourceContract
{
    public function __construct(
        protected OtruyenCatalogService $catalog,
        protected OtruyenApiClient $client,
        protected OtruyenMangaImporter $importer
    ) {}

    public function id(): string
    {
        return 'otruyen';
    }

    public function label(): string
    {
        return 'OTruyen API';
    }

    public function defaultListUrl(): ?string
    {
        return config('crawler-otruyen.list_url_default');
    }

    public function fetchCatalog(string $listUrl, int $pageStart, int $pageEnd): array
    {
        return $this->catalog->fetchListPageRange($listUrl, $pageStart, $pageEnd);
    }

    public function resolveMangasFromDetailLines(string $detailBlock): array
    {
        $mangas = [];
        foreach (preg_split('/\r\n|\r|\n/', $detailBlock) as $line) {
            $slug = OtruyenSlugResolver::fromLine(trim((string) $line));
            if ($slug === null) {
                continue;
            }
            $detailUrl = 'https://otruyenapi.com/v1/api/truyen-tranh/'.rawurlencode($slug);
            $payload = $this->client->getJson($detailUrl);
            $item = $payload['data']['item'] ?? [];
            if (! is_array($item) || empty($item['slug'])) {
                continue;
            }
            $mangas[] = [
                'slug' => (string) $item['slug'],
                'name' => (string) ($item['name'] ?? $slug),
                'thumb_url' => (string) ($item['thumb_url'] ?? ''),
            ];
        }

        return collect($mangas)
            ->unique('slug')
            ->values()
            ->all();
    }

    public function importMangas(array $slugs, ?callable $onLog = null, ?array $importOptions = null): array
    {
        return $this->importer->importMangas($slugs, $onLog, $importOptions);
    }
}
