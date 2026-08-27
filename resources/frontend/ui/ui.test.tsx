import {
    fireEvent,
    render,
    screen,
} from '@testing-library/react';
import {
    describe,
    expect,
    it,
    vi,
} from 'vitest';

import {
    Button,
    Container,
    Feedback,
    Surface,
    TextField,
} from './index';

describe('design system primitives', () => {
    it('button preserves native interaction semantics', () => {
        const onClick = vi.fn();

        render(
            <Button onClick={onClick}>
                متابعة
            </Button>,
        );

        fireEvent.click(
            screen.getByRole('button', {
                name: 'متابعة',
            }),
        );

        expect(onClick).toHaveBeenCalledOnce();
    });

    it('loading button is disabled and exposes busy state', () => {
        render(
            <Button isLoading>
                حفظ
            </Button>,
        );

        const button =
            screen.getByRole('button', {
                name: 'حفظ',
            });

        expect(button).toBeDisabled();
        expect(button).toHaveAttribute(
            'aria-busy',
            'true',
        );
    });

    it('text field connects accessible validation feedback', () => {
        render(
            <TextField
                label="البريد الإلكتروني"
                description="استخدم البريد المسجل في المنصة."
                error="البريد الإلكتروني مطلوب."
            />,
        );

        const input =
            screen.getByRole('textbox', {
                name: 'البريد الإلكتروني',
            });

        expect(input).toHaveAttribute(
            'aria-invalid',
            'true',
        );

        const describedBy =
            input.getAttribute('aria-describedby');

        expect(describedBy).toBeTruthy();

        expect(
            screen.getByText(
                'استخدم البريد المسجل في المنصة.',
            ),
        ).toBeInTheDocument();

        expect(
            screen.getByRole('alert'),
        ).toHaveTextContent(
            'البريد الإلكتروني مطلوب.',
        );
    });

    it('danger feedback uses alert semantics', () => {
        render(
            <Feedback tone="danger">
                تعذر إكمال الطلب.
            </Feedback>,
        );

        expect(
            screen.getByRole('alert'),
        ).toHaveTextContent(
            'تعذر إكمال الطلب.',
        );
    });

    it('non-danger feedback uses status semantics', () => {
        render(
            <Feedback tone="success">
                تم الحفظ بنجاح.
            </Feedback>,
        );

        expect(
            screen.getByRole('status'),
        ).toHaveTextContent(
            'تم الحفظ بنجاح.',
        );
    });

    it('layout primitives preserve consumer attributes', () => {
        render(
            <Container data-testid="container">
                <Surface data-testid="surface">
                    محتوى
                </Surface>
            </Container>,
        );

        expect(
            screen.getByTestId('container'),
        ).toHaveClass('ui-container');

        expect(
            screen.getByTestId('surface'),
        ).toHaveClass('ui-surface');
    });
});
