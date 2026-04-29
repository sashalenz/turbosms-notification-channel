<?php

declare(strict_types=1);

namespace Sashalenz\TurboSms\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Sashalenz\TurboSms\TurboSmsServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            TurboSmsServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app): void
    {
        config()->set('turbosms.api_key', 'test-token');
        config()->set('turbosms.sender', 'TestAlpha');
        config()->set('turbosms.sandbox_mode', false);
        config()->set('turbosms.debug', false);
        config()->set('turbosms.base_url', 'https://api.turbosms.ua');
        config()->set('turbosms.timeout', 10);
    }
}
