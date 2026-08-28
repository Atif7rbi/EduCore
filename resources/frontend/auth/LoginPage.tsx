import {
    type FormEvent,
    useEffect,
    useRef,
    useState,
} from 'react';
import {
    useNavigate,
} from 'react-router-dom';

import {
    EduCoreApiError,
} from '../api/errors';
import {
    Button,
    Feedback,
    Surface,
    TextField,
} from '../ui';

import {
    useAuth,
} from './AuthProvider';

interface LoginFieldErrors {
    email?: string;
    password?: string;
}

function firstFieldError(
    error: EduCoreApiError,
    field: string,
): string | undefined {
    return error.details?.[field]?.[0];
}

function destinationForRole(
    role: string,
): string {
    return role === 'admin'
        ? '/admin'
        : '/app';
}

export function LoginPage() {
    const navigate = useNavigate();

    const {
        status,
        user,
        error: bootstrapError,
        login,
        refresh,
    } = useAuth();

    const [email, setEmail] =
        useState('');

    const [password, setPassword] =
        useState('');

    const [isSubmitting, setIsSubmitting] =
        useState(false);

    const [fieldErrors, setFieldErrors] =
        useState<LoginFieldErrors>({});

    const [submitError, setSubmitError] =
        useState<EduCoreApiError | null>(null);

    const isLoginSubmissionRef =
        useRef(false);

    useEffect(() => {
        if (
            !isLoginSubmissionRef.current &&
            status === 'authenticated' &&
            user
        ) {
            navigate(
                destinationForRole(user.role),
                {
                    replace: true,
                },
            );
        }
    }, [
        navigate,
        status,
        user,
    ]);

    async function handleSubmit(
        event: FormEvent<HTMLFormElement>,
    ) {
        event.preventDefault();

        setFieldErrors({});
        setSubmitError(null);
        setIsSubmitting(true);
        isLoginSubmissionRef.current = true;

        try {
            const authenticatedUser =
                await login({
                    email,
                    password,
                });

            navigate(
                destinationForRole(
                    authenticatedUser.role,
                ),
                {
                    replace: true,
                },
            );
        } catch (caughtError) {
            isLoginSubmissionRef.current = false;

            if (
                caughtError instanceof EduCoreApiError
            ) {
                setFieldErrors({
                    email:
                        firstFieldError(
                            caughtError,
                            'email',
                        ),
                    password:
                        firstFieldError(
                            caughtError,
                            'password',
                        ),
                });

                setSubmitError(caughtError);
            } else {
                setSubmitError(
                    new EduCoreApiError({
                        code: 'unexpected_client_error',
                        message: 'حدث خطأ غير متوقع.',
                        status: null,
                        requestId: null,
                    }),
                );
            }
        } finally {
            setIsSubmitting(false);
        }
    }

    if (status === 'loading') {
        return (
            <section
                className="auth-page"
                aria-labelledby="login-title"
            >
                <div className="auth-page__heading">
                    <p className="foundation-page__eyebrow">
                        الوصول إلى المنصة
                    </p>

                    <h1
                        className="foundation-page__title"
                        id="login-title"
                    >
                        تسجيل الدخول
                    </h1>
                </div>

                <Feedback>
                    جارٍ التحقق من حالة الجلسة...
                </Feedback>
            </section>
        );
    }

    return (
        <section
            className="auth-page"
            aria-labelledby="login-title"
        >
            <div className="auth-page__heading">
                <p className="foundation-page__eyebrow">
                    الوصول إلى المنصة
                </p>

                <h1
                    className="foundation-page__title"
                    id="login-title"
                >
                    تسجيل الدخول
                </h1>

                <p className="foundation-page__description">
                    استخدم حسابك المسجل للوصول إلى EduCore.
                </p>
            </div>

            <Surface
                className="auth-card"
                elevated
            >
                <form
                    className="auth-form"
                    onSubmit={handleSubmit}
                    noValidate
                >
                    {status === 'error' &&
                    bootstrapError ? (
                        <div className="auth-form__stack">
                            <Feedback tone="danger">
                                تعذر التحقق من حالة الجلسة.
                            </Feedback>

                            <Button
                                variant="secondary"
                                onClick={() => {
                                    void refresh();
                                }}
                            >
                                إعادة المحاولة
                            </Button>
                        </div>
                    ) : null}

                    {submitError ? (
                        <Feedback tone="danger">
                            {submitError.message}

                            {submitError.requestId ? (
                                <span className="auth-error-reference">
                                    {' '}
                                    رقم المرجع:
                                    {' '}
                                    {submitError.requestId}
                                </span>
                            ) : null}
                        </Feedback>
                    ) : null}

                    <TextField
                        type="email"
                        label="البريد الإلكتروني"
                        value={email}
                        onChange={(event) => {
                            setEmail(event.target.value);
                        }}
                        error={fieldErrors.email}
                        autoComplete="email"
                        inputMode="email"
                        dir="ltr"
                    />

                    <TextField
                        type="password"
                        label="كلمة المرور"
                        value={password}
                        onChange={(event) => {
                            setPassword(event.target.value);
                        }}
                        error={fieldErrors.password}
                        autoComplete="current-password"
                    />

                    <Button
                        type="submit"
                        isLoading={isSubmitting}
                    >
                        تسجيل الدخول
                    </Button>
                </form>
            </Surface>
        </section>
    );
}
