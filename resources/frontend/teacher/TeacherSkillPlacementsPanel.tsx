import {
    FormEvent,
    useEffect,
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
} from '../api/errors';
import {
    Button,
    Feedback,
    Surface,
} from '../ui';
import {
    createTeacherHomeTopic,
    createTeacherSkillPlacement,
    deleteTeacherHomeTopic,
    deleteTeacherSkillPlacement,
    fetchTeacherSkillPlacements,
    fetchTeacherSkills,
    fetchTeacherTopics,
    teacherSkillPlacementsKey,
    teacherSkillsKey,
    teacherTopicsKey,
} from './api';
import type {
    TeacherContext,
} from './TeacherContextSelector';
import type {
    TeacherSkillPlacement,
} from './types';

interface TeacherSkillPlacementsPanelProps {
    authenticatedUserId: string;
    context: TeacherContext;
    onContextUnavailable: () => void;
    onDirtyChange: (dirty: boolean) => void;
    onLifecycleConflict: () => Promise<void>;
}

function errorMessage(error: unknown): string {
    return error instanceof EduCoreApiError
        ? error.message
        : 'تعذر إكمال عملية المهارة.';
}

function isUnavailable(error: unknown): boolean {
    return error instanceof EduCoreApiError
        && (error.status === 403 || error.status === 404);
}

export function TeacherSkillPlacementsPanel({
    authenticatedUserId,
    context,
    onContextUnavailable,
    onDirtyChange,
    onLifecycleConflict,
}: TeacherSkillPlacementsPanelProps) {
    const queryClient = useQueryClient();
    const editable = context.versionStatus === 'draft';
    const [skillId, setSkillId] = useState('');
    const [homeTopicByPlacement, setHomeTopicByPlacement] =
        useState<Record<string, string>>({});

    const placementsKey = teacherSkillPlacementsKey(
        authenticatedUserId,
        context.assignmentId,
        context.curriculumId,
        context.curriculumVersionId,
    );
    const topicsKey = teacherTopicsKey(
        authenticatedUserId,
        context.assignmentId,
        context.curriculumId,
        context.curriculumVersionId,
    );
    const placementsQuery = useQuery({
        queryKey: placementsKey,
        queryFn: ({ signal }) => fetchTeacherSkillPlacements(
            context.assignmentId,
            context.curriculumId,
            context.curriculumVersionId,
            signal,
        ),
    });
    const topicsQuery = useQuery({
        queryKey: topicsKey,
        queryFn: ({ signal }) => fetchTeacherTopics(
            context.assignmentId,
            context.curriculumId,
            context.curriculumVersionId,
            signal,
        ),
    });
    const skillsQuery = useQuery({
        queryKey: teacherSkillsKey(authenticatedUserId),
        queryFn: ({ signal }) => fetchTeacherSkills(signal),
    });

    const placements = useMemo(
        () => (placementsQuery.data ?? []).filter(
            (placement) =>
                placement.curriculum_version_id
                === context.curriculumVersionId,
        ),
        [
            context.curriculumVersionId,
            placementsQuery.data,
        ],
    );
    const topics = useMemo(
        () => (topicsQuery.data ?? []).filter(
            (topic) =>
                topic.curriculum_version_id
                === context.curriculumVersionId,
        ),
        [
            context.curriculumVersionId,
            topicsQuery.data,
        ],
    );
    const validTopicIds = new Set(
        topics.map((topic) => topic.id),
    );
    const placedSkillIds = new Set(
        placements.map((placement) => placement.skill_id),
    );
    const availableSkills = (skillsQuery.data ?? []).filter(
        (skill) => !placedSkillIds.has(skill.id),
    );
    const dirty = skillId !== ''
        || Object.values(homeTopicByPlacement).some(
            (value) => value !== '',
        );

    useEffect(() => {
        onDirtyChange(dirty);

        return () => onDirtyChange(false);
    }, [
        dirty,
        onDirtyChange,
    ]);

    useEffect(() => {
        const errors = [
            placementsQuery.error,
            topicsQuery.error,
            skillsQuery.error,
        ];

        if (errors.some(isUnavailable)) {
            onContextUnavailable();
        }
    }, [
        onContextUnavailable,
        placementsQuery.error,
        skillsQuery.error,
        topicsQuery.error,
    ]);

    async function refresh() {
        await Promise.all([
            queryClient.invalidateQueries({
                queryKey: placementsKey,
            }),
            queryClient.invalidateQueries({
                queryKey: topicsKey,
            }),
        ]);
    }

    const createPlacement = useMutation({
        mutationFn: () => createTeacherSkillPlacement(
            context.assignmentId,
            context.curriculumId,
            context.curriculumVersionId,
            skillId,
        ),
        onSuccess: async () => {
            setSkillId('');
            await refresh();
        },
        onError: async (error) => {
            if (isUnavailable(error)) {
                onContextUnavailable();

                return;
            }

            if (
                error instanceof EduCoreApiError
                && error.status === 409
            ) {
                await onLifecycleConflict();
                await refresh();
            }
        },
    });
    const removePlacement = useMutation({
        mutationFn: (placementId: string) =>
            deleteTeacherSkillPlacement(
                context.assignmentId,
                context.curriculumId,
                context.curriculumVersionId,
                placementId,
            ),
        onSuccess: refresh,
        onError: async (error) => {
            if (isUnavailable(error)) {
                onContextUnavailable();

                return;
            }

            if (
                error instanceof EduCoreApiError
                && error.status === 409
            ) {
                await onLifecycleConflict();
                await refresh();
            }
        },
    });
    const addHomeTopic = useMutation({
        mutationFn: ({
            placementId,
            topicId,
        }: {
            placementId: string;
            topicId: string;
        }) => createTeacherHomeTopic(
            context.assignmentId,
            context.curriculumId,
            context.curriculumVersionId,
            placementId,
            topicId,
        ),
        onSuccess: async () => {
            setHomeTopicByPlacement({});
            await refresh();
        },
        onError: async (error) => {
            if (isUnavailable(error)) {
                onContextUnavailable();

                return;
            }

            if (
                error instanceof EduCoreApiError
                && error.status === 409
            ) {
                await onLifecycleConflict();
                await refresh();
            }
        },
    });
    const removeHomeTopic = useMutation({
        mutationFn: ({
            placementId,
            homeTopicId,
        }: {
            placementId: string;
            homeTopicId: string;
        }) => deleteTeacherHomeTopic(
            context.assignmentId,
            context.curriculumId,
            context.curriculumVersionId,
            placementId,
            homeTopicId,
        ),
        onSuccess: refresh,
        onError: async (error) => {
            if (isUnavailable(error)) {
                onContextUnavailable();

                return;
            }

            if (
                error instanceof EduCoreApiError
                && error.status === 409
            ) {
                await onLifecycleConflict();
                await refresh();
            }
        },
    });

    function submitPlacement(event: FormEvent) {
        event.preventDefault();

        if (editable && skillId !== '') {
            createPlacement.mutate();
        }
    }

    function availableTopics(
        placement: TeacherSkillPlacement,
    ) {
        const assignedTopicIds = new Set(
            placement.home_topics
                .filter(
                    (homeTopic) =>
                        homeTopic.curriculum_version_id
                        === context.curriculumVersionId
                        && homeTopic.placement_id
                        === placement.id
                        && validTopicIds.has(homeTopic.topic_id)
                        && homeTopic.topic?.id
                        === homeTopic.topic_id,
                )
                .map((homeTopic) => homeTopic.topic_id),
        );

        return topics.filter(
            (topic) => !assignedTopicIds.has(topic.id),
        );
    }

    return (
        <Surface className="foundation-card">
            <div className="foundation-stack">
                <div>
                    <h2 className="foundation-card__title">
                        المهارات والموضوعات الرئيسة
                    </h2>

                    <p className="foundation-page__description">
                        اربط مهارات الكتالوج بهذا الإصدار وحدد موضوعاتها الرئيسة.
                    </p>
                </div>

                {!editable ? (
                    <Feedback>
                        هذا الإصدار للقراءة فقط.
                    </Feedback>
                ) : (
                    <form
                        className="admin-content-form"
                        onSubmit={submitPlacement}
                    >
                        <label>
                            <span>مهارة</span>

                            <select
                                aria-label="إضافة مهارة"
                                onChange={(event) => {
                                    setSkillId(event.target.value);
                                }}
                                value={skillId}
                            >
                                <option value="">
                                    اختر مهارة
                                </option>

                                {availableSkills.map((skill) => (
                                    <option
                                        key={skill.id}
                                        value={skill.id}
                                    >
                                        {skill.name}
                                    </option>
                                ))}
                            </select>
                        </label>

                        {createPlacement.isError ? (
                            <Feedback tone="danger">
                                {errorMessage(
                                    createPlacement.error,
                                )}
                            </Feedback>
                        ) : null}

                        <Button
                            disabled={
                                createPlacement.isPending
                                || skillId === ''
                            }
                            type="submit"
                        >
                            إضافة مهارة
                        </Button>
                    </form>
                )}

                {placementsQuery.isPending
                    || topicsQuery.isPending
                    || skillsQuery.isPending ? (
                    <p>جار تحميل المهارات…</p>
                ) : null}

                {placementsQuery.isError ? (
                    <Feedback tone="danger">
                        {errorMessage(placementsQuery.error)}
                    </Feedback>
                ) : null}

                {topicsQuery.isError ? (
                    <Feedback tone="danger">
                        {errorMessage(topicsQuery.error)}
                    </Feedback>
                ) : null}

                {skillsQuery.isError ? (
                    <Feedback tone="danger">
                        {errorMessage(skillsQuery.error)}
                    </Feedback>
                ) : null}

                {placementsQuery.isSuccess
                    && placements.length === 0 ? (
                    <Feedback>
                        لا توجد مهارات مرتبطة بهذا الإصدار.
                    </Feedback>
                ) : null}

                {placements.map((placement) => {
                    const selectableTopics =
                        availableTopics(placement);
                    const selectedHomeTopic =
                        homeTopicByPlacement[placement.id] ?? '';

                    return (
                        <article
                            className="admin-content-list__item"
                            key={placement.id}
                        >
                            <div>
                                <strong>
                                    {placement.skill?.name
                                        ?? 'مهارة غير متاحة'}
                                </strong>

                                {placement.skill?.id
                                    !== placement.skill_id ? (
                                    <Feedback tone="warning">
                                        تم إخفاء مرجع مهارة غير متطابق.
                                    </Feedback>
                                ) : null}

                                <ul>
                                    {placement.home_topics
                                        .filter(
                                            (homeTopic) =>
                                                homeTopic.placement_id
                                                === placement.id
                                                && homeTopic.curriculum_version_id
                                                === context.curriculumVersionId
                                                && validTopicIds.has(
                                                    homeTopic.topic_id,
                                                )
                                                && homeTopic.topic?.id
                                                === homeTopic.topic_id,
                                        )
                                        .map((homeTopic) => (
                                            <li
                                                key={homeTopic.id}
                                            >
                                                {homeTopic.topic?.name
                                                    ?? 'موضوع غير متاح'}

                                                {editable ? (
                                                    <Button
                                                        onClick={() => {
                                                            if (
                                                                window.confirm(
                                                                    'هل تريد إزالة الموضوع الرئيس؟',
                                                                )
                                                            ) {
                                                                removeHomeTopic.mutate({
                                                                    placementId:
                                                                        placement.id,
                                                                    homeTopicId:
                                                                        homeTopic.id,
                                                                });
                                                            }
                                                        }}
                                                        size="sm"
                                                        type="button"
                                                        variant="secondary"
                                                    >
                                                        إزالة
                                                    </Button>
                                                ) : null}
                                            </li>
                                        ))}
                                </ul>
                            </div>

                            {editable ? (
                                <div className="foundation-stack">
                                    <label>
                                        <span>موضوع رئيس</span>

                                        <select
                                            aria-label={
                                                'موضوع رئيس للمهارة '
                                                + (
                                                    placement.skill?.name
                                                    ?? placement.id
                                                )
                                            }
                                            onChange={(event) => {
                                                setHomeTopicByPlacement(
                                                    (current) => ({
                                                        ...current,
                                                        [placement.id]:
                                                            event.target.value,
                                                    }),
                                                );
                                            }}
                                            value={selectedHomeTopic}
                                        >
                                            <option value="">
                                                اختر موضوعًا
                                            </option>

                                            {selectableTopics.map(
                                                (topic) => (
                                                    <option
                                                        key={topic.id}
                                                        value={topic.id}
                                                    >
                                                        {topic.name}
                                                    </option>
                                                ),
                                            )}
                                        </select>
                                    </label>

                                    <Button
                                        disabled={
                                            addHomeTopic.isPending
                                            || selectedHomeTopic === ''
                                        }
                                        onClick={() => {
                                            if (selectedHomeTopic !== '') {
                                                addHomeTopic.mutate({
                                                    placementId:
                                                        placement.id,
                                                    topicId:
                                                        selectedHomeTopic,
                                                });
                                            }
                                        }}
                                        size="sm"
                                        type="button"
                                    >
                                        إضافة موضوع رئيس
                                    </Button>

                                    <Button
                                        disabled={removePlacement.isPending}
                                        onClick={() => {
                                            if (
                                                window.confirm(
                                                    'هل تريد إزالة المهارة من الإصدار؟',
                                                )
                                            ) {
                                                removePlacement.mutate(
                                                    placement.id,
                                                );
                                            }
                                        }}
                                        size="sm"
                                        type="button"
                                        variant="secondary"
                                    >
                                        إزالة المهارة
                                    </Button>
                                </div>
                            ) : null}
                        </article>
                    );
                })}

                {removePlacement.isError
                    || addHomeTopic.isError
                    || removeHomeTopic.isError ? (
                    <Feedback tone="danger">
                        {errorMessage(
                            removePlacement.error
                            ?? addHomeTopic.error
                            ?? removeHomeTopic.error,
                        )}
                    </Feedback>
                ) : null}
            </div>
        </Surface>
    );
}
