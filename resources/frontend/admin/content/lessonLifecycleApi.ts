import {
    apiRequest,
} from '../../api/client';

import type {
    Lesson,
} from './types';

export function unpublishLesson(
    lessonId: string,
): Promise<Lesson> {
    return apiRequest<Lesson>({
        method: 'POST',
        url:
            `/api/lessons/${lessonId}/retire`,
    });
}
