<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('llm_paused')->default(false);
            $table->uuid('llm_lease')->nullable();
            $table->timestamp('llm_lease_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['llm_paused', 'llm_lease', 'llm_lease_until']));
    }
};
