import {
    type InputHTMLAttributes,
    useId,
} from 'react';

import { cn } from './cn';

interface TextFieldProps
    extends InputHTMLAttributes<HTMLInputElement> {
    label: string;
    description?: string;
    error?: string;
}

export function TextField({
    className,
    description,
    error,
    id,
    label,
    ...props
}: TextFieldProps) {
    const generatedId = useId();
    const inputId = id ?? generatedId;

    const descriptionId =
        description
            ? `${inputId}-description`
            : undefined;

    const errorId =
        error
            ? `${inputId}-error`
            : undefined;

    const describedBy = [
        descriptionId,
        errorId,
    ]
        .filter(Boolean)
        .join(' ') || undefined;

    return (
        <div className="ui-field">
            <label
                className="ui-field__label"
                htmlFor={inputId}
            >
                {label}
            </label>

            {description ? (
                <p
                    className="ui-field__description"
                    id={descriptionId}
                >
                    {description}
                </p>
            ) : null}

            <input
                {...props}
                id={inputId}
                className={cn(
                    'ui-input',
                    error && 'ui-input--invalid',
                    className,
                )}
                aria-invalid={
                    error
                        ? true
                        : undefined
                }
                aria-describedby={describedBy}
            />

            {error ? (
                <p
                    className="ui-field__error"
                    id={errorId}
                    role="alert"
                >
                    {error}
                </p>
            ) : null}
        </div>
    );
}
