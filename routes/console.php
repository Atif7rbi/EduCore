<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('educore:recover-d4 {--confirm=}', function (): int {
    if (! app()->environment('production')) {
        $this->error('Recovery is restricted to the production environment.');

        return 1;
    }

    if (! app()->isDownForMaintenance()) {
        $this->error('Recovery requires maintenance mode.');

        return 1;
    }

    if (config('database.default') !== 'pgsql') {
        $this->error('Recovery requires PostgreSQL.');

        return 1;
    }

    $databaseName = DB::connection()->getDatabaseName();

    if ($databaseName !== 'sewaellf_educore') {
        $this->error('Unexpected production database: '.$databaseName);

        return 1;
    }

    if ($this->option('confirm') !== 'RECOVER-D4') {
        $this->error('Pass --confirm=RECOVER-D4 to authorize reconstruction.');

        return 1;
    }

    $mustBeEmpty = [
        'users',
        'subjects',
        'curricula',
        'curriculum_versions',
        'skills',
        'topics',
        'skill_version_placements',
        'skill_home_topics',
        'lessons',
        'lesson_revisions',
        'lesson_revision_skills',
    ];

    foreach ($mustBeEmpty as $table) {
        if (DB::table($table)->exists()) {
            $this->error("Recovery aborted: {$table} is not empty.");

            return 1;
        }
    }

    $password = $this->secret('New EduCore admin password');

    if (! is_string($password) || mb_strlen($password) < 12) {
        $this->error('Admin password must contain at least 12 characters.');

        return 1;
    }

    DB::transaction(function () use ($password): void {
        $now = now();

        $adminId = (string) Str::uuid();
        $subjectId = (string) Str::uuid();
        $curriculumId = (string) Str::uuid();
        $versionId = (string) Str::uuid();
        $topicId = (string) Str::uuid();
        $lessonId = (string) Str::uuid();
        $revisionId = (string) Str::uuid();

        DB::table('users')->insert([
            'id' => $adminId,
            'name' => 'Al7rbi',
            'email' => 'atif7rbi@gmail.com',
            'email_verified_at' => $now,
            'password' => Hash::make($password),
            'status' => 'active',
            'remember_token' => null,
            'created_at' => $now,
            'updated_at' => $now,
            'role' => 'admin',
        ]);

        DB::table('subjects')->insert([
            'id' => $subjectId,
            'name' => 'القدرات العامة',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('curricula')->insert([
            'id' => $curriculumId,
            'subject_id' => $subjectId,
            'name' => 'القسم الكمي',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('curriculum_versions')->insert([
            'id' => $versionId,
            'curriculum_id' => $curriculumId,
            'version_number' => 1,
            'label' => 'النسخة الأولى',
            'status' => 'draft',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('topics')->insert([
            'id' => $topicId,
            'curriculum_version_id' => $versionId,
            'name' => 'النسب والتناسب',
            'display_order' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $skillNames = [
            'فهم النسبة',
            'حل التناسب',
            'تطبيق النسبة في المسائل اللفظية',
        ];

        $placementIds = [];

        foreach ($skillNames as $skillName) {
            $skillId = (string) Str::uuid();
            $placementId = (string) Str::uuid();

            DB::table('skills')->insert([
                'id' => $skillId,
                'name' => $skillName,
                'description' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('skill_version_placements')->insert([
                'id' => $placementId,
                'skill_id' => $skillId,
                'curriculum_version_id' => $versionId,
                'created_at' => $now,
            ]);

            DB::table('skill_home_topics')->insert([
                'id' => (string) Str::uuid(),
                'placement_id' => $placementId,
                'topic_id' => $topicId,
                'curriculum_version_id' => $versionId,
                'created_at' => $now,
            ]);

            $placementIds[] = $placementId;
        }

        DB::table('lessons')->insert([
            'id' => $lessonId,
            'curriculum_version_id' => $versionId,
            'title' => 'مدخل إلى النسب والتناسب',
            'description' => 'مقدمة في مفاهيم النسبة والتناسب الأساسية.',
            'status' => 'draft',
            'display_order' => 1,
            'published_revision_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('lesson_revisions')->insert([
            'id' => $revisionId,
            'lesson_id' => $lessonId,
            'curriculum_version_id' => $versionId,
            'revision_number' => 1,
            'primary_topic_id' => $topicId,
            'content_payload' => json_encode([
                'blocks' => [
                    [
                        'type' => 'text',
                        'value' => 'مقدمة في مفاهيم النسبة والتناسب الأساسية.',
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
            'content_schema_version' => 1,
            'released_at' => $now,
            'created_at' => $now,
        ]);

        foreach ($placementIds as $placementId) {
            DB::table('lesson_revision_skills')->insert([
                'id' => (string) Str::uuid(),
                'lesson_revision_id' => $revisionId,
                'skill_version_placement_id' => $placementId,
                'curriculum_version_id' => $versionId,
                'created_at' => $now,
            ]);
        }

        DB::table('lessons')
            ->where('id', $lessonId)
            ->update([
                'status' => 'published',
                'published_revision_id' => $revisionId,
                'updated_at' => $now,
            ]);
    });

    $this->info('D4 operational data reconstructed successfully.');
    $this->warn('Keep maintenance mode enabled until verification and backup complete.');

    return 0;
})->purpose('Reconstruct the known D4 production operational baseline after incident recovery');
