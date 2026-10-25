import '../../css/admin-authoring.css';
import '../../css/admin-authoring-r2.css';
import '../../css/admin-lesson-user.css';
import '../../css/admin-content-ux-polish.css';

import {
    useCallback,
    useRef,
    useState,
    type KeyboardEvent,
} from 'react';
import {
    useSearchParams,
} from 'react-router-dom';
import {
    useQuery,
} from '@tanstack/react-query';

import {
    Feedback,
} from '../ui';

import {
    ContentContextSelector,
} from './content/ContentContextSelector';
import type {
    ContentContextState,
    ContextNavigationMode,
} from './content/ContentContextSelector';
import {
    TeacherOwnedContentInspection,
} from './content/TeacherOwnedContentInspection';
import {
    TopicsPanel,
} from './content/TopicsPanel';
import {
    SkillsPanel,
} from './content/SkillsPanel';
import {
    SkillPlacementsPanel,
} from './content/SkillPlacementsPanel';
import {
    LessonsPanel,
} from './content/LessonsPanel';
import {
    AssessmentItemsPanel,
} from './content/AssessmentItemsPanel';
import {
    PracticeActivitiesPanel,
} from './content/PracticeActivitiesPanel';
import {
    ExamTemplatesPanel,
} from './content/ExamTemplatesPanel';
import {
    ContentReadinessPanel,
    contentReadinessKey,
    fetchContentReadiness,
} from './content/ContentReadinessPanel';

import type {
    CurriculumReadiness,
} from './content/ContentReadinessPanel';

import type {
    Curriculum,
    CurriculumVersion,
} from './content/types';

type WorkspaceSection =
    | 'topics'
    | 'skills'
    | 'lessons'
    | 'assessment-items'
    | 'practice-activities'
    | 'exam-templates'
    | 'readiness';

type PublishingTabState =
    | 'pass'
    | 'blocker'
    | 'warning';

const readinessCheckBySection:
Partial<Record<WorkspaceSection, string>> = {
    topics: 'has_topic',
    lessons: 'has_published_lesson',
    'assessment-items':
        'has_published_assessment_item',
    'practice-activities':
        'has_learner_usable_practice',
    'exam-templates':
        'has_usable_exam_template',
    skills: 'has_skill_placement',
};

const warningSectionByCode:
Record<string, WorkspaceSection> = {
    draft_lessons: 'lessons',
    unpublished_lessons: 'lessons',
    draft_assessment_items:
        'assessment-items',
    retired_assessment_items:
        'assessment-items',
    archived_practice_activities:
        'practice-activities',
    active_practice_hidden_by_lesson:
        'practice-activities',
    archived_exam_templates:
        'exam-templates',
    active_exam_templates_without_published_version:
        'exam-templates',
    draft_exam_template_versions:
        'exam-templates',
    retired_exam_template_versions:
        'exam-templates',
};

function publishingTabState(
    section: WorkspaceSection,
    readiness:
        CurriculumReadiness | undefined,
): PublishingTabState | null {
    if (
        !readiness
        || readiness.curriculum_version.status
            !== 'draft'
    ) {
        return null;
    }

    if (section === 'readiness') {
        return readiness.ready_to_publish
            ? 'pass'
            : 'blocker';
    }

    const checkCode =
        readinessCheckBySection[section];

    if (!checkCode) {
        return null;
    }

    const check = readiness.checks.find(
        (candidate) =>
            candidate.code === checkCode,
    );

    if (!check) {
        return null;
    }

    if (!check.passed) {
        return 'blocker';
    }

    const hasWarning =
        readiness.warnings.some(
            (warning) =>
                warningSectionByCode[
                    warning.code
                ] === section,
        );

    return hasWarning
        ? 'warning'
        : 'pass';
}

function publishingTabTitle(
    state: PublishingTabState,
): string {
    if (state === 'pass') {
        return 'مستوفٍ لمتطلبات النشر';
    }

    if (state === 'warning') {
        return 'يحتوي على تنبيه غير مانع للنشر';
    }

    return 'يحتاج إكمالًا قبل النشر';
}

const workspaceSections: Array<{
    id: WorkspaceSection;
    label: string;
}> = [
    {
        id: 'topics',
        label: 'الوحدات',
    },
    {
        id: 'lessons',
        label: 'الدروس',
    },
    {
        id: 'assessment-items',
        label: 'بنك الأسئلة',
    },
    {
        id: 'practice-activities',
        label: 'التدريبات',
    },
    {
        id: 'exam-templates',
        label: 'الاختبارات',
    },
    {
        id: 'skills',
        label: 'المهارات',
    },
    {
        id: 'readiness',
        label: 'مراجعة النشر',
    },
];

function workspaceSectionFromSearch(
    value: string | null,
): WorkspaceSection {
    return workspaceSections.some(
        (section) => section.id === value,
    )
        ? value as WorkspaceSection
        : 'lessons';
}

function WorkspaceIcon({
    section,
}: {
    section: WorkspaceSection;
}) {
    const common = {
        width: 22,
        height: 22,
        viewBox: '0 0 24 24',
        fill: 'none',
        stroke: 'currentColor',
        strokeWidth: 1.8,
        strokeLinecap: 'round' as const,
        strokeLinejoin: 'round' as const,
        'aria-hidden': true,
    };

    switch (section) {
        case 'topics':
            return (
                <svg {...common}>
                    <path d="M3.5 6.5h6l2 2h9v9.5a2 2 0 0 1-2 2h-13a2 2 0 0 1-2-2z" />
                </svg>
            );
        case 'skills':
            return (
                <svg {...common}>
                    <circle cx="12" cy="12" r="8" />
                    <circle cx="12" cy="12" r="4" />
                    <path d="M12 4V2M20 12h2" />
                </svg>
            );
        case 'lessons':
            return (
                <svg {...common}>
                    <path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H11v16H6.5A2.5 2.5 0 0 0 4 21z" />
                    <path d="M20 5.5A2.5 2.5 0 0 0 17.5 3H13v16h4.5A2.5 2.5 0 0 1 20 21z" />
                </svg>
            );
        case 'assessment-items':
            return (
                <svg {...common}>
                    <rect x="4" y="4" width="6" height="6" rx="1" />
                    <rect x="14" y="4" width="6" height="6" rx="1" />
                    <rect x="4" y="14" width="6" height="6" rx="1" />
                    <rect x="14" y="14" width="6" height="6" rx="1" />
                </svg>
            );
        case 'practice-activities':
            return (
                <svg {...common}>
                    <path d="M9 6h11M9 12h11M9 18h11" />
                    <path d="m4 6 1 1 2-2M4 12h3M4 18h3" />
                </svg>
            );
        case 'exam-templates':
            return (
                <svg {...common}>
                    <path d="M7 3h8l4 4v14H7z" />
                    <path d="M15 3v5h5M10 12h6M10 16h6" />
                </svg>
            );
        case 'readiness':
            return (
                <svg {...common}>
                    <path d="M5 4h14v16H5z" />
                    <path d="m8 9 1.5 1.5L12 8" />
                    <path d="M13.5 10H16" />
                    <path d="m8 15 1.5 1.5L12 14" />
                    <path d="M13.5 16H16" />
                </svg>
            );
    }
}

export function AdminContentPage() {
    const [searchParams, setSearchParams] =
        useSearchParams();
    const subjectId =
        searchParams.get('subject_id');
    const curriculumId =
        searchParams.get('curriculum_id');
    const curriculumVersionId =
        searchParams.get('version_id');
    const [selectedCurriculum, setSelectedCurriculum] =
        useState<Curriculum | null>(null);
    const [selectedVersion, setSelectedVersion] =
        useState<CurriculumVersion | null>(null);
    const [contextError, setContextError] =
        useState<string | null>(null);
    const [contextState, setContextState] =
        useState<ContentContextState>(
            'subjects-loading',
        );
    const tabRefs = useRef<Array<HTMLButtonElement | null>>([]);
    const activeSection =
        workspaceSectionFromSearch(
            searchParams.get('section'),
        );

    const updateSubject = useCallback((
        value: string | null,
        mode: ContextNavigationMode = 'push',
    ) => {
        setSearchParams((current) => {
            const next =
                new URLSearchParams(current);

            if (value) {
                next.set('subject_id', value);
            } else {
                next.delete('subject_id');
            }

            next.delete('curriculum_id');
            next.delete('version_id');

            return next;
        }, {
            replace: mode === 'replace',
        });
    }, [setSearchParams]);

    const updateCurriculum = useCallback((
        value: string | null,
        mode: ContextNavigationMode = 'push',
    ) => {
        setSearchParams((current) => {
            const next =
                new URLSearchParams(current);

            if (value) {
                next.set('curriculum_id', value);
            } else {
                next.delete('curriculum_id');
            }

            next.delete('version_id');

            return next;
        }, {
            replace: mode === 'replace',
        });
    }, [setSearchParams]);

    const updateVersion = useCallback((
        value: string | null,
        mode: ContextNavigationMode = 'push',
    ) => {
        setSearchParams((current) => {
            const next =
                new URLSearchParams(current);

            if (value) {
                next.set('version_id', value);
            } else {
                next.delete('version_id');
            }

            return next;
        }, {
            replace: mode === 'replace',
        });
    }, [setSearchParams]);

    const contextReady =
        selectedCurriculum?.id
            === curriculumId
        && selectedVersion?.id
            === curriculumVersionId
        && selectedVersion?.curriculum_id
            === curriculumId;

    const isTeacherOwned =
        contextReady
        && selectedCurriculum
            ?.teacher_subject_assignment_id
        != null;

    const publishingReadiness = useQuery({
        queryKey: contentReadinessKey(
            selectedVersion?.id ?? 'none',
        ),
        queryFn: () =>
            fetchContentReadiness(
                selectedVersion!.id,
            ),
        enabled:
            !isTeacherOwned
            && selectedVersion?.status
            === 'draft',
    });

    const resolveCurriculum = useCallback(
        (curriculum: Curriculum | null) => {
            setSelectedCurriculum(
                curriculum,
            );
        },
        [],
    );

    const resolveVersion = useCallback(
        (version: CurriculumVersion | null) => {
            setSelectedVersion(version);
        },
        [],
    );

    function selectSection(
        section: WorkspaceSection,
    ) {
        setSearchParams(
            (current) => {
                const next =
                    new URLSearchParams(current);
                next.set('section', section);
                return next;
            },
            { replace: false },
        );
    }

    function renderWorkspace(
        section: WorkspaceSection,
        version: CurriculumVersion,
    ) {
        switch (section) {
            case 'topics':
                return <TopicsPanel version={version} />;
            case 'skills':
                return (
                    <div className="admin-skills-workspace">
                        <SkillsPanel />
                        <SkillPlacementsPanel version={version} />
                    </div>
                );
            case 'lessons':
                return <LessonsPanel version={version} />;
            case 'assessment-items':
                return <AssessmentItemsPanel version={version} />;
            case 'practice-activities':
                return <PracticeActivitiesPanel version={version} />;
            case 'exam-templates':
                return <ExamTemplatesPanel version={version} />;
            case 'readiness':
                return (
                    <ContentReadinessPanel
                        version={version}
                        onNavigateToSection={
                            selectSection
                        }
                    />
                );
        }
    }

    function focusAndSelectTab(
        index: number,
    ) {
        const section =
            workspaceSections[index];

        if (!section) {
            return;
        }

        selectSection(section.id);
        tabRefs.current[index]?.focus();
    }

    function handleTabKeyDown(
        event: KeyboardEvent<HTMLButtonElement>,
        index: number,
    ) {
        let nextIndex: number | null = null;

        if (event.key === 'ArrowLeft') {
            nextIndex =
                (index + 1)
                % workspaceSections.length;
        } else if (event.key === 'ArrowRight') {
            nextIndex =
                (index - 1 + workspaceSections.length)
                % workspaceSections.length;
        } else if (event.key === 'Home') {
            nextIndex = 0;
        } else if (event.key === 'End') {
            nextIndex =
                workspaceSections.length - 1;
        }

        if (nextIndex === null) {
            return;
        }

        event.preventDefault();
        focusAndSelectTab(nextIndex);
    }

    return (
        <section
            className="admin-authoring"
            aria-labelledby="admin-content-title"
        >
            <header className="admin-authoring__header">
                <div>
                    <h1 id="admin-content-title">
                        {isTeacherOwned
                            ? 'استعراض المحتوى'
                            : 'إدارة المحتوى'}
                    </h1>
                    <p>
                        {isTeacherOwned
                            ? 'استعراض حالة المحتوى المملوك للمعلم دون صلاحيات التأليف أو النشر.'
                            : 'إنشاء وإدارة المحتوى التاريخي غير المملوك للمعلمين.'}
                    </p>
                </div>
            </header>

            <ContentContextSelector
                subjectId={subjectId}
                curriculumId={curriculumId}
                curriculumVersionId={curriculumVersionId}
                onSubjectChange={updateSubject}
                onCurriculumChange={updateCurriculum}
                onCurriculumVersionChange={updateVersion}
                onCurriculumResolved={resolveCurriculum}
                onVersionResolved={resolveVersion}
                onContextErrorChange={setContextError}
                onContextStateChange={setContextState}
            />

            {contextError ? null
            : contextState.endsWith('-loading')
                || contextState.endsWith('-fetching')
                || contextState.endsWith('-error')
                || contextState.endsWith('-empty')
                || contextState.endsWith('-invalid')
                ? null
            : contextState === 'subject-missing' ? (
                <Feedback>
                    اختر مادة لعرض سياق المحتوى.
                </Feedback>
            ) : contextState === 'curriculum-missing' ? (
                <Feedback>
                    اختر منهجًا لعرض المحتوى.
                </Feedback>
            ) : contextState === 'version-missing' ? (
                <Feedback>
                    اختر إصدارًا لعرض المحتوى.
                </Feedback>
            ) : !contextReady ? (
                <div className="admin-authoring__loading">
                    جار التحقق من سياق المحتوى…
                </div>
            ) : isTeacherOwned ? (
                <div className="admin-authoring__workspace">
                    <Feedback tone="info">
                        <div className="foundation-stack">
                            <strong>
                                محتوى مملوك للمعلم —
                                استعراض فقط
                            </strong>

                            <span>
                                المعلم:
                                {' '}
                                {selectedCurriculum
                                    ?.teacher?.name
                                    ?? 'غير متاح'}
                                {' — '}
                                {selectedCurriculum
                                    ?.teacher?.email
                                    ?? '—'}
                            </span>

                            <span>
                                Teacher UUID:
                                {' '}
                                {selectedCurriculum
                                    ?.teacher_user_id
                                    ?? '—'}
                            </span>

                            <span>
                                TeacherSubjectAssignment:
                                {' '}
                                {selectedCurriculum
                                    ?.teacher_subject_assignment_id
                                    ?? '—'}
                                {' — '}
                                الحالة:
                                {' '}
                                {selectedCurriculum
                                    ?.teacher_subject_assignment_status
                                    ?? '—'}
                            </span>

                            <span>
                                المادة:
                                {' '}
                                {selectedCurriculum
                                    ?.subject.name
                                    ?? '—'}
                                {' '}
                                (
                                {selectedCurriculum
                                    ?.subject.code
                                    ?? '—'}
                                )
                            </span>

                            <span>
                                واجهة الـAdmin هنا
                                للاستعراض فقط، ولا تعرض
                                أي أدوات تأليف أو نشر
                                أو تغيير دورة حياة.
                            </span>
                        </div>
                    </Feedback>

                    {selectedVersion ? (
                        <>
                            <TeacherOwnedContentInspection
                                version={selectedVersion}
                            />

                            <ContentReadinessPanel
                                version={selectedVersion}
                                readOnly
                            />
                        </>
                    ) : (
                        <div className="admin-authoring__loading">
                            جار تجهيز وضع الاستعراض…
                        </div>
                    )}
                </div>
            ) : (
                <div className="admin-authoring__workspace">
                    <nav
                        className="admin-authoring-tabs"
                        role="tablist"
                        aria-label="أقسام إدارة المحتوى"
                    >
                        {workspaceSections.map((section, index) => {
                            const active =
                                section.id === activeSection;
                            const tabId =
                                `admin-content-tab-${section.id}`;
                            const panelId =
                                `admin-content-panel-${section.id}`;

                            const readinessState =
                                publishingTabState(
                                    section.id,
                                    publishingReadiness
                                        .data,
                                );

                            return (
                                <button
                                    key={section.id}
                                    ref={(node) => {
                                        tabRefs.current[index] = node;
                                    }}
                                    id={tabId}
                                    type="button"
                                    role="tab"
                                    aria-controls={panelId}
                                    aria-selected={active}
                                    tabIndex={active ? 0 : -1}
                                    data-readiness-state={
                                        readinessState
                                        ?? undefined
                                    }
                                    title={
                                        readinessState
                                            ? `${section.label}: ${publishingTabTitle(
                                                readinessState
                                            )}`
                                            : undefined
                                    }
                                    className={
                                        active
                                            ? 'admin-authoring-tabs__item admin-authoring-tabs__item--active'
                                            : 'admin-authoring-tabs__item'
                                    }
                                    onClick={() =>
                                        selectSection(section.id)
                                    }
                                    onKeyDown={(event) => {
                                        handleTabKeyDown(
                                            event,
                                            index,
                                        );
                                    }}
                                >
                                    <WorkspaceIcon section={section.id} />
                                    <span>{section.label}</span>
                                </button>
                            );
                        })}
                    </nav>

                    {selectedVersion ? (
                        workspaceSections.map((section) => {
                            const active =
                                section.id === activeSection;
                            const tabId =
                                `admin-content-tab-${section.id}`;
                            const panelId =
                                `admin-content-panel-${section.id}`;

                            return (
                                <div
                                    key={section.id}
                                    id={panelId}
                                    className="admin-authoring__panel"
                                    role="tabpanel"
                                    aria-labelledby={tabId}
                                    hidden={!active}
                                    tabIndex={active ? 0 : -1}
                                >
                                    {active
                                        ? renderWorkspace(
                                            section.id,
                                            selectedVersion,
                                        )
                                        : null}
                                </div>
                            );
                        })
                    ) : (
                        <div className="admin-authoring__loading">
                            جار تجهيز مساحة العمل…
                        </div>
                    )}
                </div>
            )}
        </section>
    );
}
