<?php

namespace Nqt\CrawlerOtruyen\Support;

/**
 * Trích slug truyện từ URL chi tiết API hoặc chuỗi slug thuần.
 */
class OtruyenSlugResolver
{
    public static function fromLine(string $line): ?string
    {
        $line = trim($line);
        if ($line === '') {
            return null;
        }

        // Dùng delimiter ~ vì trong class không được chứa # khi delimiter là # (sẽ cắt nhầm pattern → lỗi Unknown modifier ']').
        if (preg_match('~/truyen-tranh/([^/?#]+)~', $line, $m)) {
            return rawurldecode($m[1]);
        }

        if (preg_match('~^[a-z0-9][a-z0-9\-]*$~i', $line)) {
            return $line;
        }

        return null;
    }
}
