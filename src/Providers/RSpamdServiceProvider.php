<?php

namespace VEximweb\Plugin\RSpamd\Core\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\File;
use VEximweb\Plugin\RSpamd\Core\Repositories\EmailScoreSampleRepository;
use VEximweb\Plugin\RSpamd\Core\Repositories\EmailStatRepository;
use VEximweb\Plugin\RSpamd\Core\Repositories\DomainStatsRepository;
use VEximweb\Plugin\RSpamd\Core\Repositories\RecipientStatsRepository;
use VEximweb\Plugin\RSpamd\Core\Repositories\SenderDomainStatsRepository;
use VEximweb\Plugin\RSpamd\Core\Repositories\Interfaces\EmailScoreSampleRepositoryInterface;
use VEximweb\Plugin\RSpamd\Core\Repositories\Interfaces\EmailStatRepositoryInterface;
use VEximweb\Plugin\RSpamd\Core\Repositories\Interfaces\DomainStatsRepositoryInterface;
use VEximweb\Plugin\RSpamd\Core\Repositories\Interfaces\RecipientStatsRepositoryInterface;
use VEximweb\Plugin\RSpamd\Core\Repositories\Interfaces\SenderDomainStatsRepositoryInterface;
use VEximweb\Plugin\RSpamd\Core\Services\PermissionAwareStatsService;
use VEximweb\Plugin\RSpamd\Core\Services\EmailSpamStatsService;
use VEximweb\Plugin\RSpamd\Core\Repositories\SpamRuleStatRepository;
use VEximweb\Plugin\RSpamd\Core\Repositories\Interfaces\SpamRuleStatRepositoryInterface;
use VEximweb\Plugin\RSpamd\Core\Repositories\SpamRuleScoreSampleRepository;
use VEximweb\Plugin\RSpamd\Core\Repositories\Interfaces\SpamRuleScoreSampleRepositoryInterface;

class RSpamdServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register config
        $this->mergeConfigFrom(
            __DIR__ . '/../Config/rspamd.php',
            'rspamd'
        );

        // Bind plugin repositories
        $this->bindRepositories();
        
        // Bind services
        $this->bindServices();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Load routes
        $this->loadRoutesFrom(__DIR__ . '/../Routes/api.php');

        // Publish config
        $this->publishes([
            __DIR__ . '/../Config/rspamd.php' => config_path('rspamd.php'),
        ], 'rspamd-config');

        // Load migrations
        if (is_dir(__DIR__ . '/../Database/Migrations')) {
            $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
        }

        // Register console commands (only in console)
        if ($this->app->runningInConsole()) {
            $this->loadCommands();
        }
    }

    /**
     * Bind all repositories to their interfaces.
     */
    protected function bindRepositories(): void
    {
        $this->app->bind(
            EmailScoreSampleRepositoryInterface::class,
            EmailScoreSampleRepository::class
        );

        $this->app->bind(
            EmailStatRepositoryInterface::class,
            EmailStatRepository::class
        );

        $this->app->bind(
            DomainStatsRepositoryInterface::class,
            DomainStatsRepository::class
        );

        $this->app->bind(
            RecipientStatsRepositoryInterface::class,
            RecipientStatsRepository::class
        );

        $this->app->bind(
            SenderDomainStatsRepositoryInterface::class,
            SenderDomainStatsRepository::class
        );
        
        $this->app->bind(
            SpamRuleStatRepositoryInterface::class,
            SpamRuleStatRepository::class
        );
        
        $this->app->bind(
            SpamRuleScoreSampleRepositoryInterface::class,
            SpamRuleScoreSampleRepository::class
        );    
        
    }

    /**
     * Bind all services to the container.
     */
    protected function bindServices(): void
    {
        $this->app->singleton(PermissionAwareStatsService::class, function ($app) {
            return new PermissionAwareStatsService(
                $app->make(EmailSpamStatsService::class),
                $app->make(DomainStatsRepositoryInterface::class),
                $app->make(RecipientStatsRepositoryInterface::class),
                $app->make(SenderDomainStatsRepositoryInterface::class),
                $app->make(EmailStatRepositoryInterface::class)
            );
        });
    }

    /**
     * Auto-discover and register all console commands from the Commands directory.
     */
    protected function loadCommands(): void
    {
        $commandPath = __DIR__ . '/../Console/Commands';

        // Check if the Commands directory exists
        if (!is_dir($commandPath)) {
            return;
        }

        $commands = [];
        $files = File::files($commandPath);

        foreach ($files as $file) {
            // Only process PHP files
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $className = 'VEximweb\\Plugin\\RSpamd\\Core\\Console\\Commands\\' . $file->getFilenameWithoutExtension();

            // Check if the class exists and is a valid console command
            if (class_exists($className) && is_subclass_of($className, \Illuminate\Console\Command::class)) {
                $commands[] = $className;
            }
        }

        // Register all discovered commands
        if (!empty($commands)) {
            $this->commands($commands);
        }
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [
        EmailScoreSampleRepositoryInterface::class,
        EmailStatRepositoryInterface::class,
        DomainStatsRepositoryInterface::class,
        RecipientStatsRepositoryInterface::class,
        SenderDomainStatsRepositoryInterface::class,
        SpamRuleStatRepositoryInterface::class, 
        SpamRuleScoreSampleRepositoryInterface::class,
        PermissionAwareStatsService::class,
        EmailSpamStatsService::class,

        ];
    }
}