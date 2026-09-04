import {
    useCallback,
    useState,
} from 'react';

import {
    Feedback,
    Surface,
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
        id: 'skills',
        label: 'المهارات',
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
];

export function AdminContentPage() {
    const [
        subjectId,
        setSubjectId,
    ] = useState<string | null>(null);

    const [
        curriculumId,
        setCurriculumId,
    ] = useState<string | null>(null);

    const [
        curriculumVersionId,
        setCurriculumVersionId,
    ] = useState<string | null>(
        null,
    );

    const [
        selectedVersion,
        setSelectedVersion,
    ] =
        useState<CurriculumVersion | null>(
            null,
        );

    const [
        activeSection,
        setActiveSection,
    ] = useState<WorkspaceSection>(
        'topics',
    );

    const resolveVersion =
        useCallback(
            (
                version:
                    CurriculumVersion | null,
            ) => {
                setSelectedVersion(
                    version,
                );
            },
            [],
        );

    function renderWorkspace(
        version: CurriculumVersion,
    ) {
        switch (activeSection) {
            case 'topics':
                return (
                    <TopicsPanel
                        version={version}
                    />
                );
            case 'skills':
                return (
                    <div className="grid gap-4 xl:grid-cols-2">
                        <SkillsPanel />

                        <SkillPlacementsPanel
                            version={version}
                        />
                    </div>
                );
            case 'lessons':
                return (
                    <LessonsPanel
                        version={version}
                    />
                );
            case 'assessment-items':
                return (
                    <AssessmentItemsPanel
                        version={version}
                    />
                );
            case 'practice-activities':
                return (
                    <PracticeActivitiesPanel
                        version={version}
                    />
                );
            case 'exam-templates':
                return (
                    <ExamTemplatesPanel
                        version={version}
                    />
                );
        }
    }

    return (
        <section
            className="foundation-page admin-content"
            aria-labelledby="admin-content-title"
        >
            <div className="foundation-page__heading">
                <p className="foundation-page__eyebrow">
                    مساحة التأليف
                </p>

                <h1
                    id="admin-content-title"
                    className="foundation-page__title"
                >
                    إدارة المحتوى
                </h1>

                <p className="foundation-page__description">
                    أنشئ ونظّم محتوى المنهج من
                    مساحة عمل واحدة وواضحة.
                </p>
            </div>

            <ContentContextSelector
                subjectId={subjectId}
                curriculumId={
                    curriculumId
                }
                curriculumVersionId={
                    curriculumVersionId
                }
                onSubjectChange={
                    setSubjectId
                }
                onCurriculumChange={
                    setCurriculumId
                }
                onCurriculumVersionChange={
                    setCurriculumVersionId
                }
                onVersionResolved={
                    resolveVersion
                }
            />

            {!curriculumVersionId ? (
                <Feedback>
                    اختر إصدار منهج للبدء
                    في إدارة المحتوى.
                </Feedback>
            ) : (
                <div className="grid gap-4">
                    <Surface>
                        <div
                            className="flex gap-2 overflow-x-auto p-2"
                            role="tablist"
                            aria-label="أقسام إدارة المحتوى"
                        >
                            {workspaceSections.map(
                                (section) => {
                                    const active =
                                        section.id
                                        === activeSection;

                                    return (
                                        <button
                                            key={
                                                section.id
                                            }
                                            type="button"
                                            role="tab"
                                            aria-selected={
                                                active
                                            }
                                            className={[
                                                'min-h-11 shrink-0 rounded-xl px-4 py-2 text-sm font-bold transition-colors',
                                                active
                                                    ? 'bg-sky-700 text-white shadow-sm'
                                                    : 'text-slate-700 hover:bg-slate-100',
                                            ].join(' ')}
                                            onClick={() =>
                                                setActiveSection(
                                                    section.id,
                                                )
                                            }
                                        >
                                            {
                                                section.label
                                            }
                                        </button>
                                    );
                                },
                            )}
                        </div>
                    </Surface>

                    {selectedVersion ? (
                        <div
                            role="tabpanel"
                            aria-label={
                                workspaceSections.find(
                                    (
                                        section,
                                    ) =>
                                        section.id
                                        === activeSection,
                                )?.label
                            }
                        >
                            {renderWorkspace(
                                selectedVersion,
                            )}
                        </div>
                    ) : (
                        <Surface>
                            <div className="foundation-stack">
                                <h2 className="foundation-card__title">
                                    حالة التأليف
                                </h2>

                                <p>
                                    جار تحديد حالة التأليف…
                                </p>
                            </div>
                        </Surface>
                    )}
                </div>
            )}
        </section>
    );
}
