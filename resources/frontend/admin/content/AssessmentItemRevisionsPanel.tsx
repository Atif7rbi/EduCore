import {
    FormEvent,
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
    adminAssessmentItemRevisionsKey,
    adminTopicsKey,
    createAssessmentItemRevision,
    fetchAssessmentItemRevisions,
    fetchTopics,
} from './api';

import {
    AssessmentRevisionSkillsPanel,
} from './AssessmentRevisionSkillsPanel';

import type {
    AssessmentDifficulty,
    AssessmentItem,
    AssessmentItemRevision,
    CurriculumVersion,
} from './types';

interface AssessmentItemRevisionsPanelProps {
    version: CurriculumVersion;
    item: AssessmentItem;
    onClose: () => void;
}

function requestId(
    error: unknown,
): string | null {
    return error instanceof EduCoreApiError
        ? error.requestId ?? null
        : null;
}

function RevisionFailure({
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

function difficultyLabel(
    difficulty:
        AssessmentDifficulty,
) {
    switch (difficulty) {
        case 'easy':
            return 'سهل';
        case 'medium':
            return 'متوسط';
        case 'hard':
            return 'صعب';
    }
}

function parseJsonPayload(
    value: string,
):
    | unknown[]
    | Record<string, unknown>
    | null {
    let parsed: unknown;

    try {
        parsed = JSON.parse(
            value,
        );
    } catch {
        return null;
    }

    if (
        parsed === null
        || typeof parsed
            !== 'object'
    ) {
        return null;
    }

    return parsed as
        | unknown[]
        | Record<string, unknown>;
}

export function AssessmentItemRevisionsPanel({
    version,
    item,
    onClose,
}: AssessmentItemRevisionsPanelProps) {
    const queryClient =
        useQueryClient();

    const editable =
        version.status === 'draft'
        && item.status === 'draft';

    const [
        revisionNumber,
        setRevisionNumber,
    ] = useState('');

    const [
        primaryTopicId,
        setPrimaryTopicId,
    ] = useState('');

    const [
        difficulty,
        setDifficulty,
    ] =
        useState<AssessmentDifficulty>(
            'medium',
        );

    const [
        contentJson,
        setContentJson,
    ] = useState(
        JSON.stringify(
            [],
            null,
            2,
        ),
    );

    const [
        contentSchemaVersion,
        setContentSchemaVersion,
    ] = useState('1');

    const [
        scoringJson,
        setScoringJson,
    ] = useState(
        JSON.stringify(
            [],
            null,
            2,
        ),
    );

    const [
        scoringSchemaVersion,
        setScoringSchemaVersion,
    ] = useState('1');

    const [
        payloadError,
        setPayloadError,
    ] = useState<string | null>(
        null,
    );

    const [
        classifyingRevision,
        setClassifyingRevision,
    ] = useState<AssessmentItemRevision | null>(
        null,
    );

    const revisionsQuery =
        useQuery({
            queryKey:
                adminAssessmentItemRevisionsKey(
                    item.id,
                ),
            queryFn: () =>
                fetchAssessmentItemRevisions(
                    item.id,
                ),
        });

    const topicsQuery =
        useQuery({
            queryKey:
                adminTopicsKey(
                    version.id,
                ),
            queryFn: () =>
                fetchTopics(
                    version.id,
                ),
        });

    async function invalidate() {
        await queryClient
            .invalidateQueries({
                queryKey:
                    adminAssessmentItemRevisionsKey(
                        item.id,
                    ),
            });
    }

    const createMutation =
        useMutation({
            mutationFn: ({
                revisionNumber,
                topicId,
                selectedDifficulty,
                contentPayload,
                contentSchema,
                scoringPayload,
                scoringSchema,
            }: {
                revisionNumber:
                    number;
                topicId:
                    string | null;
                selectedDifficulty:
                    AssessmentDifficulty;
                contentPayload:
                    | unknown[]
                    | Record<
                        string,
                        unknown
                    >;
                contentSchema:
                    number;
                scoringPayload:
                    | unknown[]
                    | Record<
                        string,
                        unknown
                    >;
                scoringSchema:
                    number;
            }) =>
                createAssessmentItemRevision(
                    item.id,
                    {
                        revision_number:
                            revisionNumber,
                        primary_topic_id:
                            topicId,
                        difficulty:
                            selectedDifficulty,
                        content_payload:
                            contentPayload,
                        content_schema_version:
                            contentSchema,
                        scoring_payload:
                            scoringPayload,
                        scoring_schema_version:
                            scoringSchema,
                    },
                ),
            onSuccess: async () => {
                setRevisionNumber('');
                setPrimaryTopicId('');
                setDifficulty(
                    'medium',
                );
                setContentJson(
                    JSON.stringify(
                        [],
                        null,
                        2,
                    ),
                );
                setContentSchemaVersion(
                    '1',
                );
                setScoringJson(
                    JSON.stringify(
                        [],
                        null,
                        2,
                    ),
                );
                setScoringSchemaVersion(
                    '1',
                );
                setPayloadError(null);

                await invalidate();
            },
        });

    function submit(
        event: FormEvent,
    ) {
        event.preventDefault();

        if (
            !editable
            || createMutation.isPending
        ) {
            return;
        }

        const parsedRevision =
            Number(
                revisionNumber,
            );

        const parsedContentSchema =
            Number(
                contentSchemaVersion,
            );

        const parsedScoringSchema =
            Number(
                scoringSchemaVersion,
            );

        if (
            !Number.isInteger(
                parsedRevision,
            )
            || parsedRevision < 1
            || !Number.isInteger(
                parsedContentSchema,
            )
            || parsedContentSchema < 1
            || !Number.isInteger(
                parsedScoringSchema,
            )
            || parsedScoringSchema < 1
        ) {
            return;
        }

        const contentPayload =
            parseJsonPayload(
                contentJson,
            );

        if (!contentPayload) {
            setPayloadError(
                'Content Payload يجب أن يكون JSON array أو object.',
            );
            return;
        }

        const scoringPayload =
            parseJsonPayload(
                scoringJson,
            );

        if (!scoringPayload) {
            setPayloadError(
                'Scoring Payload يجب أن يكون JSON array أو object.',
            );
            return;
        }

        setPayloadError(null);

        createMutation.mutate({
            revisionNumber:
                parsedRevision,
            topicId:
                primaryTopicId
                || null,
            selectedDifficulty:
                difficulty,
            contentPayload,
            contentSchema:
                parsedContentSchema,
            scoringPayload,
            scoringSchema:
                parsedScoringSchema,
        });
    }

    return (
        <Surface elevated>
            <div className="foundation-stack admin-content-panel">
                <div className="admin-content-revisions__heading">
                    <div>
                        <h3 className="foundation-card__title">
                            Revisions: {
                                item.internal_label
                                ?? item.item_type
                            }
                        </h3>

                        <p className="foundation-page__description">
                            إنشاء revisions
                            لمحتوى عنصر التقييم
                            وبيانات التصحيح دون
                            فرض schema إضافي.
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

                {editable ? (
                    <form
                        className="admin-content-form"
                        onSubmit={
                            submit
                        }
                    >
                        <label>
                            Revision Number

                            <input
                                aria-label="رقم مراجعة عنصر التقييم"
                                type="number"
                                min="1"
                                step="1"
                                required
                                value={
                                    revisionNumber
                                }
                                onChange={(
                                    event,
                                ) =>
                                    setRevisionNumber(
                                        event
                                            .target
                                            .value,
                                    )
                                }
                            />
                        </label>

                        <label>
                            Primary Topic

                            <select
                                aria-label="الموضوع الرئيسي لعنصر التقييم"
                                value={
                                    primaryTopicId
                                }
                                onChange={(
                                    event,
                                ) =>
                                    setPrimaryTopicId(
                                        event
                                            .target
                                            .value,
                                    )
                                }
                            >
                                <option value="">
                                    بدون Topic
                                </option>

                                {topicsQuery.data
                                    ?.map(
                                        (
                                            topic,
                                        ) => (
                                            <option
                                                key={
                                                    topic.id
                                                }
                                                value={
                                                    topic.id
                                                }
                                            >
                                                {
                                                    topic.name
                                                }
                                            </option>
                                        ),
                                    )}
                            </select>
                        </label>

                        <label>
                            Difficulty

                            <select
                                aria-label="صعوبة عنصر التقييم"
                                value={
                                    difficulty
                                }
                                onChange={(
                                    event,
                                ) => {
                                    const value =
                                        event.target.value;

                                    if (
                                        value === 'easy'
                                        || value === 'medium'
                                        || value === 'hard'
                                    ) {
                                        setDifficulty(
                                            value,
                                        );
                                    }
                                }}
                            >
                                <option value="easy">
                                    سهل
                                </option>
                                <option value="medium">
                                    متوسط
                                </option>
                                <option value="hard">
                                    صعب
                                </option>
                            </select>
                        </label>

                        <label>
                            Content Schema Version

                            <input
                                aria-label="إصدار مخطط محتوى عنصر التقييم"
                                type="number"
                                min="1"
                                step="1"
                                required
                                value={
                                    contentSchemaVersion
                                }
                                onChange={(
                                    event,
                                ) =>
                                    setContentSchemaVersion(
                                        event
                                            .target
                                            .value,
                                    )
                                }
                            />
                        </label>

                        <label>
                            Content Payload

                            <textarea
                                aria-label="محتوى عنصر التقييم"
                                rows={8}
                                value={
                                    contentJson
                                }
                                onChange={(
                                    event,
                                ) =>
                                    setContentJson(
                                        event
                                            .target
                                            .value,
                                    )
                                }
                            />
                        </label>

                        <label>
                            Scoring Schema Version

                            <input
                                aria-label="إصدار مخطط تصحيح عنصر التقييم"
                                type="number"
                                min="1"
                                step="1"
                                required
                                value={
                                    scoringSchemaVersion
                                }
                                onChange={(
                                    event,
                                ) =>
                                    setScoringSchemaVersion(
                                        event
                                            .target
                                            .value,
                                    )
                                }
                            />
                        </label>

                        <label>
                            Scoring Payload

                            <textarea
                                aria-label="بيانات تصحيح عنصر التقييم"
                                rows={8}
                                value={
                                    scoringJson
                                }
                                onChange={(
                                    event,
                                ) =>
                                    setScoringJson(
                                        event
                                            .target
                                            .value,
                                    )
                                }
                            />
                        </label>

                        {payloadError ? (
                            <Feedback tone="danger">
                                {
                                    payloadError
                                }
                            </Feedback>
                        ) : null}

                        <Button
                            type="submit"
                            disabled={
                                createMutation
                                    .isPending
                            }
                        >
                            إنشاء Revision
                        </Button>
                    </form>
                ) : (
                    <Feedback>
                        إنشاء Revisions متاح
                        فقط لعنصر تقييم draft
                        داخل CurriculumVersion
                        draft.
                    </Feedback>
                )}

                {createMutation.isError ? (
                    <RevisionFailure
                        error={
                            createMutation.error
                        }
                    >
                        تعذر إنشاء مراجعة عنصر التقييم.
                    </RevisionFailure>
                ) : null}

                {topicsQuery.isError ? (
                    <RevisionFailure
                        error={
                            topicsQuery.error
                        }
                    >
                        تعذر تحميل Topics.
                    </RevisionFailure>
                ) : null}

                {revisionsQuery.isPending ? (
                    <p>
                        جار تحميل المراجعات…
                    </p>
                ) : revisionsQuery.isError ? (
                    <RevisionFailure
                        error={
                            revisionsQuery.error
                        }
                    >
                        تعذر تحميل مراجعات عنصر التقييم.
                    </RevisionFailure>
                ) : revisionsQuery.data
                    .length === 0 ? (
                    <Feedback>
                        لا توجد Revisions لهذا
                        العنصر.
                    </Feedback>
                ) : (
                    <div className="admin-content-list">
                        {revisionsQuery.data.map(
                            (
                                revision,
                            ) => (
                                <article
                                    key={
                                        revision.id
                                    }
                                    className="admin-content-list__item"
                                >
                                    <div>
                                        <strong>
                                            Revision{' '}
                                            {
                                                revision.revision_number
                                            }
                                        </strong>

                                        <p className="admin-content-list__meta">
                                            الصعوبة:{' '}
                                            {
                                                difficultyLabel(
                                                    revision.difficulty,
                                                )
                                            }
                                            {' · '}
                                            Content Schema{' '}
                                            {
                                                revision.content_schema_version
                                            }
                                            {' · '}
                                            Scoring Schema{' '}
                                            {
                                                revision.scoring_schema_version
                                            }
                                        </p>

                                        <p className="admin-content-list__meta">
                                            {
                                                revision.released_at
                                                    ? 'محررة'
                                                    : 'غير محررة'
                                            }
                                        </p>
                                    </div>

                                    <Button
                                        size="sm"
                                        variant="secondary"
                                        type="button"
                                        onClick={() =>
                                            setClassifyingRevision(
                                                revision,
                                            )
                                        }
                                    >
                                        تصنيف المهارات
                                    </Button>
                                </article>
                            ),
                        )}
                    </div>
                )}
            </div>

            {classifyingRevision ? (
                <AssessmentRevisionSkillsPanel
                    version={version}
                    revision={
                        classifyingRevision
                    }
                    onClose={() =>
                        setClassifyingRevision(
                            null,
                        )
                    }
                />
            ) : null}
        </Surface>
    );
}
