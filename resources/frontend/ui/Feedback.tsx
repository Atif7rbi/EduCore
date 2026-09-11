import {
    type HTMLAttributes,
} from 'react';

import { cn } from './cn';

export type FeedbackTone =
    | 'info'
    | 'success'
    | 'warning'
    | 'danger';

interface FeedbackProps
    extends HTMLAttributes<HTMLDivElement> {
    tone?: FeedbackTone;
}

export function Feedback({
    children,
    className,
    tone = 'info',
    ...props
}: FeedbackProps) {
    const isDanger = tone === 'danger';

    return (
        <div
            className={cn(
                'ui-feedback',
                `ui-feedback--${tone}`,
                className,
            )}
            role={
                isDanger
                    ? 'alert'
                    : 'status'
            }
            {...props}
        >
            {children}
        </div>
    );
}
