import {
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import {
    MemoryRouter,
} from 'react-router-dom';
import {
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vitest';

import {
    EduCoreApiError,
} from '../api/errors';

import {
    ForgotPasswordPage,
} from './ForgotPasswordPage';

const apiRequestMock = vi.fn();

vi.mock('../api/client', () => ({
    apiRequest: (...args: unknown[]) =>
        apiRequestMock(...args),
}));

function renderPage() {
    render(
        <MemoryRouter>
            <ForgotPasswordPage />
        </MemoryRouter>,
    );
}

describe('ForgotPasswordPage', () => {
    beforeEach(() => {
        apiRequestMock.mockReset();
    });

    it('submits the email and shows the generic success message', async () => {
        apiRequestMock.mockResolvedValueOnce({
            message:
                'If an account exists for this email, a password reset link has been sent.',
        });

        renderPage();

        fireEvent.change(
            screen.getByRole('textbox', {
                name: 'البريد الإلكتروني',
            }),
            {
                target: {
                    value: 'admin@example.com',
                },
            },
        );

        fireEvent.click(
            screen.getByRole('button', {
                name: 'إرسال رابط الاستعادة',
            }),
        );

        await waitFor(() => {
            expect(apiRequestMock).toHaveBeenCalledWith({
                method: 'POST',
                url: '/auth/forgot-password',
                data: {
                    email: 'admin@example.com',
                },
            });
        });

        expect(
            screen.getByText(
                'إذا كان البريد مسجلًا لدينا، فقد أرسلنا رابط استعادة كلمة المرور إليه.',
            ),
        ).toBeInTheDocument();
    });

    it('renders an email validation error returned by the API', async () => {
        apiRequestMock.mockRejectedValueOnce(
            new EduCoreApiError({
                code: 'validation_error',
                message: 'Validation failed.',
                status: 422,
                details: {
                    email: [
                        'البريد الإلكتروني غير صالح.',
                    ],
                },
                requestId: 'request-forgot-422',
            }),
        );

        renderPage();

        fireEvent.click(
            screen.getByRole('button', {
                name: 'إرسال رابط الاستعادة',
            }),
        );

        await waitFor(() => {
            expect(
                screen.getByText(
                    'البريد الإلكتروني غير صالح.',
                ),
            ).toBeInTheDocument();
        });
    });

    it('does not expose raw API failures to the user', async () => {
        apiRequestMock.mockRejectedValueOnce(
            new EduCoreApiError({
                code: 'password_reset_unavailable',
                message: 'SMTP authentication failed.',
                status: 503,
                requestId: 'request-mail-503',
            }),
        );

        renderPage();

        fireEvent.click(
            screen.getByRole('button', {
                name: 'إرسال رابط الاستعادة',
            }),
        );

        await waitFor(() => {
            expect(
                screen.getByText(
                    'تعذر إرسال رابط الاستعادة الآن. حاول مرة أخرى.',
                ),
            ).toBeInTheDocument();
        });

        expect(
            screen.queryByText(
                'SMTP authentication failed.',
            ),
        ).not.toBeInTheDocument();
    });
});
