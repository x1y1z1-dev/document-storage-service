<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('file_records', function (Blueprint $table) {
            $table->id();                                          // BIGINT UNSIGNED PK auto-increment
            $table->string('original_filename', 255);
            $table->string('storage_path', 512)->unique();
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('file_size_bytes');
            $table->dateTime('upload_timestamp');
            $table->dateTime('expiration_timestamp')->index();
            $table->timestamps();                                  // created_at / updated_at TIMESTAMP
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('file_records');
    }
};
