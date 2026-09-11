import {
    type HTMLAttributes,
} from 'react';

import { cn } from './cn';

export function Container({
    children,
    className,
    ...props
}: HTMLAttributes<HTMLDivElement>) {
    return (
        <div
            className={cn(
                'ui-container',
                className,
            )}
            {...props}
        >
            {children}
        </div>
    );
}
