<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learner_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('user_id')->unique();

            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learner_profiles');
    }
};
