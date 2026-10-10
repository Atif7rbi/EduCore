import {
    useSearchParams,
    useParams,
} from 'react-router-dom';

import {
    Feedback,
    Surface,
} from '../ui';

const knownSections = new Set([
    'topics',
    'placements',
    'lessons',
    'assessments',
    'practice',
    'exams',
]);

export function TeacherWorkspacePage() {
    const {
        assignmentId,
        curriculumId,
        versionId,
    } = useParams();
    const [searchParams] = useSearchParams();
    const requestedSection =
        searchParams.get('section');
    const section =
        requestedSection && knownSections.has(requestedSection)
            ? requestedSection
            : null;
    const hasScopedContext = Boolean(
        assignmentId
        && curriculumId
        && versionId,
    );

    return (
        <section
            className="foundation-page"
            aria-labelledby="teacher-workspace-title"
        >
            <div className="foundation-page__heading">
                <p className="foundation-page__eyebrow">
                    مساحة المعلم
                </p>

                <h1
                    className="foundation-page__title"
                    id="teacher-workspace-title"
                >
                    تأليف المحتوى
                </h1>

                <p className="foundation-page__description">
                    أنشئ مناهجك ومحتواك التعليمي ضمن سياق المادة والإصدار المعتمدين.
                </p>
            </div>

            <Surface className="foundation-card">
                {hasScopedContext ? (
                    <Feedback>
                        {section
                            ? `سيُفتح قسم ${section} عند اكتمال مساحة التأليف.`
                            : 'سياق التأليف محدد. ستتوفر أدوات المحتوى في الشريحة التالية.'}
                    </Feedback>
                ) : (
                    <Feedback>
                        اختر المادة والمنهج والإصدار لبدء التأليف. ستتوفر أداة الاختيار في الشريحة التالية.
                    </Feedback>
                )}
            </Surface>
        </section>
    );
}