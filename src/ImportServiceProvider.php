<?php

namespace Waterhole\Import;

use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Waterhole\Import\Console\ImportFlarum;

class ImportServiceProvider extends BaseServiceProvider
{
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ImportFlarum::class]);
        }
    }
}
