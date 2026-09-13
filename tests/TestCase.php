<?php
namespace MrNewport\LaravelPreflight\Tests;
class TestCase extends \Orchestra\Testbench\TestCase
{
    protected function getPackageProviders($app): array
    {
        return [\MrNewport\LaravelPreflight\PreflightServiceProvider::class];
    }
}
