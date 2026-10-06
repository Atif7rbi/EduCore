<?php

namespace App\Http\Controllers\Api\Teacher;

use App\Application\Exam\BuildExamGeneration;
use App\Application\Exceptions\TeacherAuthoringConflict;
use App\Application\TeacherAuthoring\ManageTeacherPracticeExamAuthoring;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\ExamGeneration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeacherPracticeExamAuthoringController extends Controller
{
    public function __construct(private readonly ManageTeacherPracticeExamAuthoring $authoring, private readonly BuildExamGeneration $generations) {}

    public function storePractice(Request $r, string $assignment, string $curriculum, string $version): JsonResponse
    {
        $x = $r->validate(['lesson_id' => ['nullable', 'uuid'], 'name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string']]);

        return $this->mutate(fn () => $this->authoring->createPractice($r->user()->id, $assignment, $curriculum, $version, $x['lesson_id'] ?? null, $x['name'], $x['description'] ?? null), fn ($p) => $this->practice($p), 201);
    }

    public function updatePractice(Request $r, string $assignment, string $curriculum, string $version, string $practice): JsonResponse
    {
        $x = $r->validate(['lesson_id' => ['nullable', 'uuid'], 'name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string']]);

        return $this->mutate(fn () => $this->authoring->updatePractice($r->user()->id, $assignment, $curriculum, $version, $practice, $x['lesson_id'] ?? null, $x['name'], $x['description'] ?? null), fn ($p) => $this->practice($p));
    }

    public function activatePractice(Request $r, string $assignment, string $curriculum, string $version, string $practice): JsonResponse
    {
        return $this->mutate(fn () => $this->authoring->setPracticeStatus($r->user()->id, $assignment, $curriculum, $version, $practice, 'active'), fn ($p) => $this->practice($p));
    }

    public function archivePractice(Request $r, string $assignment, string $curriculum, string $version, string $practice): JsonResponse
    {
        return $this->mutate(fn () => $this->authoring->setPracticeStatus($r->user()->id, $assignment, $curriculum, $version, $practice, 'archived'), fn ($p) => $this->practice($p));
    }

    public function addPracticeItems(Request $r, string $assignment, string $curriculum, string $version, string $practice): JsonResponse
    {
        $x = $r->validate(['assessment_item_revision_ids' => ['required', 'array', 'min:1'], 'assessment_item_revision_ids.*' => ['required', 'uuid'], 'display_order' => ['required', 'integer', 'min:0']]);

        return $this->mutate(fn () => $this->authoring->addPracticeItems($r->user()->id, $assignment, $curriculum, $version, $practice, $x['assessment_item_revision_ids'], $x['display_order']), fn ($items) => array_map(fn ($i) => $this->practiceItem($i), $items), 201);
    }

    public function removePracticeItem(Request $r, string $assignment, string $curriculum, string $version, string $practice, string $membership): JsonResponse
    {
        return $this->mutate(fn () => tap(true, fn () => $this->authoring->removePracticeItem($r->user()->id, $assignment, $curriculum, $version, $practice, $membership)), fn () => ['deleted' => true]);
    }

    public function storeTemplate(Request $r, string $assignment, string $curriculum, string $version): JsonResponse
    {
        $x = $r->validate(['name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string']]);

        return $this->mutate(fn () => $this->authoring->createTemplate($r->user()->id, $assignment, $curriculum, $version, $x['name'], $x['description'] ?? null), fn ($t) => $this->template($t), 201);
    }

    public function updateTemplate(Request $r, string $assignment, string $curriculum, string $version, string $template): JsonResponse
    {
        $x = $r->validate(['name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string']]);

        return $this->mutate(fn () => $this->authoring->updateTemplate($r->user()->id, $assignment, $curriculum, $version, $template, $x['name'], $x['description'] ?? null), fn ($t) => $this->template($t));
    }

    public function activateTemplate(Request $r, string $assignment, string $curriculum, string $version, string $template): JsonResponse
    {
        return $this->mutate(fn () => $this->authoring->setTemplateStatus($r->user()->id, $assignment, $curriculum, $version, $template, 'active'), fn ($t) => $this->template($t));
    }

    public function archiveTemplate(Request $r, string $assignment, string $curriculum, string $version, string $template): JsonResponse
    {
        return $this->mutate(fn () => $this->authoring->setTemplateStatus($r->user()->id, $assignment, $curriculum, $version, $template, 'archived'), fn ($t) => $this->template($t));
    }

    public function storeTemplateVersion(Request $r, string $assignment, string $curriculum, string $version, string $template): JsonResponse
    {
        $x = $this->templateVersionInput($r, true);

        return $this->mutate(fn () => $this->authoring->createTemplateVersion($r->user()->id, $assignment, $curriculum, $version, $template, $x['version_number'], $x['label'] ?? null, $x['rules_payload'], $x['rules_schema_version']), fn ($v) => $this->templateVersion($v), 201);
    }

    public function updateTemplateVersion(Request $r, string $assignment, string $curriculum, string $version, string $template, string $templateVersion): JsonResponse
    {
        $x = $this->templateVersionInput($r, false);

        return $this->mutate(fn () => $this->authoring->updateTemplateVersion($r->user()->id, $assignment, $curriculum, $version, $template, $templateVersion, $x['label'] ?? null, $x['rules_payload'], $x['rules_schema_version']), fn ($v) => $this->templateVersion($v));
    }

    public function publishTemplateVersion(Request $r, string $assignment, string $curriculum, string $version, string $template, string $templateVersion): JsonResponse
    {
        return $this->mutate(fn () => $this->authoring->publishTemplateVersion($r->user()->id, $assignment, $curriculum, $version, $template, $templateVersion), fn ($v) => $this->templateVersion($v));
    }

    public function retireTemplateVersion(Request $r, string $assignment, string $curriculum, string $version, string $template, string $templateVersion): JsonResponse
    {
        return $this->mutate(fn () => $this->authoring->retireTemplateVersion($r->user()->id, $assignment, $curriculum, $version, $template, $templateVersion), fn ($v) => $this->templateVersion($v));
    }

    public function buildGeneration(Request $r, string $assignment, string $curriculum, string $version, string $template, string $templateVersion): JsonResponse
    {
        $x = $r->validate(['generator_version' => ['required', 'string', 'max:255'], 'seed' => ['required', 'string', 'max:255'], 'assessment_item_revision_ids' => ['required', 'array', 'min:1'], 'assessment_item_revision_ids.*' => ['required', 'uuid']]);

        return $this->mutate(function () use ($r, $assignment, $curriculum, $version, $template, $templateVersion, $x): ExamGeneration {
            $items = $this->authoring->generationItems($r->user()->id, $assignment, $curriculum, $version, $template, $templateVersion, $x['assessment_item_revision_ids']);

            return $this->generations->execute($templateVersion, $x['generator_version'], $x['seed'], $items, fn (): string => $this->authoring->lockGenerationAuthority($r->user()->id, $assignment, $curriculum, $version, $template, $templateVersion, $x['assessment_item_revision_ids']));
        }, fn ($g) => $this->generation($g), 201);
    }

    private function templateVersionInput(Request $r, bool $create): array
    {
        return $r->validate(array_merge($create ? ['version_number' => ['required', 'integer', 'min:1']] : [], ['label' => ['nullable', 'string', 'max:255'], 'rules_payload' => ['present', 'array'], 'rules_schema_version' => ['required', 'integer', 'min:1']]));
    }

    private function mutate(\Closure $f, \Closure $out, int $status = 200): JsonResponse
    {
        try {
            return ApiResponse::success($out($f()), $status);
        } catch (TeacherAuthoringConflict $e) {
            return ApiResponse::error($e->errorCode, $e->getMessage(), 409);
        }
    }

    private function practice($p): array
    {
        return ['id' => $p->id, 'curriculum_version_id' => $p->curriculum_version_id, 'lesson_id' => $p->lesson_id, 'name' => $p->name, 'description' => $p->description, 'status' => $p->status];
    }

    private function practiceItem($i): array
    {
        return ['id' => $i->id, 'practice_activity_id' => $i->practice_activity_id, 'assessment_item_revision_id' => $i->assessment_item_revision_id, 'assessment_item_id' => $i->assessment_item_id, 'curriculum_version_id' => $i->curriculum_version_id, 'display_order' => $i->display_order];
    }

    private function template($t): array
    {
        return ['id' => $t->id, 'curriculum_version_id' => $t->curriculum_version_id, 'name' => $t->name, 'description' => $t->description, 'status' => $t->status, 'published_version_id' => $t->published_version_id];
    }

    private function templateVersion($v): array
    {
        return ['id' => $v->id, 'exam_template_id' => $v->exam_template_id, 'curriculum_version_id' => $v->curriculum_version_id, 'version_number' => $v->version_number, 'label' => $v->label, 'status' => $v->status, 'rules_payload' => $v->rules_payload, 'rules_schema_version' => $v->rules_schema_version];
    }

    private function generation($g): array
    {
        $g->load('items');

        return ['id' => $g->id, 'exam_template_version_id' => $g->exam_template_version_id, 'curriculum_version_id' => $g->curriculum_version_id, 'generated_at' => $g->generated_at?->toISOString(), 'items' => $g->items->map(fn ($i) => ['assessment_item_revision_id' => $i->assessment_item_revision_id, 'assessment_item_id' => $i->assessment_item_id])->values()];
    }
}
