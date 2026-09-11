import {
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import {
    MemoryRouter,
    Route,
    Routes,
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
    ResetPasswordPage,
} from './ResetPasswordPage';

const apiRequestMock = vi.fn();

vi.mock('../api/client', () => ({
    apiRequest: (...args: unknown[]) =>
        apiRequestMock(...args),
}));

function renderPage() {
    render(
        <MemoryRouter
            initialEntries={[
                '/reset-password/token-123?email=admin%40example.com',
            ]}
        >
            <Routes>
                <Route
                    path="/reset-password/:token"
                    element={<ResetPasswordPage />}
                />
            </Routes>
        </MemoryRouter>,
    );
}

describe('ResetPasswordPage', () => {
    beforeEach(() => {
        apiRequestMock.mockReset();
    });

    it('prefills the email from the reset link', () => {
        renderPage();

        expect(
            screen.getByRole('textbox', {
                name: 'البريد الإلكتروني',
            }),
        ).toHaveValue('admin@example.com');
    });

    it('rejects mismatched confirmation before calling the API', () => {
        renderPage();

        fireEvent.change(
            screen.getByLabelText(
                'كلمة المرور الجديدة',
            ),
            {
                target: {
                    value: 'EduCore!Reset2026',
                },
            },
        );

        fireEvent.change(
            screen.getByLabelText(
                'تأكيد كلمة المرور الجديدة',
            ),
            {
                target: {
                    value: 'Different!Reset2026',
                },
            },
        );

        fireEvent.click(
            screen.getByRole('button', {
                name: 'حفظ كلمة المرور الجديدة',
            }),
        );

        expect(
            screen.getByText(
                'تأكيد كلمة المرور لا يطابق كلمة المرور الجديدة.',
            ),
        ).toBeInTheDocument();

        expect(apiRequestMock).not.toHaveBeenCalled();
    });

    it('submits the token and new password then shows completion', async () => {
        apiRequestMock.mockResolvedValueOnce({
            reset: true,
        });

        renderPage();

        fireEvent.change(
            screen.getByLabelText(
                'كلمة المرور الجديدة',
            ),
            {
                target: {
                    value: 'EduCore!Reset2026',
                },
            },
        );

        fireEvent.change(
            screen.getByLabelText(
                'تأكيد كلمة المرور الجديدة',
            ),
            {
                target: {
                    value: 'EduCore!Reset2026',
                },
            },
        );

        fireEvent.click(
            screen.getByRole('button', {
                name: 'حفظ كلمة المرور الجديدة',
            }),
        );

        await waitFor(() => {
            expect(apiRequestMock).toHaveBeenCalledWith({
                method: 'POST',
                url: '/auth/reset-password',
                data: {
                    token: 'token-123',
                    email: 'admin@example.com',
                    password: 'EduCore!Reset2026',
                    password_confirmation:
                        'EduCore!Reset2026',
                },
            });
        });

        expect(
            screen.getByText(
                'تم تحديث كلمة المرور بنجاح.',
            ),
        ).toBeInTheDocument();
    });

    it('translates an invalid or expired reset link', async () => {
        apiRequestMock.mockRejectedValueOnce(
            new EduCoreApiError({
                code: 'invalid_password_reset',
                message: 'The password reset link is invalid or has expired.',
                status: 422,
                requestId: 'request-reset-422',
            }),
        );

        renderPage();

        fireEvent.click(
            screen.getByRole('button', {
                name: 'حفظ كلمة المرور الجديدة',
            }),
        );

        await waitFor(() => {
            expect(
                screen.getByText(
                    'رابط الاستعادة غير صالح أو انتهت صلاحيته.',
                ),
            ).toBeInTheDocument();
        });
    });
});
