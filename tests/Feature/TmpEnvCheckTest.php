<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** 临时：看看测试到底连的哪个库（用完就删） */
class TmpEnvCheckTest extends TestCase
{
    public function test_which_db(): void
    {
        $name = DB::connection()->getDatabaseName();
        $driver = DB::connection()->getDriverName();
        file_put_contents('/tmp/tmp-test-db.txt', $driver . ' | ' . $name . ' | default=' . config('database.default'));

        $this->assertTrue(true);
    }
}
