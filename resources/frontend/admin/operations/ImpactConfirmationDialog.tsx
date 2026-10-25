import {
    useEffect,
    useId,
    useRef,
} from 'react';

import {
    Button,
} from '../../ui';

export function ImpactConfirmationDialog({
    title,
    target,
    impact,
    reason,
    confirmLabel,
    isPending,
    errorMessage = null,
    isCancellationLocked,
    onCancel,
    onConfirm,
}: {
    title: string;
    target: string;
    impact: string;
    reason: string;
    confirmLabel: string;
    isPending: boolean;
    errorMessage?: string | null;
    isCancellationLocked: () => boolean;
    onCancel: () => void;
    onConfirm: () => void;
}) {
    const titleId = useId();
    const descriptionId = useId();
    const dialogRef =
        useRef<HTMLDialogElement>(null);
    const cancelButtonRef =
        useRef<HTMLButtonElement>(null);
    const initiatingControlRef =
        useRef<HTMLElement | null>(null);

    useEffect(() => {
        const dialog = dialogRef.current;
        initiatingControlRef.current =
            document.activeElement instanceof HTMLElement
                ? document.activeElement
                : null;

        if (dialog && !dialog.open) {
            if (typeof dialog.showModal === 'function') {
                dialog.showModal();
            } else {
                dialog.setAttribute('open', '');
            }
        }

        cancelButtonRef.current?.focus();

        return () => {
            if (dialog?.open) {
                if (typeof dialog.close === 'function') {
                    dialog.close();
                } else {
                    dialog.removeAttribute('open');
                }
            }

            const initiatingControl =
                initiatingControlRef.current;

            if (
                initiatingControl
                && document.contains(initiatingControl)
            ) {
                initiatingControl.focus();
            }
        };
    }, []);

    function focusableControls() {
        return Array.from(
            dialogRef.current?.querySelectorAll<HTMLElement>(
                'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
            ) ?? [],
        );
    }

    function requestCancellation() {
        if (
            !isPending
            && !isCancellationLocked()
        ) {
            onCancel();
        }
    }

    return (
        <dialog
            ref={dialogRef}
            className="admin-impact-confirmation"
            aria-labelledby={titleId}
            aria-describedby={descriptionId}
            onCancel={(event) => {
                event.preventDefault();

                requestCancellation();
            }}
            onKeyDown={(event) => {
                if (event.key !== 'Tab') {
                    return;
                }

                const controls = focusableControls();
                const first = controls[0];
                const last = controls[controls.length - 1];

                if (!first || !last) {
                    event.preventDefault();
                    return;
                }

                if (
                    event.shiftKey
                    && document.activeElement === first
                ) {
                    event.preventDefault();
                    last.focus();
                } else if (
                    !event.shiftKey
                    && document.activeElement === last
                ) {
                    event.preventDefault();
                    first.focus();
                }
            }}
        >
            <div className="foundation-stack">
                <h4 id={titleId}>
                    {title}
                </h4>

                <div
                    id={descriptionId}
                    className="foundation-stack"
                >
                    <p>
                        <strong>الهدف:</strong>
                        {' '}
                        {target}
                    </p>

                    <p>
                        <strong>الأثر:</strong>
                        {' '}
                        {impact}
                    </p>

                    <p>
                        <strong>السبب المسجل:</strong>
                        {' '}
                        {reason}
                    </p>

                    {errorMessage ? (
                        <p
                            className="admin-impact-confirmation__error"
                            role="alert"
                        >
                            {errorMessage}
                        </p>
                    ) : null}
                </div>
            </div>

            <div className="admin-impact-confirmation__actions">
                <Button
                    ref={cancelButtonRef}
                    variant="secondary"
                    disabled={isPending}
                    onClick={requestCancellation}
                >
                    تراجع
                </Button>

                <Button
                    variant="danger"
                    isLoading={isPending}
                    disabled={isPending}
                    onClick={onConfirm}
                >
                    {confirmLabel}
                </Button>
            </div>
        </dialog>
    );
}
