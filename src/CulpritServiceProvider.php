<?php

declare(strict_types=1);

namespace TheAaronLawrence\Culprit;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the reporter up. The exception hook itself is registered by the app.
 */
final class CulpritServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/culprit.php', 'culprit');

        $this->app->singleton(PathNormaliser::class, fn (): PathNormaliser => new PathNormaliser($this->app->basePath()));

        foreach ([Culprit::class, TrackerClient::class, PayloadEncoder::class, ErrorReporter::class, ReportThrottle::class, CurrentJob::class, CurrentCommand::class, CompiledViewMapper::class] as $class) {
            $this->app->singleton($class);
        }
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/culprit.php' => $this->app->configPath('culprit.php')], 'culprit-config');

        Event::listen(JobProcessing::class, fn (JobProcessing $event) => $this->app->make(CurrentJob::class)->set($event->job));
        Event::listen(JobProcessed::class, fn () => $this->app->make(CurrentJob::class)->clear());
        Event::listen(Looping::class, fn () => $this->app->make(CurrentJob::class)->clear());

        Event::listen(CommandStarting::class, fn (CommandStarting $event) => $this->app->make(CurrentCommand::class)->start((string) $event->command, $event->input));
        Event::listen(CommandFinished::class, fn (CommandFinished $event) => $this->app->make(CurrentCommand::class)->finish($event->exitCode));
    }
}
