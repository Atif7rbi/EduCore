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
    adminPlacementsKey,
    adminSkillsKey,
    adminTopicsKey,
    createHomeTopic,
    createPlacement,
    deleteHomeTopic,
    deletePlacement,
    fetchPlacements,
    fetchSkills,
    fetchTopics,
} from './api';

import type { CurriculumVersion } from './types';

interface SkillPlacementsPanelProps {
    version: CurriculumVersion;
}

function requestId(error: unknown) {
    return error instanceof EduCoreApiError
        ? error.requestId ?? null
        : null;
}

function PlacementFailure({
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
                {id ? <p className="learner-read-request-id">رقم الطلب: {id}</p> : null}
            </div>
        </Feedback>
    );
}

export function SkillPlacementsPanel({ version }: SkillPlacementsPanelProps) {
    const queryClient = useQueryClient();
    const editable = version.status === 'draft';
    const [selectedSkillId, setSelectedSkillId] = useState('');
    const [selectedHomeTopics, setSelectedHomeTopics] =
        useState<Record<string, string>>({});

    const placementsQuery = useQuery({
        queryKey: adminPlacementsKey(version.id),
        queryFn: () => fetchPlacements(version.id),
    });
    const skillsQuery = useQuery({
        queryKey: adminSkillsKey(),
        queryFn: fetchSkills,
    });
    const topicsQuery = useQuery({
        queryKey: adminTopicsKey(version.id),
        queryFn: () => fetchTopics(version.id),
    });

    const placedSkillIds = useMemo(
        () => new Set(placementsQuery.data?.map((placement) => placement.skill_id) ?? []),
        [placementsQuery.data],
    );

    const availableSkills = useMemo(
        () => skillsQuery.data?.filter((skill) => !placedSkillIds.has(skill.id)) ?? [],
        [placedSkillIds, skillsQuery.data],
    );

    async function invalidate() {
        await queryClient.invalidateQueries({
            queryKey: adminPlacementsKey(version.id),
        });
    }

    const createPlacementMutation = useMutation({
        mutationFn: () => createPlacement(version.id, selectedSkillId),
        onSuccess: async () => {
            setSelectedSkillId('');
            await invalidate();
        },
    });
    const deletePlacementMutation = useMutation({
        mutationFn: (placementId: string) => deletePlacement(placementId),
        onSuccess: invalidate,
    });
    const createHomeTopicMutation = useMutation({
        mutationFn: ({ placementId, topicId }: { placementId: string; topicId: string }) =>
            createHomeTopic(placementId, topicId),
        onSuccess: async (_data, variables) => {
            setSelectedHomeTopics((current) => ({
                ...current,
                [variables.placementId]: '',
            }));
            await invalidate();
        },
    });
    const deleteHomeTopicMutation = useMutation({
        mutationFn: ({ placementId, homeTopicId }: { placementId: string; homeTopicId: string }) =>
            deleteHomeTopic(placementId, homeTopicId),
        onSuccess: invalidate,
    });

    const mutationPending =
        createPlacementMutation.isPending
        || deletePlacementMutation.isPending
        || createHomeTopicMutation.isPending
        || deleteHomeTopicMutation.isPending;

    function submitPlacement(event: FormEvent) {
        event.preventDefault();
        if (!editable || selectedSkillId === '' || mutationPending) return;
        createPlacementMutation.mutate();
    }

    return (
        <Surface>
            <div className="foundation-stack admin-content-panel">
                <div>
                    <h2 className="foundation-card__title">مهارات هذا المنهج</h2>
                    <p className="foundation-page__description">
                        اختر المهارات المستخدمة في هذا الإصدار وحدد الموضوعات الرئيسية المرتبطة بكل مهارة.
                    </p>
                </div>

                {!editable ? (
                    <Feedback>
                        هذا الإصدار للقراءة فقط؛ لا يمكن تغيير روابط المهارات أو موضوعاتها الرئيسية.
                    </Feedback>
                ) : (
                    <form className="admin-content-form" onSubmit={submitPlacement}>
                        <label>
                            المهارة
                            <select
                                aria-label="المهارة المراد ربطها"
                                value={selectedSkillId}
                                required
                                onChange={(event) => setSelectedSkillId(event.target.value)}
                            >
                                <option value="">اختر المهارة</option>
                                {availableSkills.map((skill) => (
                                    <option key={skill.id} value={skill.id}>{skill.name}</option>
                                ))}
                            </select>
                        </label>
                        <Button
                            type="submit"
                            disabled={mutationPending || selectedSkillId === ''}
                        >
                            ربط المهارة بالمنهج
                        </Button>
                    </form>
                )}

                {createPlacementMutation.isError ? <PlacementFailure error={createPlacementMutation.error}>تعذر ربط المهارة.</PlacementFailure> : null}
                {deletePlacementMutation.isError ? <PlacementFailure error={deletePlacementMutation.error}>تعذر إزالة ربط المهارة.</PlacementFailure> : null}
                {createHomeTopicMutation.isError ? <PlacementFailure error={createHomeTopicMutation.error}>تعذر إضافة الموضوع الرئيسي.</PlacementFailure> : null}
                {deleteHomeTopicMutation.isError ? <PlacementFailure error={deleteHomeTopicMutation.error}>تعذر إزالة الموضوع الرئيسي.</PlacementFailure> : null}

                {placementsQuery.isPending || skillsQuery.isPending || topicsQuery.isPending ? (
                    <p>جار تحميل روابط المهارات…</p>
                ) : placementsQuery.isError ? (
                    <PlacementFailure error={placementsQuery.error}>تعذر تحميل مهارات المنهج.</PlacementFailure>
                ) : skillsQuery.isError ? (
                    <PlacementFailure error={skillsQuery.error}>تعذر تحميل مكتبة المهارات.</PlacementFailure>
                ) : topicsQuery.isError ? (
                    <PlacementFailure error={topicsQuery.error}>تعذر تحميل الموضوعات.</PlacementFailure>
                ) : placementsQuery.data.length === 0 ? (
                    <Feedback>لم تُربط مهارات بهذا الإصدار بعد.</Feedback>
                ) : (
                    <div className="admin-content-list">
                        {placementsQuery.data.map((placement) => {
                            const linkedTopicIds = new Set(
                                placement.home_topics.map((item) => item.topic_id),
                            );
                            const availableTopics = topicsQuery.data.filter(
                                (topic) => !linkedTopicIds.has(topic.id),
                            );

                            return (
                                <article
                                    key={placement.id}
                                    className="admin-content-list__item admin-content-placement"
                                >
                                    <div className="foundation-stack">
                                        <strong>{placement.skill?.name ?? 'مهارة'}</strong>

                                        {placement.home_topics.length === 0 ? (
                                            <p className="admin-content-list__meta">
                                                لم يُحدد موضوع رئيسي لهذه المهارة.
                                            </p>
                                        ) : (
                                            <div className="admin-content-tags">
                                                {placement.home_topics.map((homeTopic) => (
                                                    <span key={homeTopic.id} className="admin-content-tag">
                                                        {homeTopic.topic?.name ?? 'موضوع'}
                                                        {editable ? (
                                                            <button
                                                                type="button"
                                                                aria-label={`إزالة الموضوع الرئيسي ${homeTopic.topic?.name ?? ''}`}
                                                                disabled={mutationPending}
                                                                onClick={() => deleteHomeTopicMutation.mutate({
                                                                    placementId: placement.id,
                                                                    homeTopicId: homeTopic.id,
                                                                })}
                                                            >
                                                                ×
                                                            </button>
                                                        ) : null}
                                                    </span>
                                                ))}
                                            </div>
                                        )}

                                        {editable && availableTopics.length > 0 ? (
                                            <div className="admin-content-home-topic">
                                                <select
                                                    aria-label={`الموضوع الرئيسي للمهارة ${placement.skill?.name ?? ''}`}
                                                    value={selectedHomeTopics[placement.id] ?? ''}
                                                    disabled={mutationPending}
                                                    onChange={(event) => setSelectedHomeTopics((current) => ({
                                                        ...current,
                                                        [placement.id]: event.target.value,
                                                    }))}
                                                >
                                                    <option value="">اختر الموضوع الرئيسي</option>
                                                    {availableTopics.map((topic) => (
                                                        <option key={topic.id} value={topic.id}>{topic.name}</option>
                                                    ))}
                                                </select>
                                                <Button
                                                    size="sm"
                                                    type="button"
                                                    disabled={mutationPending || !selectedHomeTopics[placement.id]}
                                                    onClick={() => {
                                                        const topicId = selectedHomeTopics[placement.id];
                                                        if (!topicId) return;
                                                        createHomeTopicMutation.mutate({
                                                            placementId: placement.id,
                                                            topicId,
                                                        });
                                                    }}
                                                >
                                                    إضافة الموضوع الرئيسي
                                                </Button>
                                            </div>
                                        ) : null}
                                    </div>

                                    {editable ? (
                                        <Button
                                            size="sm"
                                            variant="secondary"
                                            disabled={mutationPending}
                                            onClick={() => deletePlacementMutation.mutate(placement.id)}
                                        >
                                            إزالة من المنهج
                                        </Button>
                                    ) : null}
                                </article>
                            );
                        })}
                    </div>
                )}
            </div>
        </Surface>
    );
}
