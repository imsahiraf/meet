<?php

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Jobs\SeedDailyLoadTestUsersJob;
use Modules\Admin\Models\Setting;
use Modules\Authentication\Models\User;
use Modules\User\Models\UserProfile;
use Tests\TestCase;

class LoadTestSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_command_clamps_to_the_configured_max_users_cap(): void
    {
        Setting::create(['setting_key' => 'load_test_max_users', 'setting_value' => '30', 'category' => 'internal', 'data_type' => 'integer']);

        $this->artisan('load-test:seed-users', ['count' => 20])->assertSuccessful();
        $this->assertSame(20, User::where('email', 'like', '%@mail.meet')->count());

        // Requesting 20 more would exceed the cap of 30 (10 remain) -
        // should silently clamp to 10, not insert the full 20.
        $this->artisan('load-test:seed-users', ['count' => 20])->assertSuccessful();
        $this->assertSame(30, User::where('email', 'like', '%@mail.meet')->count());

        // Cap already reached - a further request inserts nothing.
        $this->artisan('load-test:seed-users', ['count' => 5])->assertSuccessful();
        $this->assertSame(30, User::where('email', 'like', '%@mail.meet')->count());
    }

    public function test_seed_command_defaults_to_the_150k_cap_when_no_setting_is_configured(): void
    {
        $this->artisan('load-test:seed-users', ['count' => 5])->assertSuccessful();
        $this->assertSame(5, User::where('email', 'like', '%@mail.meet')->count());
    }

    public function test_seeded_names_include_a_real_mix_of_hindu_and_muslim_first_names(): void
    {
        $this->artisan('load-test:seed-users', ['count' => 500])->assertSuccessful();

        $names = User::where('email', 'like', '%@mail.meet')->pluck('full_name');
        $muslimMarkers = ['Mohammed', 'Ahmed', 'Fatima', 'Zara', 'Ayesha', 'Imran', 'Khan', 'Sheikh', 'Ansari', 'Siddiqui', 'Qureshi'];
        $hinduMarkers = ['Sharma', 'Verma', 'Gupta', 'Singh', 'Kumar', 'Patel', 'Aarav', 'Priya', 'Ananya'];

        $hasMuslimName = $names->contains(fn ($name) => collect($muslimMarkers)->contains(fn ($marker) => str_contains($name, $marker)));
        $hasHinduName = $names->contains(fn ($name) => collect($hinduMarkers)->contains(fn ($marker) => str_contains($name, $marker)));

        $this->assertTrue($hasMuslimName, 'expected at least one seeded name to match a Muslim name/surname marker out of 500 seeded users');
        $this->assertTrue($hasHinduName, 'expected at least one seeded name to match a Hindu name/surname marker out of 500 seeded users');
    }

    /**
     * Real reason this matters: the seeder bypasses
     * CreateDefaultUserRecords entirely for bulk-insert speed, so
     * without this, every load-test account's Settings page would show
     * permanently blank name fields - exactly the gap that led to the
     * production-incident backfill batch job.
     */
    public function test_seeded_users_have_first_and_last_name_populated_on_their_profile(): void
    {
        $this->artisan('load-test:seed-users', ['count' => 50])->assertSuccessful();

        $userIds = User::where('email', 'like', '%@mail.meet')->pluck('id');
        $profiles = UserProfile::whereIn('user_id', $userIds)->get();

        $this->assertCount(50, $profiles);
        $this->assertTrue($profiles->every(fn ($p) => filled($p->first_name) && filled($p->last_name)), 'expected every seeded profile to have first_name and last_name set');
    }

    public function test_daily_job_stops_once_the_max_users_cap_is_reached_even_before_day_7(): void
    {
        Setting::create(['setting_key' => 'load_test_max_users', 'setting_value' => '15000', 'category' => 'internal', 'data_type' => 'integer']);

        // Day 1: seeds 10,000 (well under the 15,000 cap).
        (new SeedDailyLoadTestUsersJob)->handle();
        $this->assertSame(10000, User::where('email', 'like', '%@mail.meet')->count());
        $this->assertSame('1', Setting::where('setting_key', 'load_test_seed_days_run')->value('setting_value'));

        // Day 2: only 5,000 capacity remains under the cap - clamps
        // instead of inserting the full 10,000, even though we're only
        // on day 2 of 7.
        (new SeedDailyLoadTestUsersJob)->handle();
        $this->assertSame(15000, User::where('email', 'like', '%@mail.meet')->count());

        // Day 3: cap already reached - inserts nothing, but the day
        // counter still advances (it's an independent condition).
        (new SeedDailyLoadTestUsersJob)->handle();
        $this->assertSame(15000, User::where('email', 'like', '%@mail.meet')->count());
        $this->assertSame('3', Setting::where('setting_key', 'load_test_seed_days_run')->value('setting_value'));
    }
}
