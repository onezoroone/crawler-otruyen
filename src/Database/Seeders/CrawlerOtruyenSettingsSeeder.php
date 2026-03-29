<?php

namespace Nqt\CrawlerOtruyen\Database\Seeders;

use Backpack\Settings\app\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Cài đặt crawler trong Backpack Settings (admin chỉnh prompt / chế độ ảnh / OpenAI).
 * Publish: php artisan vendor:publish --tag=crawler-otruyen-seeders
 */
class CrawlerOtruyenSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            [
                'key' => 'crawler_content_rewrite_enabled',
                'name' => 'Crawler: Viết lại mô tả (ChatGPT)',
                'description' => 'Bật để gửi mô tả truyện qua OpenAI theo prompt bên dưới. Cần OPENAI_API_KEY hoặc nhập key trong cài đặt.',
                'value' => '0',
                'field' => [
                    'name' => 'value',
                    'label' => 'Bật viết lại mô tả',
                    'type' => 'select_from_array',
                    'options' => [
                        '0' => 'Tắt',
                        '1' => 'Bật',
                    ],
                ],
            ],
            [
                'key' => 'crawler_openai_prompt',
                'name' => 'Crawler: Prompt viết lại mô tả (OpenAI)',
                'description' => 'Hướng dẫn cho AI khi viết lại mô tả truyện (tiếng Việt).',
                'value' => (string) config('crawler-otruyen.openai_prompt_default'),
                'field' => [
                    'name' => 'value',
                    'label' => 'Prompt',
                    'type' => 'textarea',
                    'attributes' => ['rows' => 8],
                ],
            ],
            [
                'key' => 'crawler_openai_api_key',
                'name' => 'Crawler: OpenAI API key (tuỳ chọn)',
                'description' => 'Để trống nếu dùng biến môi trường OPENAI_API_KEY.',
                'value' => '',
                'field' => [
                    'name' => 'value',
                    'label' => 'API key',
                    'type' => 'password',
                ],
            ],
            [
                'key' => 'crawler_openai_model',
                'name' => 'Crawler: OpenAI model',
                'description' => 'Ví dụ: gpt-4o-mini, gpt-4o',
                'value' => 'gpt-4o-mini',
                'field' => [
                    'name' => 'value',
                    'label' => 'Model',
                    'type' => 'text',
                ],
            ],
            [
                'key' => 'crawler_image_resize_width',
                'name' => 'Crawler: Resize ảnh — chiều rộng (px)',
                'description' => '0 = không giới hạn theo chiều ngang. Đặt cả width và height > 0 để crop đúng khung (giữa).',
                'value' => '300',
                'field' => [
                    'name' => 'value',
                    'label' => 'Width (px)',
                    'type' => 'number',
                    'attributes' => ['min' => 0, 'max' => 10000, 'step' => 1],
                ],
            ],
            [
                'key' => 'crawler_image_resize_height',
                'name' => 'Crawler: Resize ảnh — chiều cao (px)',
                'description' => '0 = không giới hạn theo chiều dọc. Dùng cùng width để ảnh bìa/chapter về đúng kích thước.',
                'value' => '450',
                'field' => [
                    'name' => 'value',
                    'label' => 'Height (px)',
                    'type' => 'number',
                    'attributes' => ['min' => 0, 'max' => 10000, 'step' => 1],
                ],
            ],
        ];

        foreach ($defaults as $row) {
            $field = $row['field'];
            $field['name'] = 'value';
            Setting::query()->firstOrCreate(
                ['key' => $row['key']],
                [
                    'name' => $row['name'],
                    'description' => $row['description'],
                    'value' => $row['value'],
                    'field' => json_encode($field, JSON_UNESCAPED_UNICODE),
                    'active' => 1,
                ]
            );
        }
    }
}
