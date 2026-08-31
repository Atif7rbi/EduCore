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
    adminSkillsKey,
    createSkill,
    fetchSkills,
    updateSkill,
} from './api';

import type {
    Skill,
} from './types';

function requestId(
    error: unknown,
): string | null {
    return error instanceof EduCoreApiError
        ? error.requestId ?? null
        : null;
}

function SkillFailure({
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

export function SkillsPanel() {
    const queryClient =
        useQueryClient();

    const [
        newName,
        setNewName,
    ] = useState('');

    const [
        newDescription,
        setNewDescription,
    ] = useState('');

    const [
        editingSkill,
        setEditingSkill,
    ] = useState<Skill | null>(
        null,
    );

    const [
        editName,
        setEditName,
    ] = useState('');

    const [
        editDescription,
        setEditDescription,
    ] = useState('');

    const skillsQuery = useQuery({
        queryKey: adminSkillsKey(),
        queryFn: fetchSkills,
    });

    const invalidateSkills =
        async () => {
            await queryClient
                .invalidateQueries({
                    queryKey:
                        adminSkillsKey(),
                });
        };

    const createMutation =
        useMutation({
            mutationFn: () =>
                createSkill({
                    name:
                        newName.trim(),
                    description:
                        newDescription
                            .trim()
                        || null,
                }),
            onSuccess: async () => {
                setNewName('');
                setNewDescription('');

                await invalidateSkills();
            },
        });

    const updateMutation =
        useMutation({
            mutationFn: ({
                skillId,
                name,
                description,
            }: {
                skillId: string;
                name: string;
                description:
                    string | null;
            }) =>
                updateSkill(
                    skillId,
                    {
                        name,
                        description,
                    },
                ),
            onSuccess: async () => {
                setEditingSkill(null);
                setEditName('');
                setEditDescription('');

                await invalidateSkills();
            },
        });

    function submitCreate(
        event: FormEvent,
    ) {
        event.preventDefault();

        if (
            createMutation.isPending
            || newName.trim() === ''
        ) {
            return;
        }

        createMutation.mutate();
    }

    function beginEdit(
        skill: Skill,
    ) {
        setEditingSkill(skill);
        setEditName(skill.name);
        setEditDescription(
            skill.description ?? '',
        );
    }

    function submitEdit(
        event: FormEvent,
    ) {
        event.preventDefault();

        if (
            !editingSkill
            || updateMutation.isPending
            || editName.trim() === ''
        ) {
            return;
        }

        updateMutation.mutate({
            skillId:
                editingSkill.id,
            name:
                editName.trim(),
            description:
                editDescription
                    .trim()
                || null,
        });
    }

    return (
        <Surface>
            <div className="foundation-stack admin-content-panel">
                <div>
                    <h2 className="foundation-card__title">
                        Skills
                    </h2>

                    <p className="foundation-page__description">
                        مكتبة المهارات العامة
                        المستخدمة عبر إصدارات
                        المناهج.
                    </p>
                </div>

                <form
                    className="admin-content-form"
                    onSubmit={
                        submitCreate
                    }
                >
                    <label>
                        اسم المهارة

                        <input
                            aria-label="اسم المهارة الجديدة"
                            value={
                                newName
                            }
                            maxLength={
                                255
                            }
                            required
                            onChange={(
                                event,
                            ) =>
                                setNewName(
                                    event
                                        .target
                                        .value,
                                )
                            }
                        />
                    </label>

                    <label>
                        الوصف

                        <textarea
                            aria-label="وصف المهارة الجديدة"
                            value={
                                newDescription
                            }
                            rows={3}
                            onChange={(
                                event,
                            ) =>
                                setNewDescription(
                                    event
                                        .target
                                        .value,
                                )
                            }
                        />
                    </label>

                    <Button
                        type="submit"
                        disabled={
                            createMutation
                                .isPending
                        }
                    >
                        إضافة Skill
                    </Button>
                </form>

                {createMutation.isError ? (
                    <SkillFailure
                        error={
                            createMutation
                                .error
                        }
                    >
                        تعذر إضافة المهارة.
                    </SkillFailure>
                ) : null}

                {updateMutation.isError ? (
                    <SkillFailure
                        error={
                            updateMutation
                                .error
                        }
                    >
                        تعذر تعديل المهارة.
                    </SkillFailure>
                ) : null}

                {skillsQuery.isPending ? (
                    <p>
                        جار تحميل Skills…
                    </p>
                ) : skillsQuery.isError ? (
                    <SkillFailure
                        error={
                            skillsQuery.error
                        }
                    >
                        تعذر تحميل Skills.
                    </SkillFailure>
                ) : skillsQuery.data
                    .length === 0 ? (
                    <Feedback>
                        لا توجد Skills حتى الآن.
                    </Feedback>
                ) : (
                    <div className="admin-content-list">
                        {skillsQuery.data.map(
                            (skill) => (
                                <article
                                    key={
                                        skill.id
                                    }
                                    className="admin-content-list__item"
                                >
                                    {editingSkill
                                        ?.id
                                    === skill.id ? (
                                        <form
                                            className="admin-content-form admin-content-list__editor"
                                            onSubmit={
                                                submitEdit
                                            }
                                        >
                                            <label>
                                                اسم المهارة

                                                <input
                                                    aria-label="تعديل اسم المهارة"
                                                    value={
                                                        editName
                                                    }
                                                    maxLength={
                                                        255
                                                    }
                                                    required
                                                    onChange={(
                                                        event,
                                                    ) =>
                                                        setEditName(
                                                            event
                                                                .target
                                                                .value,
                                                        )
                                                    }
                                                />
                                            </label>

                                            <label>
                                                الوصف

                                                <textarea
                                                    aria-label="تعديل وصف المهارة"
                                                    rows={
                                                        3
                                                    }
                                                    value={
                                                        editDescription
                                                    }
                                                    onChange={(
                                                        event,
                                                    ) =>
                                                        setEditDescription(
                                                            event
                                                                .target
                                                                .value,
                                                        )
                                                    }
                                                />
                                            </label>

                                            <div className="admin-content-actions">
                                                <Button
                                                    size="sm"
                                                    type="submit"
                                                    disabled={
                                                        updateMutation
                                                            .isPending
                                                    }
                                                >
                                                    حفظ
                                                </Button>

                                                <Button
                                                    size="sm"
                                                    type="button"
                                                    variant="secondary"
                                                    disabled={
                                                        updateMutation
                                                            .isPending
                                                    }
                                                    onClick={() =>
                                                        setEditingSkill(
                                                            null,
                                                        )
                                                    }
                                                >
                                                    إلغاء
                                                </Button>
                                            </div>
                                        </form>
                                    ) : (
                                        <>
                                            <div>
                                                <strong>
                                                    {
                                                        skill.name
                                                    }
                                                </strong>

                                                <p className="admin-content-list__meta">
                                                    {
                                                        skill.description
                                                        ?? 'بدون وصف'
                                                    }
                                                </p>
                                            </div>

                                            <Button
                                                size="sm"
                                                variant="secondary"
                                                disabled={
                                                    updateMutation
                                                        .isPending
                                                }
                                                onClick={() =>
                                                    beginEdit(
                                                        skill,
                                                    )
                                                }
                                            >
                                                تعديل
                                            </Button>
                                        </>
                                    )}
                                </article>
                            ),
                        )}
                    </div>
                )}
            </div>
        </Surface>
    );
}
