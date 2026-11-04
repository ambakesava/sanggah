<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('user');
            $table->timestamp('last_login_at')->nullable();
        });

        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('title');
            $table->string('publisher')->nullable();
            $table->string('url')->nullable();
            $table->string('type')->default('lainnya');
            $table->string('lang', 8)->default('id');
            $table->string('license')->nullable();
            $table->date('accessed_at')->nullable();
            $table->string('content_hash')->nullable();
            $table->string('validated_by')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('objects', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name_id');
            $table->string('name_en');
            $table->json('aliases')->nullable();
            $table->string('category');
            $table->text('visual_hint')->nullable();
            $table->json('static_card_id');
            $table->json('static_card_en');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->string('lang', 8);
            $table->text('content');
            $table->unsignedInteger('token_count')->default(0);
            $table->string('section')->nullable();
            $table->string('vector_id')->nullable();
            $table->string('embedding_model')->nullable();
            $table->string('content_hash');
            $table->string('corpus_version')->default('2026.1');
            $table->timestamps();
        });

        Schema::create('chunk_object', function (Blueprint $table) {
            $table->foreignId('chunk_id')->constrained()->cascadeOnDelete();
            $table->foreignId('object_id')->constrained('objects')->cascadeOnDelete();
            $table->primary(['chunk_id', 'object_id']);
        });

        Schema::create('ceremonies', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name_id');
            $table->string('name_en');
            $table->string('type')->default('hari_raya');
            $table->date('date_start');
            $table->date('date_end')->nullable();
            $table->text('summary_id')->nullable();
            $table->text('summary_en')->nullable();
            $table->text('tips_id');
            $table->text('tips_en');
            $table->text('road_note_id')->nullable();
            $table->text('road_note_en')->nullable();
            $table->string('status')->default('draft');
            $table->boolean('ai_drafted')->default(false);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ceremony_source', function (Blueprint $table) {
            $table->foreignId('ceremony_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->primary(['ceremony_id', 'source_id']);
        });

        Schema::create('calendar_rules', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name_id');
            $table->string('name_en');
            $table->string('rule_type');
            $table->json('activity_tags');
            $table->json('conditions');
            $table->string('effect');
            $table->unsignedInteger('weight')->default(1);
            $table->foreignId('source_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('draft');
            $table->string('validated_by')->nullable();
            $table->string('rules_version')->default('2026.1');
            $table->timestamps();
        });

        Schema::create('sasih_calendar', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->string('sasih');
            $table->string('penanggal_pangelong');
            $table->boolean('is_purnama')->default(false);
            $table->boolean('is_tilem')->default(false);
            $table->json('observances')->nullable();
            $table->foreignId('source_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('scans', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_id')->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('object_id')->nullable()->constrained('objects')->nullOnDelete();
            $table->string('status');
            $table->decimal('confidence', 5, 4)->nullable();
            $table->string('image_hash', 64)->nullable();
            $table->string('image_path')->nullable();
            $table->string('lang', 8)->default('id');
            $table->string('model')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamps();
        });

        Schema::create('feedback', function (Blueprint $table) {
            $table->id();
            $table->string('request_id')->nullable();
            $table->string('feature');
            $table->foreignId('object_id')->nullable()->constrained('objects')->nullOnDelete();
            $table->string('rating');
            $table->string('comment', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('consents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('subject_type')->default('session');
            $table->string('subject_ref')->nullable();
            $table->string('purpose')->default('store_photo');
            $table->boolean('granted')->default(true);
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('image_path')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consents');
        Schema::dropIfExists('feedback');
        Schema::dropIfExists('scans');
        Schema::dropIfExists('sasih_calendar');
        Schema::dropIfExists('calendar_rules');
        Schema::dropIfExists('ceremony_source');
        Schema::dropIfExists('ceremonies');
        Schema::dropIfExists('chunk_object');
        Schema::dropIfExists('chunks');
        Schema::dropIfExists('objects');
        Schema::dropIfExists('sources');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'last_login_at']);
        });
    }
};
