<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** 25 posts with 3 comments each: enough rows for a loop to show its N+1. */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        foreach (range(1, 25) as $i) {
            $post = DB::table('demo_posts')->insertGetId(['title' => "Post {$i}", 'created_at' => now(), 'updated_at' => now()]);

            DB::table('demo_comments')->insert(array_map(fn (int $j): array => [
                'post_id' => $post, 'body' => "Comment {$j} on post {$i}", 'created_at' => now(), 'updated_at' => now(),
            ], range(1, 3)));
        }
    }
}
