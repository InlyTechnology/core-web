<?php

namespace Inly\CoreWeb\Preview;

use Statamic\StaticCaching\NoCache\Region;
use Statamic\StaticCaching\NoCache\RegionNotFound;
use Statamic\StaticCaching\NoCache\Session;

/**
 * Statamic's nocache session for a preview render: regions are kept for this
 * request only. The stock session writes each region, with the view's
 * variables, under a key the public cached page reads too, so a preview's
 * unsaved values would reach the next visitor.
 */
final class PreviewNoCacheSession extends Session
{
    /** @var array<string, Region> */
    private array $kept = [];

    public function region(string $key): Region
    {
        return $this->kept[$key] ?? throw new RegionNotFound($key);
    }

    public function write()
    {
        //
    }

    public function restore()
    {
        return $this;
    }

    protected function cacheRegion(Region $region)
    {
        $this->kept[$region->key()] = $region;
    }
}
