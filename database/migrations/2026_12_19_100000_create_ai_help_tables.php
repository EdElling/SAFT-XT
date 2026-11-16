<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Hilfe-Assistent: gespeicherte Unterhaltungen je Person und ihre Nachrichten (Frage, Antwort, Quellen, Tokens). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_help_conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 200);
            $table->string('language', 5);
            $table->timestamps();
            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('ai_help_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained('ai_help_conversations')->cascadeOnDelete();
            $table->string('role', 10);
            $table->text('content');
            $table->json('sources')->nullable();
            $table->string('model', 100)->nullable();
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->unsignedInteger('tokens_cache')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_help_messages');
        Schema::dropIfExists('ai_help_conversations');
    }
};
