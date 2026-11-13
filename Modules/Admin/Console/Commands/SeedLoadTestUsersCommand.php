<?php

namespace Modules\Admin\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Models\Setting;
use Modules\Authentication\Models\User;

/**
 * Load-test data generator - see LOAD_TESTING.md for what this is,
 * why it exists, and how to remove it (load-test:cleanup-users).
 *
 * Deliberately not using fakerphp/faker for the names: it's a
 * require-dev-only dependency, and deploy/prepare-release.sh runs
 * `composer install --no-dev` before every real release - it would
 * silently be missing in production, exactly where this command needs
 * to run (via deploy/version-actions.php). A small hand-picked Indian
 * name list sidesteps that entirely.
 *
 * Bulk raw inserts in chunks, not per-row Eloquent saves - this needs
 * to comfortably create 100,000+ rows without spending minutes on
 * model-event overhead for each one.
 */
class SeedLoadTestUsersCommand extends Command
{
    protected $signature = 'load-test:seed-users {count=100000}';

    protected $description = 'Seeds fake Indian-named test users (emails ending @mail.meet) for load testing';

    // Hindu + Muslim, male + female - the original list here was
    // entirely Hindu-origin names, which didn't reflect a realistic
    // mixed population.
    private const FIRST_NAMES = [
        'Aarav', 'Vivaan', 'Aditya', 'Vihaan', 'Arjun', 'Reyansh', 'Krishna', 'Ishaan', 'Rohan',
        'Aryan', 'Dhruv', 'Karan', 'Rahul', 'Aman', 'Yash', 'Sai', 'Varun', 'Nikhil',
        'Rajesh', 'Suresh', 'Mahesh', 'Ramesh', 'Sanjay', 'Vijay', 'Ajay', 'Deepak', 'Manoj', 'Anil',
        'Saanvi', 'Aadhya', 'Ananya', 'Diya', 'Ishita', 'Kavya', 'Myra', 'Pari', 'Anika', 'Riya',
        'Priya', 'Neha', 'Pooja', 'Sneha', 'Divya', 'Meera', 'Nisha', 'Shreya', 'Anjali', 'Kritika',
        'Mohammed', 'Ahmed', 'Imran', 'Sameer', 'Zain', 'Rehan', 'Farhan', 'Irfan', 'Salman',
        'Aamir', 'Rizwan', 'Naveed', 'Adil', 'Faisal', 'Kabir', 'Yusuf', 'Danish', 'Arbaaz',
        'Ayesha', 'Fatima', 'Zara', 'Sana', 'Nazia', 'Rukhsar', 'Alia', 'Sabiha', 'Mehreen', 'Iqra',
    ];

    private const LAST_NAMES = [
        'Sharma', 'Verma', 'Gupta', 'Singh', 'Kumar', 'Patel', 'Shah', 'Mehta', 'Iyer', 'Nair',
        'Reddy', 'Rao', 'Naidu', 'Pillai', 'Menon', 'Joshi', 'Desai', 'Kapoor', 'Malhotra', 'Chopra',
        'Agarwal', 'Bansal', 'Jain', 'Khanna', 'Bhatt', 'Trivedi', 'Pandey', 'Mishra', 'Tiwari', 'Yadav',
        'Chauhan', 'Rathore', 'Solanki', 'Thakur', 'Bhatia', 'Arora', 'Sethi', 'Saxena', 'Goel', 'Suri',
        'Khan', 'Ahmed', 'Sheikh', 'Ansari', 'Siddiqui', 'Qureshi', 'Malik', 'Hussain', 'Sayyed', 'Pathan',
    ];

    private const SEQUENCE_SETTING_KEY = 'load_test_next_user_sequence';

    private const MAX_USERS_SETTING_KEY = 'load_test_max_users';

    private const DEFAULT_MAX_USERS = 150000;

    public function handle(): void
    {
        $count = max(0, (int) $this->argument('count'));

        if ($count === 0) {
            $this->info('Nothing to seed (count=0).');

            return;
        }

        // Admin-configurable hard ceiling (Setting, not hardcoded) -
        // an independent stop condition alongside the daily job's own
        // 7-day limit (see SeedDailyLoadTestUsersJob): whichever is
        // hit first wins, and this cap applies just as much to the
        // one-off initial seed as to the daily top-up.
        $maxUsers = (int) (Setting::where('setting_key', self::MAX_USERS_SETTING_KEY)->value('setting_value') ?? self::DEFAULT_MAX_USERS);
        $currentTotal = User::withTrashed()->where('email', 'like', '%@mail.meet')->count();
        $remainingCapacity = max(0, $maxUsers - $currentTotal);

        if ($remainingCapacity === 0) {
            $this->info("Load-test user cap already reached ({$currentTotal}/{$maxUsers}). Nothing seeded.");

            return;
        }

        if ($count > $remainingCapacity) {
            $this->info("Requested {$count}, but only {$remainingCapacity} remain before the {$maxUsers} cap - seeding {$remainingCapacity} instead.");
            $count = $remainingCapacity;
        }

        $startSequence = (int) (Setting::where('setting_key', self::SEQUENCE_SETTING_KEY)->value('setting_value') ?? 1);
        $chunkSize = 2000;
        $now = now();
        $created = 0;

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        for ($offset = 0; $offset < $count; $offset += $chunkSize) {
            $batchSize = min($chunkSize, $count - $offset);
            $this->insertBatch($startSequence + $offset, $batchSize, $now);
            $created += $batchSize;
            $bar->advance($batchSize);
        }

        $bar->finish();
        $this->newLine(2);

        Setting::updateOrCreate(
            ['setting_key' => self::SEQUENCE_SETTING_KEY],
            ['setting_value' => (string) ($startSequence + $count), 'category' => 'internal', 'data_type' => 'integer', 'is_editable' => false],
        );

        $this->info("Seeded {$created} load-test users (emails ending @mail.meet). See LOAD_TESTING.md for cleanup.");
    }

    private function insertBatch(int $sequenceStart, int $batchSize, \DateTimeInterface $now): void
    {
        $userRows = [];
        $names = [];

        for ($i = 0; $i < $batchSize; $i++) {
            $sequence = $sequenceStart + $i;
            $firstName = self::FIRST_NAMES[array_rand(self::FIRST_NAMES)];
            $lastName = self::LAST_NAMES[array_rand(self::LAST_NAMES)];
            $names[] = [$firstName, $lastName];

            $userRows[] = [
                'uuid' => (string) Str::uuid(),
                'full_name' => "{$firstName} {$lastName}",
                'email' => strtolower("{$firstName}.{$lastName}{$sequence}").'@mail.meet',
                // A dedicated, clearly-synthetic 10-digit block ('9' + a
                // zero-padded sequence) - guaranteed unique via the
                // persisted sequence counter, never reused across runs.
                'phone' => '+919'.str_pad((string) $sequence, 9, '0', STR_PAD_LEFT),
                'password' => bcrypt(Str::random(32)),
                'status' => 'active',
                'email_verified_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('users')->insert($userRows);

        // MySQL guarantees sequential auto-increment ids for a single
        // multi-row INSERT (default innodb_autoinc_lock_mode) - the PDO
        // driver's lastInsertId() after that statement is the *first*
        // row's id, so the rest can be computed without a second query
        // or any risk of picking up someone else's concurrently-inserted
        // rows (a real, considered alternative that was rejected).
        // SQLite (tests only - production is MySQL) instead returns
        // the *last* inserted rowid for a multi-row INSERT, the
        // opposite convention - caught via a real PHPUnit run against
        // SQLite, not assumed.
        $lastInsertId = (int) DB::getPdo()->lastInsertId();
        $firstId = DB::getDriverName() === 'sqlite' ? $lastInsertId - $batchSize + 1 : $lastInsertId;
        $ids = range($firstId, $firstId + $batchSize - 1);

        $childRows = array_map(fn (int $id) => [
            'uuid' => (string) Str::uuid(),
            'user_id' => $id,
            'created_at' => $now,
            'updated_at' => $now,
        ], $ids);

        // A dedicated shape (not $childRows) so the Settings page never
        // shows a load-test account's name fields blank - real
        // registrations get this via CreateDefaultUserRecords, but this
        // seeder bypasses that listener entirely for bulk-insert speed.
        $profileRows = array_map(fn (int $id, array $name) => [
            'uuid' => (string) Str::uuid(),
            'user_id' => $id,
            'first_name' => $name[0],
            'last_name' => $name[1],
            'created_at' => $now,
            'updated_at' => $now,
        ], $ids, $names);

        DB::table('user_profiles')->insert($profileRows);
        DB::table('user_preferences')->insert($childRows);
        DB::table('user_privacy')->insert($childRows);

        // user_presence has no created_at column at all (only a
        // nullable updated_at) - a separate row shape, not $childRows.
        DB::table('user_presence')->insert(array_map(fn (int $id) => [
            'uuid' => (string) Str::uuid(),
            'user_id' => $id,
            'updated_at' => $now,
        ], $ids));
    }
}
