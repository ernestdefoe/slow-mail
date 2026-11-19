<?php

namespace ErnestDefoe\SlowMail;

use ErnestDefoe\SlowMail\Integration\Integration;
use ErnestDefoe\SlowMail\Integration\Integrations;
use Flarum\Foundation\AbstractServiceProvider;
use Illuminate\Contracts\Container\Container;

/** No Member Map, no integrations, until an Extend\Conditional block in extend.php adds them (Extend\Integrate). */
class SlowMailServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        if (! $this->container->bound(PlaceSource::class)) {
            $this->container->singleton(PlaceSource::class, NoPlaces::class);
        }
        $this->container->singleton(Integrations::class, fn (Container $container) => new Integrations($container->tagged(Integration::TAG)));
    }
}
