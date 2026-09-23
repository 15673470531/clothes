<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * 测试基类
 *
 * 这里做一件很关键的事：**强制测试连内存 sqlite**，别碰开发库。
 *
 * 为什么需要（2026-09 真踩过，代价是本地开发库被清空）：
 * `docker-compose.yml` 把 `DB_CONNECTION=mysql` / `DB_DATABASE=clothes` 作为**真环境变量**注进了
 * 容器，优先级高于 phpunit.xml 里的 <env>（连 force="true" 都盖不住 —— Laravel 读 env 的适配器
 * 里，容器注入那份先命中）。于是 `php artisan test` 实际连到本地 MySQL，而用例大多用
 * RefreshDatabase → 每个测试类 migrate:fresh → 开发库被反复清空。
 *
 * 所以在应用启动**之前**把这两个变量硬改掉（putenv + $_ENV + $_SERVER 三处都改，
 * Env 仓库的三个适配器都能命中），测试永远跑内存库，跟开发库彻底隔离。
 */
abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        foreach (['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:'] as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key]    = $value;
            $_SERVER[$key] = $value;
        }

        return parent::createApplication();
    }
}
