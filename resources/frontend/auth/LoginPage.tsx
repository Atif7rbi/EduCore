import {
    type FormEvent,
    useEffect,
    useRef,
    useState,
} from 'react';
import {
    useLocation,
    useNavigate,
} from 'react-router-dom';

import '../../css/login-polish.css';

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

function loginErrorMessage(
    error: EduCoreApiError,
): string {
    if (
        error.code === 'invalid_credentials'
        || error.status === 401
    ) {
        return 'بيانات تسجيل الدخول غير صحيحة.';
    }

    if (
        error.code === 'csrf_token_mismatch'
        || error.status === 419
    ) {
        return 'انتهت صلاحية الجلسة. أعد المحاولة.';
    }

    if (
        error.code === 'validation_error'
        || error.status === 422
    ) {
        return 'تحقق من البيانات المدخلة ثم حاول مرة أخرى.';
    }

    return 'تعذر تسجيل الدخول الآن. حاول مرة أخرى.';
}

function destinationForRole(
    role: string,
): string {
    return role === 'admin'
        ? '/admin'
        : '/app';
}

function isWithinPath(
    destination: string,
    basePath: string,
): boolean {
    return (
        destination === basePath ||
        destination.startsWith(
            `${basePath}/`,
        ) ||
        destination.startsWith(
            `${basePath}?`,
        ) ||
        destination.startsWith(
            `${basePath}#`,
        )
    );
}

function requestedDestination(
    role: string,
    from: unknown,
): string {
    if (typeof from !== 'string') {
        return destinationForRole(role);
    }

    if (
        isWithinPath(
            from,
            '/app',
        )
    ) {
        return from;
    }

    if (
        role === 'admin' &&
        isWithinPath(
            from,
            '/admin',
        )
    ) {
        return from;
    }

    return destinationForRole(role);
}

function LoginHeading({
    includeDescription = true,
}: {
    includeDescription?: boolean;
}) {
    return (
        <div className="auth-page__heading">
            <div
                className="auth-page__brand-mark"
                aria-hidden="true"
            />

            <p className="foundation-page__eyebrow">
                الوصول إلى المنصة
            </p>

            <h1
                className="foundation-page__title"
                id="login-title"
            >
                تسجيل الدخول
            </h1>

            {includeDescription ? (
                <p className="foundation-page__description">
                    استخدم حسابك المسجل للوصول إلى EduCore ومتابعة عملك من مكان واحد.
                </p>
            ) : null}
        </div>
    );
}

export function LoginPage() {
    const navigate = useNavigate();
    const location = useLocation();

    const from =
        (
            location.state as
                | { from?: unknown }
                | null
        )?.from;

    const {
        status,
        user,
        error: bootstrapError,
        sessionIssue,
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
                requestedDestination(
                    user.role,
                    from,
                ),
                {
                    replace: true,
                },
            );
        }
    }, [
        from,
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
                requestedDestination(
                    authenticatedUser.role,
                    from,
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
                className="auth-page auth-page--login"
                aria-labelledby="login-title"
            >
                <LoginHeading
                    includeDescription={false}
                />

                <Feedback>
                    جارٍ التحقق من حالة الجلسة...
                </Feedback>
            </section>
        );
    }

    return (
        <section
            className="auth-page auth-page--login"
            aria-labelledby="login-title"
        >
            <LoginHeading />

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
                                    void refresh().catch(
                                        () => undefined,
                                    );
                                }}
                            >
                                إعادة المحاولة
                            </Button>
                        </div>
                    ) : null}

                    {sessionIssue ? (
                        <Feedback tone="warning">
                            {sessionIssue.kind === 'csrf'
                                ? 'انتهت صلاحية حماية الجلسة. سجّل الدخول مرة أخرى للمتابعة.'
                                : 'انتهت صلاحية جلستك. سجّل الدخول مرة أخرى للمتابعة.'}

                            {sessionIssue.requestId ? (
                                <span className="auth-error-reference">
                                    رقم المرجع: {sessionIssue.requestId}
                                </span>
                            ) : null}
                        </Feedback>
                    ) : null}

                    {submitError ? (
                        <Feedback tone="danger">
                            <div className="auth-login-error">
                                <span className="auth-login-error__message">
                                    {loginErrorMessage(
                                        submitError,
                                    )}
                                </span>

                                {submitError.requestId ? (
                                    <span className="auth-error-reference">
                                        رقم المرجع: {submitError.requestId}
                                    </span>
                                ) : null}
                            </div>
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
