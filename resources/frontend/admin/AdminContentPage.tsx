import '../../css/admin-authoring.css';
import '../../css/admin-authoring-r2.css';

import {
    useCallback,
    useState,
} from 'react';

import {
    Feedback,
} from '../ui';

import {
    ContentContextSelector,
} from './content/ContentContextSelector';
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

import type {
    CurriculumVersion,
} from './content/types';

type WorkspaceSection =
    | 'topics'
    | 'skills'
    | 'lessons'
    | 'assessment-items'
    | 'practice-activities'
    | 'exam-templates';

const workspaceSections: Array<{
    id: WorkspaceSection;
    label: string;
}> = [
    {
        id: 'topics',
        label: 'الموضوعات',
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
];

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
    }
}

export function AdminContentPage() {
    const [subjectId, setSubjectId] =
        useState<string | null>(null);
    const [curriculumId, setCurriculumId] =
        useState<string | null>(null);
    const [curriculumVersionId, setCurriculumVersionId] =
        useState<string | null>(null);
    const [selectedVersion, setSelectedVersion] =
        useState<CurriculumVersion | null>(null);
    const [activeSection, setActiveSection] =
        useState<WorkspaceSection>('lessons');

    const resolveVersion = useCallback(
        (version: CurriculumVersion | null) => {
            setSelectedVersion(version);
        },
        [],
    );

    function renderWorkspace(
        version: CurriculumVersion,
    ) {
        switch (activeSection) {
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
        }
    }

    return (
        <section
            className="admin-authoring"
            aria-labelledby="admin-content-title"
        >
            <header className="admin-authoring__header">
                <div>
                    <h1 id="admin-content-title">
                        إدارة المحتوى
                    </h1>
                    <p>
                        إنشاء وإدارة محتوى المناهج والدروس والأنشطة التعليمية.
                    </p>
                </div>
            </header>

            <ContentContextSelector
                subjectId={subjectId}
                curriculumId={curriculumId}
                curriculumVersionId={curriculumVersionId}
                onSubjectChange={setSubjectId}
                onCurriculumChange={setCurriculumId}
                onCurriculumVersionChange={setCurriculumVersionId}
                onVersionResolved={resolveVersion}
            />

            {!curriculumVersionId ? (
                <Feedback>
                    اختر منهجًا للبدء في إدارة المحتوى.
                </Feedback>
            ) : (
                <div className="admin-authoring__workspace">
                    <nav
                        className="admin-authoring-tabs"
                        role="tablist"
                        aria-label="أقسام إدارة المحتوى"
                    >
                        {workspaceSections.map((section) => {
                            const active =
                                section.id === activeSection;

                            return (
                                <button
                                    key={section.id}
                                    type="button"
                                    role="tab"
                                    aria-selected={active}
                                    className={
                                        active
                                            ? 'admin-authoring-tabs__item admin-authoring-tabs__item--active'
                                            : 'admin-authoring-tabs__item'
                                    }
                                    onClick={() =>
                                        setActiveSection(section.id)
                                    }
                                >
                                    <WorkspaceIcon section={section.id} />
                                    <span>{section.label}</span>
                                </button>
                            );
                        })}
                    </nav>

                    {selectedVersion ? (
                        <div
                            className="admin-authoring__panel"
                            role="tabpanel"
                            aria-label={
                                workspaceSections.find(
                                    (section) =>
                                        section.id === activeSection,
                                )?.label
                            }
                        >
                            {renderWorkspace(selectedVersion)}
                        </div>
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
