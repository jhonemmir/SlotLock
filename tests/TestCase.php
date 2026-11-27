<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Las escrituras requieren X-Demo-Key; los tests la mandan por defecto.
        $this->withHeader('X-Demo-Key', (string) config('slotlock.demo_api_key'));
    }
}
