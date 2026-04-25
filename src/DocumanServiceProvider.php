<?php

declare(strict_types=1);

namespace Tekkenking\Documan;

use Illuminate\Support\ServiceProvider;

class DocumanServiceProvider extends ServiceProvider
{


    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/documan.php' => config_path('documan.php'),
        ]);
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/documan.php', 'documan'
        );

        $this->app->bind('documan', function ($app, array $params = []) {
            $disk = $params[0] ?? '';
            return new Documan($disk);
        });

    }


}
