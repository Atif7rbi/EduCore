<?php

namespace Tests\Feature;

use App\Models\LearnerProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CurriculumDiscoveryApiTest extends TestCase
{
    public function test_curriculum_discovery_requires_authentication(): void
    {
        $this->getJson('/api/curricula')
            ->assertStatus(401);
    }

    public function test_curriculum_discovery_requires_learner_profile(): void
    {
        $user = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);

        $this->actingAs($user);

        $this->getJson('/api/curricula')
            ->assertStatus(403)
            ->assertJsonPath(
                'error.code',
                'learner_profile_required'
            );
    }

    public function test_discovery_returns_published_versions_and_hides_unpublished_versions(): void
    {
        $this->authenticateLearner();

        [
            'subject_id' => $subjectId,
            'curriculum_id' => $curriculumId,
            'version_ids' => $versionIds,
        ] = $this->createCurriculum(
            'P31 Published Subject '.Str::uuid(),
            'P31 Published Curriculum '.Str::uuid(),
            [
                [
                    'number' => 1,
                    'label' => 'الإصدار الأول',
                    'status' => 'published',
                ],
                [
                    'number' => 2,
                    'label' => 'الإصدار الثاني',
                    'status' => 'published',
                ],
                [
                    'number' => 3,
                    'label' => 'مسودة مخفية',
                    'status' => 'draft',
                ],
                [
                    'number' => 4,
                    'label' => 'نسخة متقاعدة',
                    'status' => 'retired',
                ],
            ],
        );

        $data = $this->getJson('/api/curricula')
            ->assertOk()
            ->json('data');

        $entry = collect($data)
            ->firstWhere('curriculum.id', $curriculumId);

        $this->assertNotNull($entry);

        $this->assertSame(
            $subjectId,
            $entry['subject']['id']
        );

        $this->assertSame(
            $curriculumId,
            $entry['curriculum']['id']
        );

        $this->assertCount(
            2,
            $entry['published_versions']
        );

        $this->assertSame(
            $versionIds[1],
            $entry['published_versions'][0]['id']
        );

        $this->assertSame(
            1,
            $entry['published_versions'][0]['version_number']
        );

        $this->assertSame(
            $versionIds[2],
            $entry['published_versions'][1]['id']
        );

        $this->assertSame(
            2,
            $entry['published_versions'][1]['version_number']
        );

        $visibleVersionIds = collect(
            $entry['published_versions']
        )->pluck('id');

        $this->assertFalse(
            $visibleVersionIds->contains($versionIds[3])
        );

        $this->assertFalse(
            $visibleVersionIds->contains($versionIds[4])
        );
    }

    public function test_discovery_orders_subjects_curricula_and_versions_deterministically(): void
    {
        $this->authenticateLearner();

        $prefix = 'P31 Order '.Str::uuid();

        $first = $this->createCurriculum(
            "{$prefix} A Subject",
            "{$prefix} A Curriculum",
            [
                [
                    'number' => 1,
                    'label' => 'v1',
                    'status' => 'published',
                ],
            ],
        );

        $second = $this->createCurriculum(
            "{$prefix} B Subject",
            "{$prefix} B Curriculum",
            [
                [
                    'number' => 2,
                    'label' => 'v2',
                    'status' => 'published',
                ],
                [
                    'number' => 1,
                    'label' => 'v1',
                    'status' => 'published',
                ],
            ],
        );

        $data = $this->getJson('/api/curricula')
            ->assertOk()
            ->json('data');

        $curriculumIds = collect($data)
            ->pluck('curriculum.id')
            ->values();

        $firstIndex = $curriculumIds->search(
            $first['curriculum_id'],
            strict: true,
        );

        $secondIndex = $curriculumIds->search(
            $second['curriculum_id'],
            strict: true,
        );

        $this->assertNotFalse($firstIndex);
        $this->assertNotFalse($secondIndex);

        $this->assertLessThan(
            $secondIndex,
            $firstIndex
        );

        $secondEntry = collect($data)
            ->firstWhere(
                'curriculum.id',
                $second['curriculum_id']
            );

        $this->assertNotNull($secondEntry);

        $this->assertSame(
            [1, 2],
            collect(
                $secondEntry['published_versions']
            )
                ->pluck('version_number')
                ->values()
                ->all()
        );
    }

    public function test_curriculum_without_published_versions_is_not_discoverable(): void
    {
        $this->authenticateLearner();

        $draftOnly = $this->createCurriculum(
            'P31 Hidden Subject '.Str::uuid(),
            'P31 Hidden Curriculum '.Str::uuid(),
            [
                [
                    'number' => 1,
                    'label' => 'draft',
                    'status' => 'draft',
                ],
                [
                    'number' => 2,
                    'label' => 'retired',
                    'status' => 'retired',
                ],
            ],
        );

        $data = $this->getJson('/api/curricula')
            ->assertOk()
            ->json('data');

        $curriculumIds = collect($data)
            ->pluck('curriculum.id');

        $this->assertFalse(
            $curriculumIds->contains(
                $draftOnly['curriculum_id']
            )
        );
    }

    private function authenticateLearner(): LearnerProfile
    {
        $user = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);

        $learner = LearnerProfile::create([
            'user_id' => $user->id,
        ]);

        $this->actingAs($user);

        return $learner;
    }

    /**
     * @param array<int, array{
     *     number: int,
     *     label: string,
     *     status: 'draft'|'published'|'retired'
     * }> $versions
     *
     * @return array{
     *     subject_id: string,
     *     curriculum_id: string,
     *     version_ids: array<int, string>
     * }
     */
    private function createCurriculum(
        string $subjectName,
        string $curriculumName,
        array $versions,
    ): array {
        $subjectId = (string) Str::uuid();
        $curriculumId = (string) Str::uuid();

        DB::table('subjects')->insert([
            'id' => $subjectId,
            'name' => $subjectName,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('curricula')->insert([
            'id' => $curriculumId,
            'subject_id' => $subjectId,
            'name' => $curriculumName,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $versionIds = [];

        foreach ($versions as $version) {
            $versionId = (string) Str::uuid();

            DB::table('curriculum_versions')->insert([
                'id' => $versionId,
                'curriculum_id' => $curriculumId,
                'version_number' => $version['number'],
                'label' => $version['label'],
                'status' => 'draft',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if (
                $version['status'] === 'published'
                || $version['status'] === 'retired'
            ) {
                DB::table('curriculum_versions')
                    ->where('id', $versionId)
                    ->update([
                        'status' => 'published',
                        'updated_at' => now(),
                    ]);
            }

            if ($version['status'] === 'retired') {
                DB::table('curriculum_versions')
                    ->where('id', $versionId)
                    ->update([
                        'status' => 'retired',
                        'updated_at' => now(),
                    ]);
            }

            $versionIds[$version['number']] = $versionId;
        }

        return [
            'subject_id' => $subjectId,
            'curriculum_id' => $curriculumId,
            'version_ids' => $versionIds,
        ];
    }
}
