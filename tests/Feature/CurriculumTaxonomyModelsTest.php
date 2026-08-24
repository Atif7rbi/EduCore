<?php

namespace Tests\Feature;

use App\Models\Curriculum;
use App\Models\CurriculumVersion;
use App\Models\Skill;
use App\Models\SkillHomeTopic;
use App\Models\SkillLineage;
use App\Models\SkillVersionPlacement;
use App\Models\Subject;
use App\Models\Topic;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Tests\TestCase;

class CurriculumTaxonomyModelsTest extends TestCase
{
    public function test_domain_models_use_uuid_primary_keys(): void
    {
        $models = [
            new Subject(),
            new Curriculum(),
            new CurriculumVersion(),
            new Topic(),
            new Skill(),
            new SkillLineage(),
            new SkillVersionPlacement(),
            new SkillHomeTopic(),
        ];

        foreach ($models as $model) {
            $this->assertFalse($model->getIncrementing());
            $this->assertSame('string', $model->getKeyType());
        }
    }

    public function test_curriculum_relationship_mapping(): void
    {
        $subjectCurricula = (new Subject())->curricula();
        $this->assertInstanceOf(HasMany::class, $subjectCurricula);
        $this->assertSame('subject_id', $subjectCurricula->getForeignKeyName());

        $curriculumSubject = (new Curriculum())->subject();
        $this->assertInstanceOf(BelongsTo::class, $curriculumSubject);
        $this->assertSame('subject_id', $curriculumSubject->getForeignKeyName());

        $curriculumVersions = (new Curriculum())->versions();
        $this->assertInstanceOf(HasMany::class, $curriculumVersions);
        $this->assertSame('curriculum_id', $curriculumVersions->getForeignKeyName());

        $versionCurriculum = (new CurriculumVersion())->curriculum();
        $this->assertInstanceOf(BelongsTo::class, $versionCurriculum);
        $this->assertSame('curriculum_id', $versionCurriculum->getForeignKeyName());

        $versionTopics = (new CurriculumVersion())->topics();
        $this->assertInstanceOf(HasMany::class, $versionTopics);
        $this->assertSame('curriculum_version_id', $versionTopics->getForeignKeyName());
    }

    public function test_taxonomy_relationship_mapping(): void
    {
        $topicVersion = (new Topic())->curriculumVersion();
        $this->assertInstanceOf(BelongsTo::class, $topicVersion);
        $this->assertSame('curriculum_version_id', $topicVersion->getForeignKeyName());

        $skillPlacements = (new Skill())->placements();
        $this->assertInstanceOf(HasMany::class, $skillPlacements);
        $this->assertSame('skill_id', $skillPlacements->getForeignKeyName());

        $placementSkill = (new SkillVersionPlacement())->skill();
        $this->assertInstanceOf(BelongsTo::class, $placementSkill);
        $this->assertSame('skill_id', $placementSkill->getForeignKeyName());

        $placementVersion = (new SkillVersionPlacement())->curriculumVersion();
        $this->assertInstanceOf(BelongsTo::class, $placementVersion);
        $this->assertSame(
            'curriculum_version_id',
            $placementVersion->getForeignKeyName()
        );

        $placementHomeTopics = (new SkillVersionPlacement())->homeTopics();
        $this->assertInstanceOf(HasMany::class, $placementHomeTopics);
        $this->assertSame('placement_id', $placementHomeTopics->getForeignKeyName());

        $homeTopicPlacement = (new SkillHomeTopic())->placement();
        $this->assertInstanceOf(BelongsTo::class, $homeTopicPlacement);
        $this->assertSame('placement_id', $homeTopicPlacement->getForeignKeyName());

        $homeTopicTopic = (new SkillHomeTopic())->topic();
        $this->assertInstanceOf(BelongsTo::class, $homeTopicTopic);
        $this->assertSame('topic_id', $homeTopicTopic->getForeignKeyName());
    }

    public function test_skill_lineage_relationship_mapping(): void
    {
        $outgoing = (new Skill())->outgoingLineages();
        $this->assertInstanceOf(HasMany::class, $outgoing);
        $this->assertSame('source_skill_id', $outgoing->getForeignKeyName());

        $incoming = (new Skill())->incomingLineages();
        $this->assertInstanceOf(HasMany::class, $incoming);
        $this->assertSame('target_skill_id', $incoming->getForeignKeyName());

        $source = (new SkillLineage())->sourceSkill();
        $this->assertInstanceOf(BelongsTo::class, $source);
        $this->assertSame('source_skill_id', $source->getForeignKeyName());

        $target = (new SkillLineage())->targetSkill();
        $this->assertInstanceOf(BelongsTo::class, $target);
        $this->assertSame('target_skill_id', $target->getForeignKeyName());
    }

    public function test_immutable_join_models_do_not_expect_updated_at(): void
    {
        $this->assertNull((new SkillLineage())->getUpdatedAtColumn());
        $this->assertNull((new SkillVersionPlacement())->getUpdatedAtColumn());
        $this->assertNull((new SkillHomeTopic())->getUpdatedAtColumn());
    }
}
