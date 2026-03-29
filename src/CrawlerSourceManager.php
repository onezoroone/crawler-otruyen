<?php

namespace Nqt\CrawlerOtruyen;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Nqt\CrawlerOtruyen\Contracts\CrawlerSourceContract;

/**
 * Chọn driver nguồn crawl theo config `crawler-otruyen.default` và `sources`.
 */
class CrawlerSourceManager
{
    public function __construct(
        protected Container $container
    ) {}

    public function driver(?string $name = null): CrawlerSourceContract
    {
        $name ??= (string) config('crawler-otruyen.default', 'otruyen');
        /** @var array<string, class-string<CrawlerSourceContract>> $map */
        $map = config('crawler-otruyen.sources', []);

        if (! isset($map[$name]) || $map[$name] === '') {
            throw new InvalidArgumentException("Nguồn crawl không tồn tại hoặc chưa cấu hình: [{$name}]");
        }

        $class = $map[$name];
        $instance = $this->container->make($class);

        if (! $instance instanceof CrawlerSourceContract) {
            throw new InvalidArgumentException("Class {$class} phải implement ".CrawlerSourceContract::class);
        }

        return $instance;
    }

    /**
     * @return array<string, string> id => label
     */
    public function labels(): array
    {
        $out = [];
        foreach (array_keys(config('crawler-otruyen.sources', [])) as $id) {
            $out[$id] = $this->driver((string) $id)->label();
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public function registeredIds(): array
    {
        return array_keys(config('crawler-otruyen.sources', []));
    }
}
