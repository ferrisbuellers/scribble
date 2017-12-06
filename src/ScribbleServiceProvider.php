<?php

namespace FullStackFool\Scribble;

use Illuminate\Support\ServiceProvider;

class ScribbleServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->publishes([
            __DIR__ . '/../config/scribble.php' => config_path('scribble.php')
        ]);
        $this->mergeConfigFrom(__DIR__ . '/../config/scribble.php', 'scribble');
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        // TODO: Implement register() method.
    }
}
