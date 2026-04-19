<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * LoadTestSeeder
 *
 * Seeds realistic data for the heavy-query load test:
 * - 50 users
 * - 10,000 posts (with varied status values — no index = slow scans)
 * - 30,000 comments (for N+1 simulation)
 *
 * Run with:
 *   php artisan db:seed --class=LoadTestSeeder
 */
class LoadTestSeeder extends Seeder
{
    private const USERS    = 50;
    private const POSTS    = 10_000;
    private const COMMENTS = 30_000;

    private const STATUSES  = ['draft', 'published', 'archived', 'pending', 'flagged'];
    private const CHUNK     = 500;

    public function run(): void
    {
        $this->command->info('Seeding load test data...');

        // ── Users ──────────────────────────────────────────────────────────────
        $this->command->info("Creating ".self::USERS." users...");
        $userIds = $this->seedUsers();

        // ── Posts (no index on status → slow full-table scans) ─────────────────
        $this->command->info("Creating ".self::POSTS." posts...");
        $postIds = $this->seedPosts($userIds);

        // ── Comments (for N+1 simulation) ──────────────────────────────────────
        $this->command->info("Creating ".self::COMMENTS." comments...");
        $this->seedComments($userIds, $postIds);

        $this->command->info('✓ Load test data seeded.');
    }

    /** @return list<int> */
    private function seedUsers(): array
    {
        $rows = [];
        $now  = now();

        for ($i = 0; $i < self::USERS; $i++) {
            $rows[] = [
                'name'       => 'Load User '.$i,
                'email'      => "loadtest{$i}@simxstudio.test",
                'password'   => bcrypt('password'),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('users')->insertOrIgnore($rows);

        return DB::table('users')
            ->whereIn('email', array_column($rows, 'email'))
            ->pluck('id')
            ->toArray();
    }

    /** @return list<int> */
    private function seedPosts(array $userIds): array
    {
        $postIds = [];
        $now     = now();
        $batch   = [];

        for ($i = 0; $i < self::POSTS; $i++) {
            $batch[] = [
                'user_id'       => $userIds[array_rand($userIds)],
                'title'         => 'Post Title '.Str::random(12).' #'.$i,
                'body'          => implode(' ', array_fill(0, rand(50, 300), Str::random(rand(4, 12)))),
                'status'        => self::STATUSES[array_rand(self::STATUSES)],
                'view_count'    => rand(0, 50_000),
                'comment_count' => rand(0, 500),
                'score'         => round(rand(0, 10000) / 1000, 4),
                'meta'          => json_encode(['tags' => [Str::random(6), Str::random(6)]]),
                'created_at'    => $now,
                'updated_at'    => $now,
            ];

            if (count($batch) >= self::CHUNK) {
                DB::table('load_test_posts')->insert($batch);
                $batch = [];
            }
        }

        if (!empty($batch)) {
            DB::table('load_test_posts')->insert($batch);
        }

        return DB::table('load_test_posts')->pluck('id')->toArray();
    }

    /** @param list<int> $userIds @param list<int> $postIds */
    private function seedComments(array $userIds, array $postIds): void
    {
        $now   = now();
        $batch = [];

        for ($i = 0; $i < self::COMMENTS; $i++) {
            $batch[] = [
                'post_id'    => $postIds[array_rand($postIds)],
                'user_id'    => $userIds[array_rand($userIds)],
                'body'       => 'Comment: '.Str::random(rand(20, 100)),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($batch) >= self::CHUNK) {
                DB::table('load_test_comments')->insert($batch);
                $batch = [];
            }
        }

        if (!empty($batch)) {
            DB::table('load_test_comments')->insert($batch);
        }
    }
}
