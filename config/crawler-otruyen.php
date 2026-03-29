<?php

use Nqt\CrawlerOtruyen\Sources\Otruyen\OtruyenCrawlerSource;

return [

    /**
     * Nguồn crawl mặc định (khóa trong `sources`).
     * Đổi nguồn: env CRAWLER_SOURCE hoặc đăng ký class mới bên dưới.
     */
    'default' => env('CRAWLER_SOURCE', 'otruyen'),

    /**
     * map id => class implements CrawlerSourceContract.
     * Thêm nguồn: tạo class trong app (vd: App\Crawler\NetTruyenSource) và thêm dòng vào đây.
     */
    'sources' => [
        'otruyen' => OtruyenCrawlerSource::class,
    ],

    /** OTruyen: URL danh sách mặc định (chỉ dùng khi driver otruyen / tương thích) */
    'list_url_default' => 'https://otruyenapi.com/v1/api/danh-sach/truyen-moi',

    'http_timeout' => 40,

    'user_agent' => 'TruyenApp-CrawlerOtruyen/1.0',

    /** Giới hạn chương import mỗi truyện mỗi lần (0 = không giới hạn) */
    'max_chapters_per_manga' => 0,

    /*
    |--------------------------------------------------------------------------
    | Ảnh chapter / bìa: remote | local (storage public) | s3
    |--------------------------------------------------------------------------
    | Admin có thể ghi đè qua Settings: crawler_image_mode
    */
    'image_mode' => env('CRAWLER_IMAGE_MODE', 'remote'),

    'image_download_timeout' => 120,

    /** Số request ảnh chương chạy song song (Guzzle Pool). */
    'image_download_concurrency' => max(1, (int) env('CRAWLER_IMAGE_DOWNLOAD_CONCURRENCY', 8)),

    /** Số lần thử tải mỗi ảnh (tổng, gồm lượt trong pool). */
    'image_download_max_attempts' => max(1, min(10, (int) env('CRAWLER_IMAGE_DOWNLOAD_MAX_ATTEMPTS', 3))),

    /**
     * Chất lượng WebP sau khi nén (1–100). Có thể ghi đè bằng Settings image_compression_ratio (Backpack).
     */
    'webp_quality' => (int) env('CRAWLER_WEBP_QUALITY', 82),

    /**
     * Tăng giới hạn RAM PHP khi decode/encode ảnh (GD + WebP dễ vượt 128M với ảnh lớn).
     * Chuỗi rỗng = không đổi (vd: "512M", "1024M").
     */
    'image_memory_limit' => env('CRAWLER_IMAGE_MEMORY_LIMIT', '512M'),

    /**
     * Thu nhỏ cạnh dài nhất nếu vượt (px) — giảm peak RAM trước khi encode WebP (ảnh chapter thường rất lớn).
     * 0 = không tự thu nhỏ theo cạnh (chỉ dựa image_memory_limit).
     */
    'image_max_edge_pixels' => max(0, (int) env('CRAWLER_IMAGE_MAX_EDGE', 4096)),

    /**
     * Resize khi mirror — chỉ áp dụng ảnh bìa (ảnh chapter giữ kích thước gốc).
     * 0 = không giới hạn theo cạnh; cả W,H > 0: crop center; một cạnh: scaleDown.
     */
    'image_resize_width' => (int) env('CRAWLER_IMAGE_RESIZE_WIDTH', 0),

    'image_resize_height' => (int) env('CRAWLER_IMAGE_RESIZE_HEIGHT', 0),

    /**
     * Tên server lưu trong JSON image_servers khi mirror: remote = giữ tên từ API;
     * s3 / public dùng nhãn cố định (khác nhau để phân biệt VIP vs bản thường).
     */
    'image_server_label_s3' => env('CRAWLER_IMAGE_SERVER_LABEL_S3', 'VIP'),

    'image_server_label_public' => env('CRAWLER_IMAGE_SERVER_LABEL_PUBLIC', 'LC'),

    /*
    |--------------------------------------------------------------------------
    | OpenAI — viết lại mô tả (bật trong Settings hoặc env)
    |--------------------------------------------------------------------------
    */
    'content_rewrite_enabled' => env('CRAWLER_CONTENT_REWRITE', false),

    'openai_api_key' => env('OPENAI_API_KEY', ''),

    'openai_model' => env('OPENAI_MODEL', 'gpt-4o-mini'),

    'openai_timeout' => 120,

    'openai_max_tokens' => 4096,

    'openai_temperature' => 0.4,

    'openai_max_input_chars' => 12000,

    'openai_system_message' => 'Bạn là biên tập nội dung web truyện tranh tiếng Việt. Chỉ trả về văn bản thuần, không markdown, không giải thích.',

    /** Prompt mặc định (admin sửa trong Backpack Settings: crawler_openai_prompt) */
    'openai_prompt_default' => 'Viết lại đoạn mô tả sau cho mạch lạc, tự nhiên, giữ nguyên ý chính, tránh spam từ khóa. Dùng tiếng Việt.',
];
