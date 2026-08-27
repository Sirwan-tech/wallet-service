<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        // Safety net: the suite drops and re-creates every table, so refuse to
        // run unless we are pointed at the dedicated test database.
        $database = env('DB_DATABASE');

        if ($database !== 'wallet_test') {
            $this->fail("Refusing to run tests against database [{$database}]. Expected [wallet_test].");
        }

        parent::setUp();
    }
}
