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
    adminRevisionSkillsKey,
    createRevisionSkill,
    deleteRevisionSkill,
    fetchPlacements,
    fetchRevisionSkills,
} from './api';

import type {
    CurriculumVersion,
    LessonRevision,
} from './types';

interface RevisionSkillsPanelProps {
    version: CurriculumVersion;
    revision: LessonRevision;
    onClose: () => void;
}

function requestId(error: unknown) {
    return error instanceof EduCoreApiError
        ? error.requestId ?? null
        : null;
}

function ClassificationFailure({
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

export function RevisionSkillsPanel({
    version,
    revision,
    onClose,
}: RevisionSkillsPanelProps) {
    const queryClient = useQueryClient();
    const editable =
        version.status === 'draft'
        && revision.released_at === null;

    const [selectedPlacementId, setSelectedPlacementId] =
        useState('');

    const classificationsQuery = useQuery({
        queryKey: adminRevisionSkillsKey(revision.id),
        queryFn: () => fetchRevisionSkills(revision.id),
    });

    const placementsQuery = useQuery({
        queryKey: adminPlacementsKey(version.id),
        queryFn: () => fetchPlacements(version.id),
    });

    const classifiedPlacementIds = useMemo(
        () =>
            new Set(
                classificationsQuery.data?.map(
                    (classification) =>
                        classification.skill_version_placement_id,
                ) ?? [],
            ),
        [classificationsQuery.data],
    );

    const availablePlacements = useMemo(
        () =>
            placementsQuery.data?.filter(
                (placement) =>
                    !classifiedPlacementIds.has(placement.id),
            ) ?? [],
        [classifiedPlacementIds, placementsQuery.data],
    );

    async function invalidate() {
        await queryClient.invalidateQueries({
            queryKey: adminRevisionSkillsKey(revision.id),
        });
    }

    const createMutation = useMutation({
        mutationFn: () =>
            createRevisionSkill(revision.id, selectedPlacementId),
        onSuccess: async () => {
            setSelectedPlacementId('');
            await invalidate();
        },
    });

    const deleteMutation = useMutation({
        mutationFn: (classificationId: string) =>
            deleteRevisionSkill(revision.id, classificationId),
        onSuccess: invalidate,
    });

    const pending =
        createMutation.isPending || deleteMutation.isPending;

    function submit(event: FormEvent) {
        event.preventDefault();

        if (!editable || pending || selectedPlacementId === '') {
            return;
        }

        createMutation.mutate();
    }

    return (
        <Surface className="admin-content-revision-skills" elevated>
            <div className="foundation-stack admin-content-panel">
                <div className="admin-content-revisions__heading">
                    <div>
                        <h3 className="foundation-card__title">
                            مهارات النسخة {revision.revision_number}
                        </h3>
                        <p className="foundation-page__description">
                            حدد المهارات التي يغطيها محتوى هذه النسخة من الدرس.
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
                        هذه النسخة معتمدة؛ روابط المهارات أصبحت للقراءة فقط.
                    </Feedback>
                ) : (
                    <form className="admin-content-form" onSubmit={submit}>
                        <label>
                            المهارة
                            <select
                                aria-label="المهارة المراد ربطها بالنسخة"
                                required
                                value={selectedPlacementId}
                                disabled={pending}
                                onChange={(event) =>
                                    setSelectedPlacementId(event.target.value)
                                }
                            >
                                <option value="">اختر المهارة</option>
                                {availablePlacements.map((placement) => (
                                    <option key={placement.id} value={placement.id}>
                                        {placement.skill?.name ?? 'مهارة'}
                                    </option>
                                ))}
                            </select>
                        </label>

                        <Button
                            type="submit"
                            disabled={pending || selectedPlacementId === ''}
                        >
                            ربط المهارة
                        </Button>
                    </form>
                )}

                {createMutation.isError ? (
                    <ClassificationFailure error={createMutation.error}>
                        تعذر ربط المهارة.
                    </ClassificationFailure>
                ) : null}

                {deleteMutation.isError ? (
                    <ClassificationFailure error={deleteMutation.error}>
                        تعذر إزالة المهارة.
                    </ClassificationFailure>
                ) : null}

                {classificationsQuery.isPending || placementsQuery.isPending ? (
                    <p>جار تحميل المهارات…</p>
                ) : classificationsQuery.isError ? (
                    <ClassificationFailure error={classificationsQuery.error}>
                        تعذر تحميل مهارات النسخة.
                    </ClassificationFailure>
                ) : placementsQuery.isError ? (
                    <ClassificationFailure error={placementsQuery.error}>
                        تعذر تحميل مهارات المنهج.
                    </ClassificationFailure>
                ) : classificationsQuery.data.length === 0 ? (
                    <Feedback>
                        لم تُربط مهارات بهذه النسخة بعد.
                    </Feedback>
                ) : (
                    <div className="admin-content-list">
                        {classificationsQuery.data.map((classification) => (
                            <article
                                key={classification.id}
                                className="admin-content-list__item"
                            >
                                <strong>
                                    {classification.skill?.name ?? 'مهارة'}
                                </strong>

                                {editable ? (
                                    <Button
                                        size="sm"
                                        variant="secondary"
                                        type="button"
                                        disabled={pending}
                                        onClick={() =>
                                            deleteMutation.mutate(classification.id)
                                        }
                                    >
                                        إزالة الربط
                                    </Button>
                                ) : null}
                            </article>
                        ))}
                    </div>
                )}
            </div>
        </Surface>
    );
}
