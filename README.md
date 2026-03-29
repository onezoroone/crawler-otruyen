# crawler-otruyen

Package Laravel: công cụ **cào & import** truyện từ API OTruyen — giao diện wizard trong Backpack admin, lệnh Artisan, tùy chọn ảnh (remote / storage / S3) và viết lại mô tả qua OpenAI.

## Yêu cầu

- PHP 8.3+
- Laravel 13+
- Gợi ý: [Backpack for Laravel](https://backpackforlaravel.com/) (middleware `admin`, menu), package [Backpack Settings](https://github.com/Laravel-Backpack/Settings) để lưu cấu hình crawler trong DB

## Cài đặt

### Monorepo / path

Trong `composer.json` của app:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "packages/crawler-otruyen"
        }
    ],
    "require": {
        "nqt/crawler-otruyen": "@dev"
    }
}
```

```bash
composer update nqt/crawler-otruyen
```

Service provider được **auto-discover** (`extra.laravel.providers`).

### Seeder Settings (Backpack)

```bash
php artisan db:seed --class=Database\\Seeders\\CrawlerOtruyenSettingsSeeder
```

Hoặc gọi từ `DatabaseSeeder` (đã có sẵn trong project mẫu).

## Cấu hình

File config: `config/crawler-otruyen.php` (merge từ package).

Biến môi trường thường dùng:

| Biến | Ý nghĩa |
|------|---------|
| `CRAWLER_IMAGE_MODE` | `remote` \| `local` \| `s3` |
| `CRAWLER_CONTENT_REWRITE` | `true` / `false` — bật viết lại mô tả |
| `OPENAI_API_KEY` | Key OpenAI (hoặc nhập trong Settings) |

Ảnh **local**: cần `php artisan storage:link`. Ảnh **S3**: cấu hình disk `s3` và biến AWS trong `.env`.

### Backpack Settings (sau khi seed)

Các key: `crawler_content_rewrite_enabled`, `crawler_image_mode`, `crawler_openai_prompt`, `crawler_openai_api_key`, `crawler_openai_model`.

Truy cập: **Admin → Settings** (hoặc route `setting` của Backpack).

## Giao diện wizard

URL (prefix Backpack mặc định `admin`):

`GET /admin/plugin/crawler-otruyen`

- Bước 1: danh sách API hoặc nhập slug/URL.
- Bước 2: chọn truyện + **tùy chọn xử lý** (chế độ ảnh, bật/tắt viết lại mô tả) cho **lần import đó**.
- Bước 3: tóm tắt kết quả + nhật ký (SSE khi trình duyệt hỗ trợ).

Menu: nhóm **Crawler** (cấu hình `plugins.menu_partials`).

## Lệnh Artisan

```bash
php artisan crawler-otruyen:import --slug=ten-slug
php artisan crawler-otruyen:import --list --list-url="..." --page-start=1 --page-end=1
php artisan crawler-otruyen:import --file=slugs.txt
```

Tùy chọn ghi đè Settings:

```bash
php artisan crawler-otruyen:import --slug=x --image-mode=local --rewrite
php artisan crawler-otruyen:import --slug=x --no-rewrite
```

Không dùng đồng thời `--rewrite` và `--no-rewrite`.

### Log file (terminal)

Lệnh ghi thêm vào kênh log **`crawler_otruyen`** (driver `daily`):

- Đường dẫn: `storage/logs/crawler-otruyen-YYYY-MM-DD.log`
- Mỗi dòng do Laravel ghi có **đầy đủ ngày giờ** (theo timezone app, `config/app.php` → `timezone`).
- Biến tùy chọn: `LOG_CRAWLER_OTRUYEN_LOG_DAYS` (mặc định giữ file 14 ngày).

## Publish tài nguyên

Sao chép view / seeder ra app để chỉnh sửa:

```bash
# Chỉ Blade
php artisan vendor:publish --tag=crawler-otruyen-views

# Chỉ stub seeder (ghi đè database/seeders/CrawlerOtruyenSettingsSeeder.php)
php artisan vendor:publish --tag=crawler-otruyen-seeders

# Cả hai
php artisan vendor:publish --tag=crawler-otruyen
```

- View publish: `resources/views/vendor/crawler-otruyen/` (ưu tiên hơn view trong package).
- Seeder publish: file app gọi seeder gốc trong package; có thể sửa `run()` sau khi publish.

## Mở rộng nguồn crawl

Đăng ký class implements `Nqt\CrawlerOtruyen\Contracts\CrawlerSourceContract` trong `config('crawler-otruyen.sources')` và đặt `CRAWLER_SOURCE` hoặc chọn nguồn trên wizard nếu có nhiều driver.

## Giấy phép

MIT
