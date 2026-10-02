import {
    useQuery,
} from '@tanstack/react-query';

import {
    Feedback,
    Surface,
} from '../../ui';

import {
    adminAssessmentItemsKey,
    adminExamTemplatesKey,
    adminLessonsKey,
    adminPlacementsKey,
    adminPracticeActivitiesKey,
    adminTopicsKey,
    fetchAssessmentItems,
    fetchExamTemplates,
    fetchLessons,
    fetchPlacements,
    fetchPracticeActivities,
    fetchTopics,
} from './api';

import type {
    CurriculumVersion,
} from './types';

interface TeacherOwnedContentInspectionProps {
    version: CurriculumVersion;
}

function EmptyState({
    children,
}: {
    children: string;
}) {
    return (
        <p className="foundation-page__description">
            {children}
        </p>
    );
}

export function TeacherOwnedContentInspection({
    version,
}: TeacherOwnedContentInspectionProps) {
    const topicsQuery = useQuery({
        queryKey:
            adminTopicsKey(version.id),
        queryFn: () =>
            fetchTopics(version.id),
    });

    const lessonsQuery = useQuery({
        queryKey:
            adminLessonsKey(version.id),
        queryFn: () =>
            fetchLessons(version.id),
    });

    const assessmentItemsQuery = useQuery({
        queryKey:
            adminAssessmentItemsKey(
                version.id,
            ),
        queryFn: () =>
            fetchAssessmentItems(
                version.id,
            ),
    });

    const practiceActivitiesQuery = useQuery({
        queryKey:
            adminPracticeActivitiesKey(
                version.id,
            ),
        queryFn: () =>
            fetchPracticeActivities(
                version.id,
            ),
    });

    const examTemplatesQuery = useQuery({
        queryKey:
            adminExamTemplatesKey(
                version.id,
            ),
        queryFn: () =>
            fetchExamTemplates(
                version.id,
            ),
    });

    const placementsQuery = useQuery({
        queryKey:
            adminPlacementsKey(version.id),
        queryFn: () =>
            fetchPlacements(version.id),
    });

    const queries = [
        topicsQuery,
        lessonsQuery,
        assessmentItemsQuery,
        practiceActivitiesQuery,
        examTemplatesQuery,
        placementsQuery,
    ];

    if (
        queries.some(
            (query) => query.isPending,
        )
    ) {
        return (
            <Surface aria-busy="true">
                جار تحميل محتوى المنهج
                للاستعراض…
            </Surface>
        );
    }

    if (
        queries.some(
            (query) => query.isError,
        )
    ) {
        return (
            <Feedback tone="danger">
                تعذر تحميل بعض محتوى المنهج
                للاستعراض.
            </Feedback>
        );
    }

    return (
        <div
            className="foundation-stack"
            data-testid="teacher-owned-content-inspection"
        >
            <Surface>
                <div className="foundation-stack">
                    <h2>
                        استعراض محتوى المنهج
                    </h2>

                    <Feedback tone="info">
                        هذه مساحة قراءة فقط.
                        لا توجد عمليات إنشاء أو تعديل
                        أو نشر أو تغيير دورة حياة.
                    </Feedback>
                </div>
            </Surface>

            <Surface>
                <div className="foundation-stack">
                    <h3>الوحدات</h3>

                    {topicsQuery.data?.length ? (
                        <ul>
                            {topicsQuery.data.map(
                                (topic) => (
                                    <li key={topic.id}>
                                        {topic.name}
                                    </li>
                                ),
                            )}
                        </ul>
                    ) : (
                        <EmptyState>
                            لا توجد وحدات.
                        </EmptyState>
                    )}
                </div>
            </Surface>

            <Surface>
                <div className="foundation-stack">
                    <h3>الدروس</h3>

                    {lessonsQuery.data?.length ? (
                        <ul>
                            {lessonsQuery.data.map(
                                (lesson) => (
                                    <li key={lesson.id}>
                                        <strong>
                                            {lesson.title}
                                        </strong>
                                        {' — '}
                                        {lesson.status}
                                    </li>
                                ),
                            )}
                        </ul>
                    ) : (
                        <EmptyState>
                            لا توجد دروس.
                        </EmptyState>
                    )}
                </div>
            </Surface>

            <Surface>
                <div className="foundation-stack">
                    <h3>بنك الأسئلة</h3>

                    {assessmentItemsQuery
                        .data?.length ? (
                        <ul>
                            {assessmentItemsQuery
                                .data
                                .map(
                                    (item) => (
                                        <li key={item.id}>
                                            {item.internal_label
                                                ?? 'سؤال بدون عنوان داخلي'}
                                            {' — '}
                                            {item.status}
                                        </li>
                                    ),
                                )}
                        </ul>
                    ) : (
                        <EmptyState>
                            لا توجد أسئلة.
                        </EmptyState>
                    )}
                </div>
            </Surface>

            <Surface>
                <div className="foundation-stack">
                    <h3>التدريبات</h3>

                    {practiceActivitiesQuery
                        .data?.length ? (
                        <ul>
                            {practiceActivitiesQuery
                                .data
                                .map(
                                    (activity) => (
                                        <li
                                            key={
                                                activity.id
                                            }
                                        >
                                            {activity.name}
                                            {' — '}
                                            {activity.status}
                                        </li>
                                    ),
                                )}
                        </ul>
                    ) : (
                        <EmptyState>
                            لا توجد تدريبات.
                        </EmptyState>
                    )}
                </div>
            </Surface>

            <Surface>
                <div className="foundation-stack">
                    <h3>الاختبارات</h3>

                    {examTemplatesQuery
                        .data?.length ? (
                        <ul>
                            {examTemplatesQuery
                                .data
                                .map(
                                    (template) => (
                                        <li
                                            key={
                                                template.id
                                            }
                                        >
                                            {template.name}
                                            {' — '}
                                            {template.status}
                                        </li>
                                    ),
                                )}
                        </ul>
                    ) : (
                        <EmptyState>
                            لا توجد اختبارات.
                        </EmptyState>
                    )}
                </div>
            </Surface>

            <Surface>
                <div className="foundation-stack">
                    <h3>مهارات المنهج</h3>

                    <p>
                        عدد روابط المهارات:
                        {' '}
                        <strong>
                            {placementsQuery
                                .data
                                ?.length
                                ?? 0}
                        </strong>
                    </p>
                </div>
            </Surface>
        </div>
    );
}
