import {
    type FormEvent,
    useState,
} from 'react';
import {
    Link,
    useParams,
    useSearchParams,
} from 'react-router-dom';

import '../../css/login-polish.css';

import {
    apiRequest,
} from '../api/client';
import {
    EduCoreApiError,
} from '../api/errors';
import {
    Button,
    Feedback,
    Surface,
    TextField,
} from '../ui';

interface ResetPasswordPayload {
    reset: boolean;
}

export function ResetPasswordPage() {
    const { token = '' } = useParams<{ token: string }>();
    const [searchParams] = useSearchParams();
    const [email, setEmail] = useState(
        searchParams.get('email') ?? '',
    );
    const [password, setPassword] = useState('');
    const [passwordConfirmation, setPasswordConfirmation] = useState('');
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [resetComplete, setResetComplete] = useState(false);
    const [error, setError] = useState<string | null>(null);

    async function handleSubmit(
        event: FormEvent<HTMLFormElement>,
    ) {
        event.preventDefault();
        setError(null);

        if (password !== passwordConfirmation) {
            setError('تأكيد كلمة المرور لا يطابق كلمة المرور الجديدة.');
            return;
        }

        setIsSubmitting(true);

        try {
            await apiRequest<ResetPasswordPayload>({
                method: 'POST',
                url: '/auth/reset-password',
                data: {
                    token,
                    email,
                    password,
                    password_confirmation: passwordConfirmation,
                },
            });

            setResetComplete(true);
        } catch (caughtError) {
            if (caughtError instanceof EduCoreApiError) {
                setError(
                    caughtError.details?.password?.[0]
                    ?? caughtError.details?.email?.[0]
                    ?? (
                        caughtError.code === 'invalid_password_reset'
                            ? 'رابط الاستعادة غير صالح أو انتهت صلاحيته.'
                            : 'تعذر تعيين كلمة المرور الجديدة. حاول مرة أخرى.'
                    ),
                );
            } else {
                setError('تعذر تعيين كلمة المرور الجديدة. حاول مرة أخرى.');
            }
        } finally {
            setIsSubmitting(false);
        }
    }

    return (
        <section className="auth-page auth-page--login">
            <div className="auth-page__heading">
                <div className="auth-page__brand-mark" aria-hidden="true" />
                <p className="foundation-page__eyebrow">
                    استعادة الوصول
                </p>
                <h1 className="foundation-page__title">
                    تعيين كلمة مرور جديدة
                </h1>
                <p className="foundation-page__description">
                    اختر كلمة مرور قوية ثم استخدمها لتسجيل الدخول إلى EduCore.
                </p>
            </div>

            <Surface className="auth-card" elevated>
                {resetComplete ? (
                    <div className="auth-form auth-form__stack">
                        <Feedback>
                            تم تحديث كلمة المرور بنجاح.
                        </Feedback>
                        <Link to="/login" className="auth-secondary-link">
                            تسجيل الدخول الآن
                        </Link>
                    </div>
                ) : (
                    <form className="auth-form" onSubmit={handleSubmit} noValidate>
                        {error ? (
                            <Feedback tone="danger">
                                {error}
                            </Feedback>
                        ) : null}

                        <TextField
                            type="email"
                            label="البريد الإلكتروني"
                            value={email}
                            onChange={(event) => setEmail(event.target.value)}
                            autoComplete="email"
                            inputMode="email"
                            dir="ltr"
                        />

                        <TextField
                            type="password"
                            label="كلمة المرور الجديدة"
                            value={password}
                            onChange={(event) => setPassword(event.target.value)}
                            autoComplete="new-password"
                        />

                        <TextField
                            type="password"
                            label="تأكيد كلمة المرور الجديدة"
                            value={passwordConfirmation}
                            onChange={(event) => setPasswordConfirmation(event.target.value)}
                            autoComplete="new-password"
                        />

                        <Button type="submit" isLoading={isSubmitting}>
                            حفظ كلمة المرور الجديدة
                        </Button>

                        <Link to="/login" className="auth-secondary-link">
                            العودة إلى تسجيل الدخول
                        </Link>
                    </form>
                )}
            </Surface>
        </section>
    );
}
