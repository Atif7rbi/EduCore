<?php

namespace App\Application\Taxonomy;

use App\Application\Support\TransactionManager;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class CreateSkill
{
    public function __construct(
        private readonly TransactionManager $transactions,
    ) {}

    public function execute(
        string $actorUserId,
        string $name,
        ?string $description,
    ): Skill {
        return $this->transactions->run(
            function () use (
                $actorUserId,
                $name,
                $description,
            ): Skill {
                $actor = User::query()
                    ->whereKey($actorUserId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (
                    ! $actor->isAdmin()
                    || ! $actor->isActive()
                ) {
                    throw (new ModelNotFoundException)
                        ->setModel(
                            User::class,
                            [$actorUserId],
                        );
                }

                return Skill::query()->create([
                    'name' => $name,
                    'description' => $description,
                ]);
            }
        );
    }
}
