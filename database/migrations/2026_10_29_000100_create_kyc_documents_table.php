<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Keep a database copy of each owner KYC document.
     * Render's free plan deletes files on every deploy, so the disk copy alone is not safe.
     */
    public function up(): void
    {
        Schema::create('kyc_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('field', 50);
            $table->string('mime', 100);
            $table->longText('data'); // base64 file content
            $table->timestamps();

            $table->unique(['user_id', 'field']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_documents');
    }
};
