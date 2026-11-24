<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('mail_messages', function (Blueprint $table) {
            $table->foreignId('crm_contact_id')->nullable()->constrained('crm_contacts')->nullOnDelete();
            $table->foreignId('crm_company_id')->nullable()->constrained('crm_companies')->nullOnDelete();
            $table->index(['crm_contact_id', 'received_at']);
            $table->index(['crm_company_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::table('mail_messages', function (Blueprint $table) {
            $table->dropIndex(['crm_contact_id', 'received_at']);
            $table->dropIndex(['crm_company_id', 'received_at']);
            $table->dropConstrainedForeignId('crm_contact_id');
            $table->dropConstrainedForeignId('crm_company_id');
        });
    }
};
