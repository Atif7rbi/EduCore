import {
    render,
    screen,
} from '@testing-library/react';
import {
    MemoryRouter,
} from 'react-router-dom';
import {
    describe,
    expect,
    it,
} from 'vitest';

import {
    LearnerFoundationPage,
} from './App';

describe('frontend foundation', () => {
    it('renders the learner foundation in the RTL test environment', () => {
        render(
            <MemoryRouter>
                <LearnerFoundationPage />
            </MemoryRouter>,
        );

        expect(
            screen.getByRole('heading', {
                name: 'EduCore',
            }),
        ).toBeInTheDocument();

        expect(
            document.documentElement.lang,
        ).toBe('ar');

        expect(
            document.documentElement.dir,
        ).toBe('rtl');
    });
});
