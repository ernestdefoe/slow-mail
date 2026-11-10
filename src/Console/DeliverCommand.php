<?php

namespace ErnestDefoe\SlowMail\Console;

use ErnestDefoe\SlowMail\Mailroom;
use Flarum\Console\AbstractCommand;

/** Scheduled every minute (extend.php). Safe to run by hand, or twice at once: see Mailroom. */
class DeliverCommand extends AbstractCommand
{
    public function __construct(private readonly Mailroom $mailroom)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('slow-mail:deliver')->setDescription('Deliver the letters that have arrived.');
    }

    protected function fire(): int
    {
        $delivered = $this->mailroom->run();
        if ($delivered) {
            $this->info("Delivered {$delivered}.");
        }

        return 0;
    }
}
