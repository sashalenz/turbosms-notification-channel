<?php

declare(strict_types=1);

namespace Sashalenz\TurboSms;

use Illuminate\Config\Repository;
use Spatie\LaravelPackageTools\Package;
use Illuminate\Contracts\Foundation\Application;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class TurboSmsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('turbosms-notification-channel')
            ->hasConfigFile('turbosms');
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(TurboSmsChannel::class, static function (Application $app): TurboSmsChannel {
            /** @var Repository $config */
            $config = $app->make('config');

            /** @var array<string, mixed> $options */
            $options = (array) $config->get('turbosms', []);

            return new TurboSmsChannel(
                apiKey: isset($options['api_key']) ? (string) $options['api_key'] : null,
                sender: isset($options['sender']) ? (string) $options['sender'] : null,
                sandboxMode: (bool) ($options['sandbox_mode'] ?? false),
                debug: (bool) ($options['debug'] ?? false),
                baseUrl: (string) ($options['base_url'] ?? 'https://api.turbosms.ua'),
                timeout: (int) ($options['timeout'] ?? 10),
            );
        });
    }
}
