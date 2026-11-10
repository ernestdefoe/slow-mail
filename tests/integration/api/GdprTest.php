<?php

namespace ErnestDefoe\SlowMail\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\SlowMail\Tests\integration\Helpers;
use Flarum\Foundation\Paths;
use Flarum\Gdpr\Jobs\ExportJob;
use Flarum\Gdpr\Jobs\ErasureJob;
use Flarum\Gdpr\Models\ErasureRequest;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

/**
 * A member's own export carries their letters; a moderator's export of them
 * carries envelopes only. Letters still on their way to a member are in
 * nobody's export of that member. Erasure removes every letter they wrote or got.
 */
class GdprTest extends TestCase
{
    use Helpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extension('flarum-gdpr', 'ernestdefoe-slow-mail');
        $this->members(false);
    }

    private function export(int $user, int $actor): string
    {
        $container = $this->app()->getContainer();
        $container->call([new ExportJob(User::findOrFail($user), User::findOrFail($actor)), 'handle']);

        $zips = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($container->make(Paths::class)->storage, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if (str_ends_with($file->getFilename(), '.zip')) {
                $zips[$file->getMTime().$file->getPathname()] = $file->getPathname();
            }
        }
        $this->assertNotEmpty($zips, 'no export written');
        krsort($zips);
        $zip = new ZipArchive();
        $zip->open(reset($zips));
        $ours = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (str_starts_with($zip->getNameIndex($i), 'slow-mail/')) {
                $ours .= $zip->getFromIndex($i);
            }
        }
        $zip->close();
        array_map('unlink', $zips);

        return $ours;
    }

    #[Test]
    public function exports_carry_words_only_for_the_member_themself()
    {
        $delivered = $this->write(2, 3)[1]['letter']['id'];
        $this->arrive($delivered);
        $this->write(2, 3, ['body' => 'Still travelling '.self::MARKER]);

        $own = $this->export(3, 3);
        $this->assertSame(1, substr_count($own, self::MARKER), 'the one that arrived, not the one on its way');
        $this->assertStringNotContainsString('Still travelling', $own);

        $byModerator = $this->export(3, 1);
        $this->assertStringContainsString('"received"', $byModerator);
        $this->assertStringNotContainsString(self::MARKER, $byModerator);
        $this->assertStringNotContainsString('with_user_id', $byModerator, 'nor who they write to');
        $this->assertStringNotContainsString('lighthouse', $byModerator);

        $this->assertSame(2, substr_count($this->export(2, 2), self::MARKER), 'the writer has both');

        // A moderator's export of the writer: only the letter that arrived, not the one still travelling.
        $this->assertSame(1, substr_count($this->export(2, 1), '"sent"'));
    }

    #[Test]
    public function erasure_removes_every_letter_they_wrote_or_got()
    {
        $this->write(2, 3);
        $this->write(3, 2);
        $this->write(3, 4);
        $this->call('POST', '/api/slow-mail/seeking', 2, ['interests' => 'tea', 'region' => 'anywhere']);
        $this->call('POST', '/api/slow-mail/blocks', 2, ['user' => 4, 'blocked' => true]);

        $request = ErasureRequest::unguarded(fn () => ErasureRequest::create(['user_id' => 2, 'status' => 'manual', 'created_at' => Carbon::now(), 'user_confirmed_at' => Carbon::now(), 'processed_mode' => 'anonymization']));
        $this->app()->getContainer()->call([new ErasureJob($request), 'handle']);

        $this->assertSame(1, $this->database()->table('slow_mail_letters')->count(), 'only Theo to Mira is left');
        $this->assertSame(0, $this->database()->table('slow_mail_seekers')->count());
        $this->assertSame(0, $this->database()->table('slow_mail_blocks')->count());
    }
}
