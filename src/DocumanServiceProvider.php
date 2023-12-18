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
    public function boot()
    {
        $this->publishes([
            __DIR__.'/../config/documan.php' => config_path('documan.php'),
        ],'documan-config');
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/documan.php', 'documan'
        );

        $this->app->singleton('documan', function ($app) {
            return new Documan();
        });

    }


}
