<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // --- resources ---
        Schema::create('resources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->string('type', 40);
            $table->unsignedInteger('capacity')->default(1);
            $table->string('timezone', 40)->default('UTC');
            $table->jsonb('opening_hours')->nullable();
            $table->timestamps();
        });

        // --- reservations ---
        Schema::create('reservations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('resource_id')->constrained('resources')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('start_at');
            $table->timestampTz('end_at');
            $table->string('status', 20)->default('held');
            $table->timestampTz('expires_at')->nullable();
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->timestamps();

            $table->index(['resource_id', 'start_at']);
        });

        // time_range generated column
        DB::statement(<<<'SQL'
            ALTER TABLE reservations
            ADD COLUMN time_range tstzrange
            GENERATED ALWAYS AS (tstzrange(start_at, end_at, '[)')) STORED
        SQL);

        // exclusion constraint: no overlapping held/confirmed for same resource
        DB::statement(<<<'SQL'
            ALTER TABLE reservations
            ADD CONSTRAINT reservations_no_overlap
            EXCLUDE USING gist (
                resource_id WITH =,
                time_range WITH &&
            ) WHERE (status IN ('held', 'confirmed'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE reservations
            ADD CONSTRAINT reservations_valid_range
            CHECK (start_at < end_at)
        SQL);

        // partial index for active holds
        DB::statement(<<<'SQL'
            CREATE INDEX idx_reservations_held_active
            ON reservations (resource_id, expires_at)
            WHERE status = 'held'
        SQL);

        // --- waitlist_entries ---
        Schema::create('waitlist_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('resource_id')->constrained('resources')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestampTz('desired_start');
            $table->timestampTz('desired_end');
            $table->string('status', 20)->default('waiting');
            $table->timestamps();

            $table->index(['resource_id', 'created_at']);
        });

        DB::statement(<<<'SQL'
            CREATE INDEX idx_waitlist_resource_created
            ON waitlist_entries (resource_id, created_at)
            WHERE status = 'waiting'
        SQL);

        // --- idempotency_keys ---
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->string('request_hash', 64);
            $table->unsignedSmallInteger('response_status');
            $table->jsonb('response_body')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('expires_at');

            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('waitlist_entries');
        Schema::dropIfExists('reservations');
        Schema::dropIfExists('resources');
    }
};
