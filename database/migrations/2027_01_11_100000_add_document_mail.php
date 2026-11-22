<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Dokumente per E-Mail versenden (B-124, D3b): An, Betreff, Text und Antwortadresse je Vorlage (mit Platzhaltern) und
 * das App-Recht „Dokumente: versenden“ – bewusst ohne automatische Vergabe, Versand nach aussen wird freigeschaltet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_templates', function (Blueprint $table): void {
            $table->string('email_to', 500)->nullable()->after('file_field');
            $table->string('email_subject', 300)->nullable()->after('email_to');
            $table->text('email_body')->nullable()->after('email_subject');
            $table->string('email_reply_to', 300)->nullable()->after('email_body');
        });

        Permission::findOrCreate('documents.send', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('document_templates', function (Blueprint $table): void {
            $table->dropColumn(['email_to', 'email_subject', 'email_body', 'email_reply_to']);
        });

        Permission::query()->where('name', 'documents.send')->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
