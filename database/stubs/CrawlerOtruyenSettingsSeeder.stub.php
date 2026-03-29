<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Nqt\CrawlerOtruyen\Database\Seeders\CrawlerOtruyenSettingsSeeder as CrawlerOtruyenSettingsSeederPackage;

/**
 * Bản sao có thể chỉnh — publish từ package crawler-otruyen.
 * Mặc định gọi seeder trong package; ghi đè run() nếu cần tuỳ biến.
 */
class CrawlerOtruyenSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(CrawlerOtruyenSettingsSeederPackage::class);
    }
}
