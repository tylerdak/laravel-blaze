<?php

namespace Livewire\Blaze;

use Livewire\Blaze\Walker\Walker;
use Livewire\Blaze\Tokenizer\Tokenizer;
use Livewire\Blaze\Parser\Parser;
use Livewire\Blaze\Memoizer\Memoizer;
use Livewire\Blaze\Folder\Folder;
use Livewire\Blaze\Directive\BlazeDirective;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Blade;

class BlazeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerBlazeManager();
        $this->registerBlazeDirectiveFallbacks();
        $this->registerBladeMacros();
        $this->interceptBladeCompilation();
        $this->interceptViewCacheInvalidation();
    }

    protected function registerBlazeManager(): void
    {
        $this->app->bind('blaze.service', fn ($app) => $app->make(BladeService::class));

        $this->app->singleton(BlazeManager::class, fn () => new BlazeManager(
            new Tokenizer,
            new Parser,
            new Walker,
            new Folder(
                renderBlade: fn ($blade) => app('blaze.service')->isolatedRender($blade),
                renderNodes: fn ($nodes) => implode('', array_map(fn ($n) => $n->render(), $nodes)),
                componentNameToPath: fn ($name) => app('blaze.service')->componentNameToPath($name),
            ),
            new Memoizer(
                componentNameToPath: fn ($name) => app('blaze.service')->componentNameToPath($name),
            ),
        ));

        $this->app->alias(BlazeManager::class, Blaze::class);

        $this->app->bind('blaze', fn ($app) => $app->make(BlazeManager::class));
    }

    protected function registerBlazeDirectiveFallbacks(): void
    {
        Blade::directive('unblaze', function ($expression) {
            return ''
                . '<'.'?php $__getScope = fn($scope = []) => $scope; ?>'
                . '<'.'?php if (isset($scope)) $__scope = $scope; ?>'
                . '<'.'?php $scope = $__getScope('.$expression.'); ?>';
        });

        Blade::directive('endunblaze', function () {
            return '<'.'?php if (isset($__scope)) { $scope = $__scope; unset($__scope); } ?>';
        });

        BlazeDirective::registerFallback();
    }

    protected function registerBladeMacros(): void
    {
        $this->app->make('view')->macro('pushConsumableComponentData', function ($data) {
            $this->componentStack[] = new \Illuminate\Support\HtmlString('');

            $this->componentData[$this->currentComponent()] = $data;
        });

        $this->app->make('view')->macro('popConsumableComponentData', function () {
            array_pop($this->componentStack);
        });
    }

    protected function interceptBladeCompilation(): void
    {
        $blaze = app(BlazeManager::class);

        app('blaze.service')->earliestPreCompilationHook(function ($input) use ($blaze) {
            if ($blaze->isDisabled()) return $input;

            if (app('blaze.service')->containsLaravelExceptionView($input)) return $input;

            return $blaze->collectAndAppendFrontMatter($input, function ($input) use ($blaze) {
                return $blaze->compile($input);
            });
        });
    }

    protected function interceptViewCacheInvalidation(): void
    {
        $blaze = app(BlazeManager::class);

        app('blaze.service')->viewCacheInvalidationHook(function ($view, $invalidate) use ($blaze) {
            if ($blaze->isDisabled()) return;

            if ($blaze->viewContainsExpiredFrontMatter($view)) {
                $invalidate();
            }
        });
    }

    public function boot(): void
    {
        // Bootstrap services
    }
}
