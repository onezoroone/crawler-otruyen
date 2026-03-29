<?php

namespace Nqt\CrawlerOtruyen;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Nqt\CrawlerOtruyen\Console\Commands\CrawlerOtruyenImportCommand;
use Nqt\CrawlerOtruyen\Http\Controllers\Admin\CrawlerWizardController;
use Nqt\CrawlerOtruyen\Services\CrawlerImageStorageService;
use Nqt\CrawlerOtruyen\Services\CrawlerImportOptions;
use Nqt\CrawlerOtruyen\Services\OpenAiContentRewriter;
use Nqt\CrawlerOtruyen\Services\OtruyenApiClient;
use Nqt\CrawlerOtruyen\Services\OtruyenCatalogService;
use Nqt\CrawlerOtruyen\Services\OtruyenMangaImporter;

class CrawlerOtruyenServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/crawler-otruyen.php', 'crawler-otruyen');

        $this->app->singleton(OtruyenApiClient::class);
        $this->app->singleton(OtruyenCatalogService::class);
        $this->app->singleton(CrawlerImportOptions::class);
        $this->app->singleton(OpenAiContentRewriter::class);
        $this->app->singleton(CrawlerImageStorageService::class);
        $this->app->singleton(OtruyenMangaImporter::class);
        $this->app->singleton(CrawlerSourceManager::class);
    }

    public function boot(): void
    {
        /** Ưu tiên view đã publish vào resources/views/vendor/crawler-otruyen */
        $this->loadViewsFrom([
            resource_path('views/vendor/crawler-otruyen'),
            __DIR__.'/../resources/views',
        ], 'crawler-otruyen');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/crawler-otruyen'),
        ], ['crawler-otruyen-views', 'crawler-otruyen']);

        $this->publishes([
            __DIR__.'/../database/stubs/CrawlerOtruyenSettingsSeeder.stub.php' => database_path('seeders/CrawlerOtruyenSettingsSeeder.php'),
        ], ['crawler-otruyen-seeders', 'crawler-otruyen']);

        if ($this->app->runningInConsole()) {
            $this->commands([
                CrawlerOtruyenImportCommand::class,
            ]);
        }

        $this->registerAdminRoutes();

        $this->app->booted(function () {
            $partial = 'crawler-otruyen::partials.menu';
            $existing = config('plugins.menu_partials', []);
            if (! in_array($partial, $existing, true)) {
                config([
                    'plugins.menu_partials' => array_values(array_merge($existing, [$partial])),
                ]);
            }
        });
    }

    protected function registerAdminRoutes(): void
    {
        Route::group([
            'prefix' => config('backpack.base.route_prefix', 'admin'),
            'middleware' => array_merge(
                (array) config('backpack.base.web_middleware', 'web'),
                (array) config('backpack.base.middleware_key', 'admin')
            ),
        ], function () {
            Route::get('plugin/crawler-otruyen', [CrawlerWizardController::class, 'wizard'])
                ->name('crawler-otruyen.wizard');
            Route::post('plugin/crawler-otruyen/api/step1', [CrawlerWizardController::class, 'apiStep1'])
                ->name('crawler-otruyen.api.step1');
            Route::post('plugin/crawler-otruyen/api/step2', [CrawlerWizardController::class, 'apiStep2'])
                ->name('crawler-otruyen.api.step2');

            // Bookmark / link cũ multi-page → một trang wizard (SPA jQuery).
            $wizardPath = '/'.trim(config('backpack.base.route_prefix', 'admin'), '/').'/plugin/crawler-otruyen';
            foreach (['step1', 'step2', 'step3'] as $legacy) {
                Route::permanentRedirect("plugin/crawler-otruyen/{$legacy}", $wizardPath);
            }
        });
    }
}
