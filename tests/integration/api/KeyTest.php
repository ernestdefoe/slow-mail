<?php

namespace ErnestDefoe\SlowMail\Tests\integration\api;

use ErnestDefoe\SlowMail\Tests\integration\Helpers;
use ErnestDefoe\SlowMail\Vault;
use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;

/** A changed key must stop sending, not silently write letters under a key the old ones can't be read with. */
class KeyTest extends TestCase
{
    use Helpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extension('ernestdefoe-slow-mail');
        $this->members(false);
    }

    #[Test]
    public function a_changed_key_stops_sending()
    {
        $this->assertSame(201, $this->write(2, 3)[0]);
        $this->assertNotEmpty($this->database()->table('settings')->where('key', Vault::CHECK)->value('value'), 'fingerprint saved with the first letter');

        // Someone puts another key in place (a key file from another server).
        $file = $this->app()->getContainer()->make(Vault::class)->keyFile();
        $original = file_get_contents($file);
        file_put_contents($file, base64_encode(random_bytes(32)));

        try {
            $refused = $this->write(2, 4);
            $this->assertSame(503, $refused[0], $refused[2]);
            $this->assertSame('slow_mail_key_missing', $refused[1]['errors'][0]['code']);
            $this->assertSame(1, $this->database()->table('slow_mail_letters')->count());
        } finally {
            file_put_contents($file, $original);
        }
    }
}
