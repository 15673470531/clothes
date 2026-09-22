<?php

namespace App\Providers;

use App\Services\Tryon\DashScopeProvider;
use App\Services\Tryon\TryonProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // AI 试穿的供应商：现在接阿里云百炼。换厂商/加自建 GPU 只改这一行绑定，
        // 业务代码只认 TryonProvider 接口（测试里也换假实现，不用碰网络）。
        $this->app->bind(TryonProvider::class, function () {
            return new DashScopeProvider((string) config('tryon.api_key'));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
