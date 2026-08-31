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

import type {
    CurriculumVersion,
} from './content/types';

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

    return (
        <section
            className="foundation-page admin-content"
            aria-labelledby="admin-content-title"
        >
            <div className="foundation-page__heading">
                <p className="foundation-page__eyebrow">
                    Admin Studio
                </p>

                <h1
                    id="admin-content-title"
                    className="foundation-page__title"
                >
                    المحتوى والتصنيف
                </h1>

                <p className="foundation-page__description">
                    إدارة Topics وSkills والدروس
                    ضمن إصدار المنهج المحدد.
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
                <div className="admin-content__workspace">
                    {selectedVersion ? (
                        <TopicsPanel
                            version={
                                selectedVersion
                            }
                        />
                    ) : null}

                    <SkillsPanel />

                    {selectedVersion ? (
                        <>
                            <SkillPlacementsPanel
                                version={
                                    selectedVersion
                                }
                            />

                            <LessonsPanel
                                version={
                                    selectedVersion
                                }
                            />
                        </>
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
