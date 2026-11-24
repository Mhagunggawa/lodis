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
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_login_at')->nullable()->after('remember_token');
        });

        Schema::create('monitored_domains', function (Blueprint $table) {
            $table->id();
            $table->string('domain_name')->unique();
            $table->unsignedInteger('port')->default(443);
            $table->string('issuer')->nullable();
            $table->dateTime('ssl_valid_from')->nullable();
            $table->dateTime('ssl_expires_at')->nullable();
            $table->integer('days_remaining')->nullable();
            $table->enum('status', ['valid', 'warning', 'critical', 'error'])->default('error');
            $table->dateTime('last_checked_at')->nullable();
            $table->dateTime('last_reminded_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('server_action_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('action_type', ['reload_webserver', 'renew_ssl']);
            $table->enum('status', ['queued', 'running', 'success', 'failed'])->default('queued');
            $table->longText('output')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('app_settings');
        Schema::dropIfExists('server_action_logs');
        Schema::dropIfExists('monitored_domains');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('last_login_at');
        });
    }
};
