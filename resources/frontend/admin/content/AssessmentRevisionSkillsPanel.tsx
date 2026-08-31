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
                <strong>
                    {children}
                </strong>

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
    role:
        AssessmentRevisionSkillRole,
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
    const queryClient =
        useQueryClient();

    const editable =
        version.status === 'draft'
        && revision.released_at === null;

    const [
        placementId,
        setPlacementId,
    ] = useState('');

    const [
        role,
        setRole,
    ] =
        useState<AssessmentRevisionSkillRole>(
            'primary',
        );

    const skillsQuery =
        useQuery({
            queryKey:
                adminAssessmentRevisionSkillsKey(
                    revision.id,
                ),
            queryFn: () =>
                fetchAssessmentRevisionSkills(
                    revision.id,
                ),
        });

    const placementsQuery =
        useQuery({
            queryKey:
                adminPlacementsKey(
                    version.id,
                ),
            queryFn: () =>
                fetchPlacements(
                    version.id,
                ),
        });

    const availablePlacements =
        useMemo(() => {
            const classified =
                new Set(
                    (
                        skillsQuery.data
                        ?? []
                    ).map(
                        (
                            classification,
                        ) =>
                            classification
                                .skill_version_placement_id,
                    ),
                );

            return (
                placementsQuery.data
                ?? []
            ).filter(
                (placement) =>
                    !classified.has(
                        placement.id,
                    ),
            );
        }, [
            placementsQuery.data,
            skillsQuery.data,
        ]);

    async function invalidate() {
        await queryClient
            .invalidateQueries({
                queryKey:
                    adminAssessmentRevisionSkillsKey(
                        revision.id,
                    ),
            });
    }

    const createMutation =
        useMutation({
            mutationFn: ({
                selectedPlacementId,
                selectedRole,
            }: {
                selectedPlacementId:
                    string;
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

    const deleteMutation =
        useMutation({
            mutationFn: (
                classificationId:
                    string,
            ) =>
                deleteAssessmentRevisionSkill(
                    revision.id,
                    classificationId,
                ),
            onSuccess: invalidate,
        });

    function submit(
        event: FormEvent,
    ) {
        event.preventDefault();

        if (
            !editable
            || createMutation.isPending
            || placementId === ''
        ) {
            return;
        }

        createMutation.mutate({
            selectedPlacementId:
                placementId,
            selectedRole:
                role,
        });
    }

    return (
        <Surface elevated>
            <div className="foundation-stack admin-content-panel admin-content-revision-skills">
                <div className="admin-content-revisions__heading">
                    <div>
                        <h4 className="foundation-card__title">
                            Skills — Revision{' '}
                            {
                                revision.revision_number
                            }
                        </h4>

                        <p className="foundation-page__description">
                            تصنيف المهارات إلى
                            primary أو supporting
                            باستخدام Skill
                            Placements من نفس
                            CurriculumVersion.
                        </p>
                    </div>

                    <Button
                        size="sm"
                        variant="secondary"
                        type="button"
                        onClick={
                            onClose
                        }
                    >
                        إغلاق
                    </Button>
                </div>

                {!editable ? (
                    <Feedback>
                        تصنيف المهارات للقراءة
                        فقط؛ CurriculumVersion
                        يجب أن تكون draft
                        والـRevision غير محررة.
                    </Feedback>
                ) : (
                    <form
                        className="admin-content-form"
                        onSubmit={
                            submit
                        }
                    >
                        <label>
                            Skill Placement

                            <select
                                aria-label="مهارة مراجعة عنصر التقييم"
                                required
                                value={
                                    placementId
                                }
                                onChange={(
                                    event,
                                ) =>
                                    setPlacementId(
                                        event
                                            .target
                                            .value,
                                    )
                                }
                            >
                                <option value="">
                                    اختر المهارة
                                </option>

                                {availablePlacements
                                    .map(
                                        (
                                            placement,
                                        ) => (
                                            <option
                                                key={
                                                    placement.id
                                                }
                                                value={
                                                    placement.id
                                                }
                                            >
                                                {
                                                    placement.skill
                                                        ?.name
                                                    ?? placement.skill_id
                                                }
                                            </option>
                                        ),
                                    )}
                            </select>
                        </label>

                        <label>
                            Role

                            <select
                                aria-label="دور مهارة عنصر التقييم"
                                value={
                                    role
                                }
                                onChange={(
                                    event,
                                ) => {
                                    const value =
                                        event
                                            .target
                                            .value;

                                    if (
                                        value
                                            === 'primary'
                                        || value
                                            === 'supporting'
                                    ) {
                                        setRole(
                                            value,
                                        );
                                    }
                                }}
                            >
                                <option value="primary">
                                    Primary
                                </option>

                                <option value="supporting">
                                    Supporting
                                </option>
                            </select>
                        </label>

                        <Button
                            type="submit"
                            disabled={
                                createMutation
                                    .isPending
                            }
                        >
                            إضافة التصنيف
                        </Button>
                    </form>
                )}

                {createMutation.isError ? (
                    <SkillFailure
                        error={
                            createMutation
                                .error
                        }
                    >
                        تعذر إضافة تصنيف المهارة.
                    </SkillFailure>
                ) : null}

                {deleteMutation.isError ? (
                    <SkillFailure
                        error={
                            deleteMutation
                                .error
                        }
                    >
                        تعذر إزالة تصنيف المهارة.
                    </SkillFailure>
                ) : null}

                {placementsQuery.isError ? (
                    <SkillFailure
                        error={
                            placementsQuery
                                .error
                        }
                    >
                        تعذر تحميل Skill Placements.
                    </SkillFailure>
                ) : null}

                {skillsQuery.isPending ? (
                    <p>
                        جار تحميل تصنيفات
                        المهارات…
                    </p>
                ) : skillsQuery.isError ? (
                    <SkillFailure
                        error={
                            skillsQuery.error
                        }
                    >
                        تعذر تحميل تصنيفات المهارات.
                    </SkillFailure>
                ) : skillsQuery.data
                    .length === 0 ? (
                    <Feedback>
                        لا توجد مهارات مصنفة
                        لهذه الـRevision.
                    </Feedback>
                ) : (
                    <div className="admin-content-list">
                        {skillsQuery.data.map(
                            (
                                classification,
                            ) => (
                                <article
                                    key={
                                        classification.id
                                    }
                                    className="admin-content-list__item"
                                >
                                    <div>
                                        <strong>
                                            {
                                                classification.skill
                                                    ?.name
                                                ?? classification
                                                    .skill_version_placement_id
                                            }
                                        </strong>

                                        <p className="admin-content-list__meta">
                                            الدور:{' '}
                                            {
                                                roleLabel(
                                                    classification.role,
                                                )
                                            }
                                        </p>
                                    </div>

                                    {editable ? (
                                        <Button
                                            size="sm"
                                            variant="secondary"
                                            type="button"
                                            disabled={
                                                deleteMutation
                                                    .isPending
                                            }
                                            onClick={() =>
                                                deleteMutation
                                                    .mutate(
                                                        classification.id,
                                                    )
                                            }
                                        >
                                            إزالة التصنيف
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
