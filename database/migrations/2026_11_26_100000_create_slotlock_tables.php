<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // EXCLUDE sobre `uuid` requiere el operator class de btree_gist.
        // Se crea acá (y no en docker/init.sql) para que las migraciones
        // funcionen igual en Docker, en CI y en un PostgreSQL gestionado.
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        // --- resources -----------------------------------------------------
        Schema::create('resources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->string('type', 40);
            // v1: solo recursos exclusivos. Compartidos (capacity > 1) requiere
            // otra estrategia (suma de unidades), no EXCLUDE.
            $table->unsignedInteger('capacity')->default(1);
            $table->string('timezone', 64)->default('UTC');
            $table->unsignedInteger('max_duration_minutes')->default(240);
            $table->jsonb('opening_hours')->nullable();
            $table->timestampsTz(6);
        });

        DB::statement(
            "ALTER TABLE resources
               ADD CONSTRAINT resources_type_valid CHECK (type IN ('room','court','table')),
               ADD CONSTRAINT resources_capacity_exclusive_v1 CHECK (capacity = 1),
               ADD CONSTRAINT resources_max_duration_valid CHECK (max_duration_minutes BETWEEN 15 AND 1440)"
        );

        // --- reservations --------------------------------------------------
        Schema::create('reservations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // RESTRICT: borrar un recurso no debe borrar el historial de reservas.
            $table->foreignUuid('resource_id')->constrained('resources')->restrictOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('start_at', 6);
            $table->timestampTz('end_at', 6);
            $table->string('status', 20)->default('held');
            $table->timestampTz('expires_at', 6)->nullable();
            $table->timestampsTz(6);

            $table->index(['resource_id', 'start_at']);
        });

        // Rango half-open [start, end). `time_range` es STORED para que el
        // índice GiST de la restricción también sirva las consultas de
        // disponibilidad por rango.
        DB::statement(<<<'SQL'
            ALTER TABLE reservations
            ADD COLUMN time_range tstzrange
            GENERATED ALWAYS AS (tstzrange(start_at, end_at, '[)')) STORED
        SQL);

        // La invariante del proyecto: no puede haber dos reservas `held` o
        // `confirmed` solapadas para el mismo recurso, sin importar la
        // concurrencia ni el nivel de aislamiento.
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
            ADD CONSTRAINT reservations_status_valid
                CHECK (status IN ('held', 'confirmed', 'cancelled', 'expired')),
            ADD CONSTRAINT reservations_valid_range
                CHECK (start_at < end_at),
            -- Un hold sin TTL bloquearía el slot para siempre.
            ADD CONSTRAINT reservations_held_has_ttl
                CHECK (status <> 'held' OR expires_at IS NOT NULL)
        SQL);

        // Barrido global de holds vencidos (el job de higiene).
        DB::statement(<<<'SQL'
            CREATE INDEX idx_reservations_held_expiry
            ON reservations (expires_at)
            WHERE status = 'held'
        SQL);

        // --- waitlist_entries ----------------------------------------------
        Schema::create('waitlist_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('resource_id')->constrained('resources')->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestampTz('desired_start', 6);
            $table->timestampTz('desired_end', 6);
            $table->string('status', 20)->default('waiting');
            $table->foreignUuid('promoted_reservation_id')->nullable()->constrained('reservations')->nullOnDelete();
            $table->timestampsTz(6);

            $table->index('promoted_reservation_id');
        });

        DB::statement(
            "ALTER TABLE waitlist_entries
               ADD CONSTRAINT waitlist_status_valid
                   CHECK (status IN ('waiting','notified','expired')),
               ADD CONSTRAINT waitlist_valid_range
                   CHECK (desired_start < desired_end)"
        );

        // Un usuario no puede encolarse dos veces por el mismo rango.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX idx_waitlist_unique_waiting
            ON waitlist_entries (resource_id, user_id, desired_start, desired_end)
            WHERE status = 'waiting'
        SQL);

        // FIFO determinista (created_at + id) para la promoción con SKIP LOCKED.
        DB::statement(<<<'SQL'
            CREATE INDEX idx_waitlist_fifo
            ON waitlist_entries (resource_id, created_at, id)
            WHERE status = 'waiting'
        SQL);

        // --- idempotency_keys ----------------------------------------------
        // Fuente de verdad única: la tabla (no hay columna duplicada en
        // reservations). `response_status` NULL = operación en curso.
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->string('endpoint', 80);
            $table->char('request_hash', 64);
            $table->smallInteger('response_status')->nullable();
            $table->jsonb('response_body')->nullable();
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->timestampTz('expires_at', 6);

            $table->index('expires_at');
        });

        DB::statement(
            'ALTER TABLE idempotency_keys
               ADD CONSTRAINT idempotency_status_valid
                   CHECK (response_status IS NULL OR response_status BETWEEN 100 AND 599),
               ADD CONSTRAINT idempotency_expiry_valid
                   CHECK (expires_at > created_at)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('waitlist_entries');
        Schema::dropIfExists('reservations');
        Schema::dropIfExists('resources');
    }
};
