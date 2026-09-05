import {
    type FormEvent,
    useState,
} from 'react';
import {
    Link,
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

interface ForgotPasswordPayload {
    message: string;
}

export function ForgotPasswordPage() {
    const [email, setEmail] = useState('');
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [sent, setSent] = useState(false);
    const [error, setError] = useState<string | null>(null);

    async function handleSubmit(
        event: FormEvent<HTMLFormElement>,
    ) {
        event.preventDefault();
        setError(null);
        setIsSubmitting(true);

        try {
            await apiRequest<ForgotPasswordPayload>({
                method: 'POST',
                url: '/auth/forgot-password',
                data: { email },
            });

            setSent(true);
        } catch (caughtError) {
            if (caughtError instanceof EduCoreApiError) {
                setError(
                    caughtError.details?.email?.[0]
                    ?? 'تعذر إرسال رابط الاستعادة الآن. حاول مرة أخرى.',
                );
            } else {
                setError('تعذر إرسال رابط الاستعادة الآن. حاول مرة أخرى.');
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
                    نسيت كلمة المرور؟
                </h1>
                <p className="foundation-page__description">
                    أدخل بريدك الإلكتروني وسنرسل لك رابطًا آمنًا لتعيين كلمة مرور جديدة.
                </p>
            </div>

            <Surface className="auth-card" elevated>
                {sent ? (
                    <div className="auth-form auth-form__stack">
                        <Feedback>
                            إذا كان البريد مسجلًا لدينا، فقد أرسلنا رابط استعادة كلمة المرور إليه.
                        </Feedback>
                        <Link to="/login" className="auth-secondary-link">
                            العودة إلى تسجيل الدخول
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

                        <Button type="submit" isLoading={isSubmitting}>
                            إرسال رابط الاستعادة
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
