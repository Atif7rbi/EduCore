<?php

namespace App\Application\Exam;

use App\Application\Support\TransactionManager;
use App\Models\ExamGeneration;
use App\Models\ExamGenerationItem;
use App\Models\ExamTemplateVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BuildExamGeneration
{
    public function __construct(
        private readonly TransactionManager $transactions,
    ) {
    }

    /**
     * @param array<int, array{
     *     assessment_item_revision_id: string,
     *     assessment_item_id: string
     * }> $items
     */
    public function execute(
        string $examTemplateVersionId,
        string $generatorVersion,
        string $seed,
        array $items,
    ): ExamGeneration {
        return $this->transactions->run(
            function () use (
                $examTemplateVersionId,
                $generatorVersion,
                $seed,
                $items,
            ): ExamGeneration {
                $templateVersion = ExamTemplateVersion::query()
                    ->whereKey($examTemplateVersionId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $generation = ExamGeneration::query()->create([
                    'id' => (string) Str::uuid(),
                    'exam_template_version_id' => $templateVersion->id,
                    'curriculum_version_id' => $templateVersion->curriculum_version_id,
                    'rules_snapshot' => $templateVersion->rules_payload,
                    'rules_schema_version' => $templateVersion->rules_schema_version,
                    'generator_version' => $generatorVersion,
                    'seed' => $seed,
                    'generated_at' => null,
                ]);

                foreach (array_values($items) as $position => $item) {
                    ExamGenerationItem::query()->create([
                        'id' => (string) Str::uuid(),
                        'exam_generation_id' => $generation->id,
                        'assessment_item_revision_id' => $item['assessment_item_revision_id'],
                        'assessment_item_id' => $item['assessment_item_id'],
                        'curriculum_version_id' => $templateVersion->curriculum_version_id,
                        'selection_position' => $position,
                    ]);
                }

                DB::table('exam_generations')
                    ->where('id', $generation->id)
                    ->update([
                        'generated_at' => CarbonImmutable::now('UTC'),
                    ]);

                return $generation->refresh();
            }
        );
    }
}
