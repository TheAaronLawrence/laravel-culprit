<?php

declare(strict_types=1);

namespace TheAaronLawrence\Culprit\Tests;

use Illuminate\Console\Application as Artisan;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Exceptions\Handler;
use Orchestra\Testbench\TestCase as Orchestra;
use TheAaronLawrence\Culprit\Culprit;
use TheAaronLawrence\Culprit\CulpritServiceProvider;
use TheAaronLawrence\Culprit\Tests\Fixtures\InspireCommand;
use TheAaronLawrence\Culprit\Tests\Fixtures\PlaygroundFailCommand;

use function Orchestra\Testbench\default_skeleton_path;

abstract class TestCase extends Orchestra
{
    /**
     * The package root is the app's base path, so fixtures under tests/ count
     * as app code and not vendor code.
     */
    public static function applicationBasePath(): string
    {
        return dirname(__DIR__);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Failed jobs go to Testbench's in-memory "testing" connection, like everything else.
        config(['queue.failed.database' => config('database.default')]);
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:33R4vHfujC7MVfmgTrzhJG4Ou2yALnQhjdGjp6QBj18=');

        // What the app's exception hook does: hand every reported exception to the reporter.
        $app->afterResolving(ExceptionHandler::class, function (Handler $handler): void {
            app(Culprit::class)->handles(new Exceptions($handler));
        });

        // Added when Artisan is built, so the command events can still be rerouted.
        Artisan::starting(function (Artisan $artisan): void {
            $artisan->resolveCommands([PlaygroundFailCommand::class, InspireCommand::class]);
        });
    }

    /**
     * Testbench's loadLaravelMigrations() looks for migrations under base_path(),
     * which is the package root here, so load the skeleton's own copy of them
     * (users, cache, jobs, failed_jobs).
     */
    protected function loadSkeletonMigrations(): void
    {
        $this->loadMigrationsFrom((string) default_skeleton_path('migrations'));
    }

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [CulpritServiceProvider::class];
    }
}
