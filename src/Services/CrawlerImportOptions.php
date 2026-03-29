<?php

namespace Nqt\CrawlerOtruyen\Services;

use Backpack\Settings\app\Models\Setting;

/**
 * Tùy chọn import: ưu tiên Backpack Settings (key crawler_*), fallback config/env.
 * Ghi đè theo lần chạy: dùng withOverrides() (wizard / CLI).
 */
class CrawlerImportOptions
{
    public function __construct(
        protected array $overrides = []
    ) {}

    /**
     * Gộp ghi đè (wizard, artisan) lên trên cài đặt DB/config.
     *
     * @param  array{image_mode?: string, content_rewrite_enabled?: bool, image_resize_width?: int, image_resize_height?: int}  $overrides
     */
    public function withOverrides(array $overrides): self
    {
        return new self(array_merge($this->overrides, $overrides));
    }

    /** remote | local | s3 */
    public function imageMode(): string
    {
        if (isset($this->overrides['image_mode'])) {
            $v = strtolower(trim((string) $this->overrides['image_mode']));
            if (in_array($v, ['remote', 'local', 's3'], true)) {
                return $v;
            }
        }

        $v = $this->settingString('crawler_image_mode', (string) config('crawler-otruyen.image_mode', 'remote'));
        $v = strtolower(trim($v));
        if (! in_array($v, ['remote', 'local', 's3'], true)) {
            return 'remote';
        }

        return $v;
    }

    public function contentRewriteEnabled(): bool
    {
        if (array_key_exists('content_rewrite_enabled', $this->overrides)) {
            return (bool) $this->overrides['content_rewrite_enabled'];
        }

        return $this->settingBool('crawler_content_rewrite_enabled', (bool) config('crawler-otruyen.content_rewrite_enabled', false));
    }

    public function openaiApiKey(): ?string
    {
        $env = (string) config('crawler-otruyen.openai_api_key', '');
        if ($env !== '') {
            return $env;
        }
        $s = trim((string) $this->settingString('crawler_openai_api_key', ''));

        return $s !== '' ? $s : null;
    }

    public function openaiModel(): string
    {
        return $this->settingString('crawler_openai_model', (string) config('crawler-otruyen.openai_model', 'gpt-4o-mini'));
    }

    /** Kích thước resize khi mirror (px). 0 = không áp dụng cạnh đó. */
    public function imageResizeWidth(): int
    {
        if (array_key_exists('image_resize_width', $this->overrides)) {
            return max(0, (int) $this->overrides['image_resize_width']);
        }

        return $this->settingInt('crawler_image_resize_width', (int) config('crawler-otruyen.image_resize_width', 0));
    }

    public function imageResizeHeight(): int
    {
        if (array_key_exists('image_resize_height', $this->overrides)) {
            return max(0, (int) $this->overrides['image_resize_height']);
        }

        return $this->settingInt('crawler_image_resize_height', (int) config('crawler-otruyen.image_resize_height', 0));
    }

    /** Prompt do admin chỉnh trong Settings (hướng dẫn viết lại mô tả). */
    public function openaiUserPrompt(): string
    {
        $default = (string) config('crawler-otruyen.openai_prompt_default', '');
        $fromDb = $this->settingString('crawler_openai_prompt', $default);

        return $fromDb !== '' ? $fromDb : $default;
    }

    protected function settingString(string $key, string $default): string
    {
        if (! class_exists(Setting::class)) {
            return $default;
        }
        $v = Setting::get($key, $default);

        return $v === null ? $default : (string) $v;
    }

    protected function settingInt(string $key, int $default): int
    {
        if (! class_exists(Setting::class)) {
            return $default;
        }
        $v = Setting::get($key, null);
        if ($v === null || $v === '') {
            return $default;
        }

        return max(0, (int) $v);
    }

    protected function settingBool(string $key, bool $default): bool
    {
        if (! class_exists(Setting::class)) {
            return $default;
        }
        $v = Setting::get($key, null);
        if ($v === null || $v === '') {
            return $default;
        }
        if (is_bool($v)) {
            return $v;
        }
        $s = strtolower(trim((string) $v));

        return in_array($s, ['1', 'true', 'yes', 'on'], true);
    }
}
