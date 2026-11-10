<?php

namespace ErnestDefoe\SlowMail\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\SlowMail\Journey;
use ErnestDefoe\SlowMail\Places;
use ErnestDefoe\SlowMail\Tests\integration\Helpers;
use Flarum\Group\Group;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * A travel time must never place anyone more precisely than they chose on
 * Member Map, and never use a place the other member couldn't see there.
 */
class JourneyTest extends TestCase
{
    use Helpers;

    private const SPEED = ['km_per_day' => 2000, 'min' => 1, 'max' => 24 * 30, 'default' => 24];

    protected function setUp(): void
    {
        parent::setUp();
        $this->extension('ernestdefoe-member-map', 'ernestdefoe-slow-mail');
        $this->members();
    }

    /** @return array<string, array{string}> */
    public static function precisions(): array
    {
        return ['city' => ['city'], 'region' => ['region'], 'country' => ['country']];
    }

    /**
     * The attacker knows the formula and the travel time, not the key. Every
     * distance that could have produced that time (for some jitter) is still
     * a candidate: those must span two whole bands (1,000 km for cities), or
     * from 0 to a band and a half for near neighbours, wherever the truth is.
     * The fastest settings an admin can choose (1 hour minimum).
     */
    #[Test]
    #[DataProvider('precisions')]
    public function a_travel_time_fits_a_wide_range_of_distances(string $precision)
    {
        $band = Journey::BANDS[$precision];

        $v = self::SPEED['km_per_day'] / 24;
        // Could this distance have given $hours, for any jitter in [-1, 1]? Solved exactly, not sampled.
        $fits = function (int $km, int $hours) use ($band, $v): bool {
            $k = max(1, round($km / $band)) * $band;
            $low = $hours === self::SPEED['min'] ? -INF : (($hours - 1) * $v - $k) / ($band * Journey::JITTER);
            $high = $hours === self::SPEED['max'] ? INF : ($hours * $v - $k) / ($band * Journey::JITTER);

            return $high >= -1 && $low < 1;
        };

        for ($truth = 0; $truth <= 12000; $truth += 37) {
            foreach ([-0.93, -0.4, 0.0, 0.55, 0.97] as $unit) {
                $observed = Journey::hours($truth, $precision, $precision, $unit, self::SPEED);

                $candidates = array_values(array_filter(range(0, 20000, 10), fn ($km) => $fits($km, $observed)));
                $this->assertTrue($fits($truth, $observed), 'the truth itself fits');

                // Two whole bands of distances; for near neighbours (the first band) from 0 to a band and a half.
                $span = max($candidates) - min($candidates) + 10;
                $this->assertGreaterThanOrEqual($truth >= 2 * $band ? 2 * $band : 1.5 * $band, $span, "$precision: {$truth} km gave {$observed} h, which pins it to {$span} km");
            }
        }
    }

    #[Test]
    public function the_coarser_precision_of_the_two_sets_the_band()
    {
        // 250 km apart: city to city rounds to 0 or 500; with a country-level member it rounds to 0.
        $this->assertSame(Journey::hours(250, 'city', 'country', 0, self::SPEED), Journey::hours(900, 'city', 'country', 0, self::SPEED));
        $this->assertNotSame(Journey::hours(250, 'city', 'city', 0, self::SPEED), Journey::hours(900, 'city', 'city', 0, self::SPEED));
    }

    #[Test]
    public function the_same_two_places_always_give_the_same_time()
    {
        $hours = [];
        foreach (range(1, 3) as $i) {
            [, $body] = $this->write(2, 3, ['body' => "letter $i"]);
            $hours[] = $body['letter']['travelHours'];
        }
        $this->assertCount(1, array_unique($hours), 'sending again must not average the jitter away');

        // Lyon to Nashville is ~7,450 km: about 3.7 days at 2,000 km a day, give or take the jitter.
        $this->assertGreaterThanOrEqual(80, $hours[0]);
        $this->assertLessThanOrEqual(100, $hours[0]);
    }

    #[Test]
    public function different_pairs_at_the_same_distance_get_different_times()
    {
        $journey = $this->app()->getContainer()->make(Journey::class);
        $users = [];
        $rows = [];
        foreach (range(10, 40) as $id) {
            $users[] = ['id' => $id, 'username' => "u$id", 'email' => "u$id@machine.local", 'is_email_confirmed' => 1, 'password' => '', 'joined_at' => Carbon::now()];
            $rows[] = ['user_id' => $id, 'lat' => 36.16, 'lng' => -86.77, 'precision' => 'city', 'label' => 'Nashville, United States', 'country_code' => 'us', 'show_on_profile' => 1, 'updated_at' => Carbon::now()];
        }
        $this->database()->table('users')->insert($users);
        $this->database()->table('member_map_locations')->insert($rows);

        $lena = User::find(2);
        $times = [];
        foreach (range(10, 40) as $id) {
            $times[] = $journey->plan($lena, User::find($id))['hours'];
        }
        $this->assertGreaterThan(3, count(array_unique($times)), 'jitter exists');
    }

    #[Test]
    public function arrival_is_on_the_hour_and_the_eta_says_exactly_that()
    {
        [, $quote] = $this->call('GET', '/api/slow-mail/quote?to=3', 2);
        [, $body] = $this->write(2, 3);

        $arrives = Carbon::parse($body['letter']['arrivesAt']);
        $this->assertSame(0, $arrives->minute);
        $this->assertSame(0, $arrives->second);
        $this->assertSame($quote['hours'], $body['letter']['travelHours']);
        $this->assertTrue($quote['mapped']);
    }

    #[Test]
    public function a_place_the_other_member_cannot_see_is_never_used()
    {
        $default = fn (int $to) => $this->write(2, $to, ['body' => 'x'.$to])[1]['letter'];

        // Mira has no place: the set time (24 h, give or take a quarter).
        $mira = $default(4);
        $this->assertGreaterThanOrEqual(18, $mira['travelHours']);
        $this->assertLessThanOrEqual(30, $mira['travelHours']);
        $this->assertNull($mira['destination']);

        // Theo stops being listed on the map (loses the permission): his place is no longer used, and isn't named.
        $this->database()->table('group_permission')->where('permission', 'member-map.list')->delete();
        $this->database()->table('group_permission')->insert(['group_id' => Group::MODERATOR_ID, 'permission' => 'member-map.list']);
        [, $quote] = $this->call('GET', '/api/slow-mail/quote?to=3', 2);
        $this->assertFalse($quote['mapped']);
        $this->assertNull($quote['destination']);
        $this->assertNull($quote['postmark'], 'nor is Lena\'s: she is off the map too');
        $this->assertLessThanOrEqual(30, $quote['hours']);
    }

    #[Test]
    public function a_postmark_never_names_a_city()
    {
        $this->assertSame('France', Places::postmark('Lyon, France', 'city'));
        $this->assertSame('Bavaria, Germany', Places::postmark('Bavaria, Germany', 'region'));
        $this->assertSame('Japan', Places::postmark('Japan', 'country'));

        $letter = $this->write(2, 3)[1]['letter'];
        $this->assertSame('France', $letter['postmark']);
        $this->assertSame('fr', $letter['origin']);
        $this->assertSame('United States', $letter['destination']);
        $this->assertStringNotContainsString('Lyon', json_encode($letter));
        $this->assertStringNotContainsString('Nashville', json_encode($this->call('GET', '/api/slow-mail', 2)[1]));
    }
}
