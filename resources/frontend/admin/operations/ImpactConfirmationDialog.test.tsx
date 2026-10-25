import {
    useState,
} from 'react';
import {
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import {
    describe,
    expect,
    it,
    vi,
} from 'vitest';

import {
    ImpactConfirmationDialog,
} from './ImpactConfirmationDialog';

function DialogHarness({
    isPending = false,
}: {
    isPending?: boolean;
}) {
    const [open, setOpen] = useState(false);

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
            >
                بدء العملية
            </button>
            <button type="button">
                إجراء خلفي
            </button>

            {open ? (
                <ImpactConfirmationDialog
                    title="تأكيد العملية"
                    target="الهدف المحدد"
                    impact="أثر واضح"
                    reason="سبب ثابت"
                    confirmLabel="تأكيد"
                    isPending={isPending}
                    isCancellationLocked={() => isPending}
                    onCancel={() => setOpen(false)}
                    onConfirm={vi.fn()}
                />
            ) : null}
        </>
    );
}

describe('ImpactConfirmationDialog', () => {
    it('moves focus inside, traps keyboard traversal, and restores the initiator', async () => {
        render(<DialogHarness />);

        const initiator = screen.getByRole('button', {
            name: 'بدء العملية',
        });
        initiator.focus();
        fireEvent.click(initiator);

        const cancel = await screen.findByRole('button', {
            name: 'تراجع',
        });
        const confirm = screen.getByRole('button', {
            name: 'تأكيد',
        });
        const dialog = screen.getByRole('dialog', {
            name: 'تأكيد العملية',
        });

        await waitFor(() => {
            expect(cancel).toHaveFocus();
        });

        fireEvent.keyDown(cancel, {
            key: 'Tab',
            shiftKey: true,
        });
        expect(confirm).toHaveFocus();

        fireEvent.keyDown(confirm, {
            key: 'Tab',
        });
        expect(cancel).toHaveFocus();
        expect(
            screen.getByRole('button', {
                name: 'إجراء خلفي',
            }),
        ).not.toHaveFocus();

        fireEvent(dialog, new Event('cancel', {
            cancelable: true,
        }));

        await waitFor(() => {
            expect(
                screen.queryByRole('dialog'),
            ).not.toBeInTheDocument();
            expect(initiator).toHaveFocus();
        });
    });

    it('refuses Escape dismissal while a mutation is pending', async () => {
        render(<DialogHarness isPending />);

        fireEvent.click(screen.getByRole('button', {
            name: 'بدء العملية',
        }));
        const dialog = await screen.findByRole('dialog');

        fireEvent(dialog, new Event('cancel', {
            cancelable: true,
        }));

        expect(dialog).toBeInTheDocument();
        expect(screen.getByRole('button', {
            name: 'تراجع',
        })).toBeDisabled();
        expect(screen.getByRole('button', {
            name: 'تأكيد',
        })).toBeDisabled();
    });
});
