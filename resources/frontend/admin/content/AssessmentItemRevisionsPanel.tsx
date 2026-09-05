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

function difficultyLabel(
    difficulty: AssessmentDifficulty,
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

function questionStem(
    revision: AssessmentItemRevision,
): string | null {
    const payload = revision.content_payload;

    if (
        Array.isArray(payload)
        || payload === null
        || typeof payload !== 'object'
    ) {
        return null;
    }

    const stem = payload.stem;

    return typeof stem === 'string'
        && stem.trim() !== ''
        ? stem
        : null;
}

export function AssessmentItemRevisionsPanel({
    version,
    item,
    onClose,
}: AssessmentItemRevisionsPanelProps) {
    const queryClient = useQueryClient();

    const editable =
        version.status === 'draft'
        && item.status === 'draft';

    const [
        primaryTopicId,
        setPrimaryTopicId,
    ] = useState('');

    const [
        difficulty,
        setDifficulty,
    ] = useState<AssessmentDifficulty>(
        'medium',
    );

    const [stem, setStem] = useState('');
    const [optionOne, setOptionOne] = useState('');
    const [optionTwo, setOptionTwo] = useState('');
    const [optionThree, setOptionThree] = useState('');
    const [optionFour, setOptionFour] = useState('');
    const [correctOption, setCorrectOption] = useState('0');

    const [
        formError,
        setFormError,
    ] = useState<string | null>(null);

    const [
        classifyingRevision,
        setClassifyingRevision,
    ] = useState<AssessmentItemRevision | null>(
        null,
    );

    const revisionsQuery = useQuery({
        queryKey: adminAssessmentItemRevisionsKey(
            item.id,
        ),
        queryFn: () =>
            fetchAssessmentItemRevisions(item.id),
    });

    const topicsQuery = useQuery({
        queryKey: adminTopicsKey(version.id),
        queryFn: () => fetchTopics(version.id),
    });

    const nextRevisionNumber = useMemo(() => {
        const revisions = revisionsQuery.data ?? [];

        return revisions.reduce(
            (highest, revision) =>
                Math.max(
                    highest,
                    revision.revision_number,
                ),
            0,
        ) + 1;
    }, [revisionsQuery.data]);

    async function invalidate() {
        await queryClient.invalidateQueries({
            queryKey:
                adminAssessmentItemRevisionsKey(
                    item.id,
                ),
        });
    }

    const createMutation = useMutation({
        mutationFn: ({
            revisionNumber,
            topicId,
            selectedDifficulty,
            questionStemValue,
            options,
            correctOptionIndex,
        }: {
            revisionNumber: number;
            topicId: string | null;
            selectedDifficulty:
                AssessmentDifficulty;
            questionStemValue: string;
            options: string[];
            correctOptionIndex: number;
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
                    content_payload: {
                        stem: questionStemValue,
                        options,
                    },
                    content_schema_version: 1,
                    scoring_payload: {
                        correct_option:
                            correctOptionIndex,
                    },
                    scoring_schema_version: 1,
                },
            ),
        onSuccess: async () => {
            setPrimaryTopicId('');
            setDifficulty('medium');
            setStem('');
            setOptionOne('');
            setOptionTwo('');
            setOptionThree('');
            setOptionFour('');
            setCorrectOption('0');
            setFormError(null);

            await invalidate();
        },
    });

    function submit(event: FormEvent) {
        event.preventDefault();

        if (
            !editable
            || createMutation.isPending
            || revisionsQuery.isPending
        ) {
            return;
        }

        const questionStemValue = stem.trim();
        const options = [
            optionOne.trim(),
            optionTwo.trim(),
            optionThree.trim(),
            optionFour.trim(),
        ];
        const correctOptionIndex =
            Number(correctOption);

        if (
            questionStemValue === ''
            || options.some(
                (option) => option === '',
            )
        ) {
            setFormError(
                'أدخل نص السؤال والخيارات الأربعة قبل الحفظ.',
            );
            return;
        }

        if (
            !Number.isInteger(correctOptionIndex)
            || correctOptionIndex < 0
            || correctOptionIndex > 3
        ) {
            setFormError(
                'اختر الإجابة الصحيحة.',
            );
            return;
        }

        setFormError(null);

        createMutation.mutate({
            revisionNumber: nextRevisionNumber,
            topicId: primaryTopicId || null,
            selectedDifficulty: difficulty,
            questionStemValue,
            options,
            correctOptionIndex,
        });
    }

    return (
        <Surface elevated>
            <div className="foundation-stack admin-content-panel">
                <div className="admin-content-revisions__heading">
                    <div>
                        <h3 className="foundation-card__title">
                            {item.internal_label
                                ?? 'تحرير السؤال'}
                        </h3>

                        <p className="foundation-page__description">
                            اكتب السؤال وخياراته وحدد الإجابة الصحيحة ومستوى الصعوبة.
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

                {editable ? (
                    <form
                        className="admin-content-form"
                        onSubmit={submit}
                    >
                        <label>
                            نص السؤال

                            <textarea
                                aria-label="نص السؤال"
                                rows={4}
                                required
                                value={stem}
                                onChange={(event) =>
                                    setStem(
                                        event.target.value,
                                    )
                                }
                            />
                        </label>

                        <label>
                            الخيار الأول

                            <input
                                aria-label="الخيار الأول"
                                required
                                value={optionOne}
                                onChange={(event) =>
                                    setOptionOne(
                                        event.target.value,
                                    )
                                }
                            />
                        </label>

                        <label>
                            الخيار الثاني

                            <input
                                aria-label="الخيار الثاني"
                                required
                                value={optionTwo}
                                onChange={(event) =>
                                    setOptionTwo(
                                        event.target.value,
                                    )
                                }
                            />
                        </label>

                        <label>
                            الخيار الثالث

                            <input
                                aria-label="الخيار الثالث"
                                required
                                value={optionThree}
                                onChange={(event) =>
                                    setOptionThree(
                                        event.target.value,
                                    )
                                }
                            />
                        </label>

                        <label>
                            الخيار الرابع

                            <input
                                aria-label="الخيار الرابع"
                                required
                                value={optionFour}
                                onChange={(event) =>
                                    setOptionFour(
                                        event.target.value,
                                    )
                                }
                            />
                        </label>

                        <label>
                            الإجابة الصحيحة

                            <select
                                aria-label="الإجابة الصحيحة"
                                value={correctOption}
                                onChange={(event) =>
                                    setCorrectOption(
                                        event.target.value,
                                    )
                                }
                            >
                                <option value="0">
                                    الخيار الأول
                                </option>
                                <option value="1">
                                    الخيار الثاني
                                </option>
                                <option value="2">
                                    الخيار الثالث
                                </option>
                                <option value="3">
                                    الخيار الرابع
                                </option>
                            </select>
                        </label>

                        <label>
                            الوحدة الرئيسية

                            <select
                                aria-label="الوحدة الرئيسية للسؤال"
                                value={primaryTopicId}
                                onChange={(event) =>
                                    setPrimaryTopicId(
                                        event.target.value,
                                    )
                                }
                            >
                                <option value="">
                                    بدون وحدة محددة
                                </option>

                                {topicsQuery.data?.map(
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

                        <label>
                            مستوى الصعوبة

                            <select
                                aria-label="مستوى صعوبة السؤال"
                                value={difficulty}
                                onChange={(event) => {
                                    const value =
                                        event.target.value;

                                    if (
                                        value === 'easy'
                                        || value === 'medium'
                                        || value === 'hard'
                                    ) {
                                        setDifficulty(value);
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

                        {formError ? (
                            <Feedback tone="danger">
                                {formError}
                            </Feedback>
                        ) : null}

                        <Button
                            type="submit"
                            disabled={
                                createMutation.isPending
                                || revisionsQuery.isPending
                            }
                        >
                            حفظ محتوى السؤال
                        </Button>
                    </form>
                ) : (
                    <Feedback>
                        هذا السؤال للقراءة فقط ولا يمكن إضافة تعديلات جديدة إليه.
                    </Feedback>
                )}

                {createMutation.isError ? (
                    <RevisionFailure
                        error={createMutation.error}
                    >
                        تعذر حفظ محتوى السؤال.
                    </RevisionFailure>
                ) : null}

                {topicsQuery.isError ? (
                    <RevisionFailure
                        error={topicsQuery.error}
                    >
                        تعذر تحميل الوحدات.
                    </RevisionFailure>
                ) : null}

                {revisionsQuery.isPending ? (
                    <p>جار تحميل محتوى السؤال…</p>
                ) : revisionsQuery.isError ? (
                    <RevisionFailure
                        error={revisionsQuery.error}
                    >
                        تعذر تحميل محتوى السؤال.
                    </RevisionFailure>
                ) : revisionsQuery.data.length === 0 ? (
                    <Feedback>
                        لم تتم إضافة محتوى لهذا السؤال حتى الآن.
                    </Feedback>
                ) : (
                    <div className="admin-content-list">
                        {revisionsQuery.data.map(
                            (revision, index) => (
                                <article
                                    key={revision.id}
                                    className="admin-content-list__item"
                                >
                                    <div>
                                        <strong>
                                            {questionStem(revision)
                                                ?? `محتوى محفوظ ${index + 1}`}
                                        </strong>

                                        <p className="admin-content-list__meta">
                                            الصعوبة:{' '}
                                            {difficultyLabel(
                                                revision.difficulty,
                                            )}
                                            {' · '}
                                            {revision.released_at
                                                ? 'معتمد'
                                                : 'مسودة'}
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
                                        ربط المهارات
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
                    revision={classifyingRevision}
                    onClose={() =>
                        setClassifyingRevision(null)
                    }
                />
            ) : null}
        </Surface>
    );
}
