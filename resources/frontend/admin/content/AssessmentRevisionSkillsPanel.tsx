import {
    FormEvent,
    useMemo,
    useState,
} from 'react';
import {
    useMutation,
    useQuery,
    useQueryClient,
} from '@tanstack/react-query';

import {
    EduCoreApiError,
} from '../../api/errors';
import {
    Button,
    Feedback,
    Surface,
} from '../../ui';

import {
    adminAssessmentRevisionSkillsKey,
    adminPlacementsKey,
    createAssessmentRevisionSkill,
    deleteAssessmentRevisionSkill,
    fetchAssessmentRevisionSkills,
    fetchPlacements,
} from './api';

import type {
    AssessmentItemRevision,
    AssessmentRevisionSkillRole,
    CurriculumVersion,
} from './types';

interface AssessmentRevisionSkillsPanelProps {
    version: CurriculumVersion;
    revision: AssessmentItemRevision;
    onClose: () => void;
}

function requestId(
    error: unknown,
): string | null {
    return error instanceof EduCoreApiError
        ? error.requestId ?? null
        : null;
}

function SkillFailure({
    children,
    error,
}: {
    children: string;
    error: unknown;
}) {
    const id = requestId(error);

    return (
        <Feedback tone="danger">
            <div>
                <strong>{children}</strong>

                {id ? (
                    <p className="learner-read-request-id">
                        رقم الطلب: {id}
                    </p>
                ) : null}
            </div>
        </Feedback>
    );
}

function roleLabel(
    role: AssessmentRevisionSkillRole,
) {
    return role === 'primary'
        ? 'أساسية'
        : 'مساندة';
}

export function AssessmentRevisionSkillsPanel({
    version,
    revision,
    onClose,
}: AssessmentRevisionSkillsPanelProps) {
    const queryClient = useQueryClient();

    const editable =
        version.status === 'draft'
        && revision.released_at === null;

    const [placementId, setPlacementId] =
        useState('');
    const [role, setRole] =
        useState<AssessmentRevisionSkillRole>(
            'primary',
        );

    const skillsQuery = useQuery({
        queryKey:
            adminAssessmentRevisionSkillsKey(
                revision.id,
            ),
        queryFn: () =>
            fetchAssessmentRevisionSkills(
                revision.id,
            ),
    });

    const placementsQuery = useQuery({
        queryKey: adminPlacementsKey(
            version.id,
        ),
        queryFn: () =>
            fetchPlacements(version.id),
    });

    const availablePlacements = useMemo(() => {
        const linked = new Set(
            (skillsQuery.data ?? []).map(
                (classification) =>
                    classification
                        .skill_version_placement_id,
            ),
        );

        return (placementsQuery.data ?? [])
            .filter(
                (placement) =>
                    !linked.has(placement.id),
            );
    }, [
        placementsQuery.data,
        skillsQuery.data,
    ]);

    async function invalidate() {
        await queryClient.invalidateQueries({
            queryKey:
                adminAssessmentRevisionSkillsKey(
                    revision.id,
                ),
        });
    }

    const createMutation = useMutation({
        mutationFn: ({
            selectedPlacementId,
            selectedRole,
        }: {
            selectedPlacementId: string;
            selectedRole:
                AssessmentRevisionSkillRole;
        }) =>
            createAssessmentRevisionSkill(
                revision.id,
                selectedPlacementId,
                selectedRole,
            ),
        onSuccess: async () => {
            setPlacementId('');
            setRole('primary');
            await invalidate();
        },
    });

    const deleteMutation = useMutation({
        mutationFn: (
            classificationId: string,
        ) =>
            deleteAssessmentRevisionSkill(
                revision.id,
                classificationId,
            ),
        onSuccess: invalidate,
    });

    function submit(event: FormEvent) {
        event.preventDefault();

        if (
            !editable
            || createMutation.isPending
            || placementId === ''
        ) {
            return;
        }

        createMutation.mutate({
            selectedPlacementId: placementId,
            selectedRole: role,
        });
    }

    return (
        <Surface elevated>
            <div className="foundation-stack admin-content-panel admin-content-revision-skills">
                <div className="admin-content-revisions__heading">
                    <div>
                        <h4 className="foundation-card__title">
                            ربط المهارات بالسؤال
                        </h4>

                        <p className="foundation-page__description">
                            حدد المهارة التي يقيسها السؤال، وبيّن إن كانت أساسية أو مساندة.
                        </p>
                    </div>

                    <Button
                        size="sm"
                        variant="secondary"
                        type="button"
                        onClick={onClose}
                    >
                        إغلاق
                    </Button>
                </div>

                {!editable ? (
                    <Feedback>
                        روابط المهارات للقراءة فقط بعد اعتماد محتوى السؤال أو إغلاق المنهج للتعديل.
                    </Feedback>
                ) : (
                    <form
                        className="admin-content-form"
                        onSubmit={submit}
                    >
                        <label>
                            المهارة

                            <select
                                aria-label="المهارة المرتبطة بالسؤال"
                                required
                                value={placementId}
                                onChange={(event) =>
                                    setPlacementId(
                                        event.target.value,
                                    )
                                }
                            >
                                <option value="">
                                    اختر المهارة
                                </option>

                                {availablePlacements.map(
                                    (placement) => (
                                        <option
                                            key={placement.id}
                                            value={placement.id}
                                        >
                                            {placement.skill
                                                ?.name
                                                ?? 'مهارة'}
                                        </option>
                                    ),
                                )}
                            </select>
                        </label>

                        <label>
                            أهمية المهارة في السؤال

                            <select
                                aria-label="أهمية المهارة في السؤال"
                                value={role}
                                onChange={(event) => {
                                    const value =
                                        event.target.value;

                                    if (
                                        value === 'primary'
                                        || value === 'supporting'
                                    ) {
                                        setRole(value);
                                    }
                                }}
                            >
                                <option value="primary">
                                    أساسية
                                </option>
                                <option value="supporting">
                                    مساندة
                                </option>
                            </select>
                        </label>

                        <Button
                            type="submit"
                            disabled={
                                createMutation.isPending
                            }
                        >
                            ربط المهارة
                        </Button>
                    </form>
                )}

                {createMutation.isError ? (
                    <SkillFailure
                        error={createMutation.error}
                    >
                        تعذر ربط المهارة.
                    </SkillFailure>
                ) : null}

                {deleteMutation.isError ? (
                    <SkillFailure
                        error={deleteMutation.error}
                    >
                        تعذر إزالة المهارة.
                    </SkillFailure>
                ) : null}

                {placementsQuery.isError ? (
                    <SkillFailure
                        error={placementsQuery.error}
                    >
                        تعذر تحميل المهارات.
                    </SkillFailure>
                ) : null}

                {skillsQuery.isPending ? (
                    <p>جار تحميل المهارات…</p>
                ) : skillsQuery.isError ? (
                    <SkillFailure
                        error={skillsQuery.error}
                    >
                        تعذر تحميل المهارات المرتبطة.
                    </SkillFailure>
                ) : skillsQuery.data.length === 0 ? (
                    <Feedback>
                        لم يتم ربط مهارات بهذا السؤال حتى الآن.
                    </Feedback>
                ) : (
                    <div className="admin-content-list">
                        {skillsQuery.data.map(
                            (classification) => (
                                <article
                                    key={classification.id}
                                    className="admin-content-list__item"
                                >
                                    <div>
                                        <strong>
                                            {classification.skill
                                                ?.name
                                                ?? 'مهارة'}
                                        </strong>

                                        <p className="admin-content-list__meta">
                                            {roleLabel(
                                                classification.role,
                                            )}
                                        </p>
                                    </div>

                                    {editable ? (
                                        <Button
                                            size="sm"
                                            variant="secondary"
                                            type="button"
                                            disabled={
                                                deleteMutation.isPending
                                            }
                                            onClick={() =>
                                                deleteMutation.mutate(
                                                    classification.id,
                                                )
                                            }
                                        >
                                            إزالة الربط
                                        </Button>
                                    ) : null}
                                </article>
                            ),
                        )}
                    </div>
                )}
            </div>
        </Surface>
    );
}
