import {
    type ButtonHTMLAttributes,
    forwardRef,
} from 'react';

import { cn } from './cn';

export type ButtonVariant =
    | 'primary'
    | 'secondary'
    | 'ghost'
    | 'danger';

export type ButtonSize =
    | 'sm'
    | 'md'
    | 'lg';

interface ButtonProps
    extends ButtonHTMLAttributes<HTMLButtonElement> {
    variant?: ButtonVariant;
    size?: ButtonSize;
    isLoading?: boolean;
}

export const Button = forwardRef<
    HTMLButtonElement,
    ButtonProps
>(function Button(
    {
        children,
        className,
        disabled,
        isLoading = false,
        size = 'md',
        type = 'button',
        variant = 'primary',
        ...props
    },
    ref,
) {
    return (
        <button
            ref={ref}
            type={type}
            className={cn(
                'ui-button',
                `ui-button--${variant}`,
                `ui-button--${size}`,
                className,
            )}
            disabled={disabled || isLoading}
            aria-busy={isLoading || undefined}
            {...props}
        >
            {isLoading ? (
                <span
                    className="ui-button__spinner"
                    aria-hidden="true"
                />
            ) : null}

            <span>
                {children}
            </span>
        </button>
    );
});
