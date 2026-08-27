import {
    type HTMLAttributes,
} from 'react';

import { cn } from './cn';

interface SurfaceProps
    extends HTMLAttributes<HTMLDivElement> {
    elevated?: boolean;
}

export function Surface({
    children,
    className,
    elevated = false,
    ...props
}: SurfaceProps) {
    return (
        <div
            className={cn(
                'ui-surface',
                elevated && 'ui-surface--elevated',
                className,
            )}
            {...props}
        >
            {children}
        </div>
    );
}
