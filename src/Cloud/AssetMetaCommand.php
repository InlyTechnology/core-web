<?php

namespace Inly\CoreWeb\Cloud;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Facade;
use Statamic\Assets\AssetContainerContents;
use Statamic\Assets\AssetRepository;
use Statamic\Contracts\Assets\AssetContainer as AssetContainerContract;
use Statamic\Contracts\Assets\AssetContainerRepository as AssetContainerRepositoryContract;
use Statamic\Contracts\Assets\AssetRepository as AssetRepositoryContract;
use Statamic\Eloquent\Assets\Asset as EloquentAsset;
use Statamic\Eloquent\Assets\AssetContainer;
use Statamic\Facades\AssetContainer as AssetContainerFacade;
use Statamic\Stache\Repositories\AssetContainerRepository;
use Statamic\Statamic;

/**
 * content:import's asset meta for one container, in its own process: each asset is a read of its .meta from the disk, so a large library outlasted one process's time, and a run again picks up where the last stopped.
 */
class AssetMetaCommand extends Command
{
    protected $signature = 'core-web:asset-meta {container}';

    protected $description = 'Import one asset container\'s meta into the database, skipping what is already in (content:import runs it)';

    protected $hidden = true;

    public function handle(): int
    {
        // The driver's own import-assets swaps in the same: the stache repositories read the container's files and their .meta.
        Facade::clearResolvedInstance(AssetContainerRepositoryContract::class);
        Facade::clearResolvedInstance(AssetRepositoryContract::class);
        Statamic::repository(AssetContainerRepositoryContract::class, AssetContainerRepository::class);
        Statamic::repository(AssetRepositoryContract::class, AssetRepository::class);
        app()->bind(AssetContainerContract::class, AssetContainer::class);
        app()->bind(AssetContainerContents::class, fn () => new AssetContainerContents);

        $container = AssetContainerFacade::find($this->argument('container'));

        if ($container === null) {
            $this->error("No asset container [{$this->argument('container')}].");

            return self::FAILURE;
        }

        $done = app('statamic.eloquent.assets.model')::query()
            ->where('container', $container->handle())
            ->pluck('path')
            ->flip();

        $imported = 0;

        foreach ($container->assets() as $asset) {
            if ($done->has($asset->path())) {
                continue;
            }

            EloquentAsset::makeModelFromContract($asset)?->save();
            $imported++;
        }

        $this->line("{$container->handle()}: {$imported} imported, {$done->count()} already in.");

        return self::SUCCESS;
    }
}
