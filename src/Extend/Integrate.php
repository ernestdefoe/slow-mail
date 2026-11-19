<?php

namespace ErnestDefoe\SlowMail\Extend;

use ErnestDefoe\SlowMail\Integration\Integration;
use ErnestDefoe\SlowMail\PlaceSource;
use Flarum\Extend\ExtenderInterface;
use Flarum\Extension\Extension;
use Illuminate\Contracts\Container\Container;

/**
 * Wires another extension into Slow Mail. Used only inside Extend\Conditional
 * blocks in extend.php, so an integration exists exactly while its extension
 * is enabled:
 *
 *   (new Integrate())->with(Integration\Parley::class)
 *   (new Integrate())->places(MemberMapPlaces::class)
 */
class Integrate implements ExtenderInterface
{
    /** @var list<class-string<Integration>> */
    private array $integrations = [];

    /** @var class-string<PlaceSource>|null */
    private ?string $places = null;

    /** @param class-string<Integration> $class */
    public function with(string $class): self
    {
        $this->integrations[] = $class;

        return $this;
    }

    /** @param class-string<PlaceSource> $class */
    public function places(string $class): self
    {
        $this->places = $class;

        return $this;
    }

    public function extend(Container $container, ?Extension $extension = null): void
    {
        if ($this->integrations) {
            $container->tag($this->integrations, Integration::TAG);
        }
        if ($this->places) {
            $container->singleton(PlaceSource::class, $this->places);
        }
    }
}
