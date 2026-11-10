<?php

namespace ErnestDefoe\SlowMail\Http;

use ErnestDefoe\SlowMail\Mailroom;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Container\Container;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Delivery for forums whose scheduler isn't running: any request after the
 * next arrival (Mailroom keeps it in the cache) delivers what's due, once,
 * under a lock. Every other request costs one cache read and no query.
 */
class DeliverOnVisit implements MiddlewareInterface
{
    public function __construct(private readonly Cache $cache, private readonly Container $container)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $next = $this->cache->get(Mailroom::NEXT);

        if ($next === null || (is_numeric($next) && (int) $next <= time())) {
            try {
                $this->container->make(Mailroom::class)->runIfDue();
            } catch (Throwable $e) {
                // Never fail a page for the post office: the scheduler or the next visit tries again.
                $this->container->make(LoggerInterface::class)->error('[slow-mail] delivering on a visit: '.$e->getMessage());
            }
        }

        return $handler->handle($request);
    }
}
