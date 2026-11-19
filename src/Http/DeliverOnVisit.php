<?php

namespace ErnestDefoe\SlowMail\Http;

use ErnestDefoe\SlowMail\Job\Deliver;
use ErnestDefoe\SlowMail\Mailroom;
use Illuminate\Contracts\Queue\Queue;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Delivery for forums whose scheduler isn't running: once the next arrival
 * (Mailroom keeps it in the cache) has passed, the first request queues a
 * delivery (Job\Deliver); others in the same minute don't. Every other
 * request costs one cache read and no query.
 */
class DeliverOnVisit implements MiddlewareInterface
{
    public function __construct(private readonly Mailroom $mailroom, private readonly Queue $queue)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->mailroom->claimDueDelivery()) {
            $this->queue->push(new Deliver());
        }

        return $handler->handle($request);
    }
}
