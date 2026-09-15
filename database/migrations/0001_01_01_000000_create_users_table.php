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
        Schema::create('users', function (Blueprint $table) {
            // pizzasys user id — NOT auto-incrementing. Replicated from
            // auth.v1.user.* events; this service never mints a user.
            $table->unsignedBigInteger('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('image_path')->nullable();
            $table->timestamps();

            // No password, no email_verified_at, no remember_token: there is no
            // local login. AuthTokenStoreScopeMiddleware verifies every request
            // against pizzasys and then Auth::login()s the mirrored row.
            // password_reset_tokens is intentionally omitted for the same reason.
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            // NOT foreignId(): users.id is externally supplied, and a FK here
            // would reject a session for a user that hasn't replicated yet.
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('sessions');
    }
};
