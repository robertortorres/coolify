<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')
                ->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->index()
                ->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->index()
                ->constrained()->cascadeOnDelete();
            $table->enum('permission', ['read', 'operate'])->default('read');
            $table->foreignId('granted_by')->nullable()->index()
                ->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['application_id', 'user_id']);
            $table->unique(['application_id', 'team_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_shares');
    }
};
