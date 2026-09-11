<?php

namespace App\Application\Exceptions;

class CurriculumVersionNotReady extends ApplicationException
{
    /**
     * @param array<int, array{
     *     code: string,
     *     message: string,
     *     value: string|int
     * }> $blockers
     */
    public function __construct(
        public readonly array $blockers,
    ) {
        parent::__construct(
            'Curriculum version is not ready to publish.'
        );
    }
}
