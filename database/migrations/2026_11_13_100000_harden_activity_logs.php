<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Security report P1-07 / P1-08.
 * - Log ids (user, actor, device) become plain references: no foreign key, so deleting a device or a user never
 *   tries to UPDATE the append-only log (ON DELETE SET NULL was refused by the trigger).
 * - DELETE is allowed only to the table owner with the purge flag, i.e. through purge_activity_logs()
 *   (SECURITY DEFINER, owned by the migration role). The runtime role (DB_APP_ROLE) is not the owner:
 *   setting the flag is useless to it. TRUNCATE is refused by a statement trigger.
 * - When DB_APP_ROLE is set, it receives the runtime privileges: no UPDATE/DELETE/TRUNCATE on activity_logs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropForeign(['actor_id']);
            $table->dropForeign(['device_id']);
            $table->index('actor_id');
        });

        $schema = (string) DB::scalar('SELECT current_schema()');

        $quotedSchema = '"'.str_replace('"', '""', $schema).'"';
        $purge = <<<'SQL'
CREATE OR REPLACE FUNCTION purge_activity_logs(cutoff timestamptz) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = __SCHEMA__, pg_catalog AS $$
DECLARE
    deleted bigint;
BEGIN
    PERFORM set_config('attendance.allow_log_purge', 'on', true);
    DELETE FROM activity_logs WHERE created_at < cutoff;
    GET DIAGNOSTICS deleted = ROW_COUNT;
    PERFORM set_config('attendance.allow_log_purge', 'off', true);
    RETURN deleted;
END;
$$
SQL;
        DB::statement(str_replace('__SCHEMA__', $quotedSchema, $purge));
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION activity_logs_append_only() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE'
        AND current_setting('attendance.allow_log_purge', true) = 'on'
        AND current_user = (SELECT pg_get_userbyid(relowner) FROM pg_class WHERE oid = TG_RELID) THEN
        RETURN OLD;
    END IF;
    RAISE EXCEPTION 'activity_logs is append-only';
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION activity_logs_no_truncate() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'activity_logs is append-only';
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER activity_logs_no_truncate BEFORE TRUNCATE ON activity_logs
    FOR EACH STATEMENT EXECUTE FUNCTION activity_logs_no_truncate();

REVOKE ALL ON FUNCTION purge_activity_logs(timestamptz) FROM PUBLIC;
SQL);

        $appRole = config('database.app_role');
        if (is_string($appRole) && $appRole !== '' && $appRole !== DB::scalar('SELECT current_user')) {
            $role = '"'.str_replace('"', '""', $appRole).'"';
            DB::statement("GRANT USAGE ON SCHEMA {$quotedSchema} TO {$role}");
            DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA {$quotedSchema} TO {$role}");
            DB::statement("GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA {$quotedSchema} TO {$role}");
            DB::statement("REVOKE UPDATE, DELETE, TRUNCATE ON activity_logs FROM {$role}");
            DB::statement("GRANT EXECUTE ON FUNCTION purge_activity_logs(timestamptz) TO {$role}");
            DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA {$quotedSchema} GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO {$role}");
            DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA {$quotedSchema} GRANT USAGE, SELECT ON SEQUENCES TO {$role}");
        }
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP FUNCTION IF EXISTS purge_activity_logs(timestamptz);
DROP TRIGGER IF EXISTS activity_logs_no_truncate ON activity_logs;
DROP FUNCTION IF EXISTS activity_logs_no_truncate();
CREATE OR REPLACE FUNCTION activity_logs_append_only() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' AND current_setting('attendance.allow_log_purge', true) = 'on' THEN
        RETURN OLD;
    END IF;
    RAISE EXCEPTION 'activity_logs is append-only';
END;
$$ LANGUAGE plpgsql;
ALTER TABLE activity_logs ADD CONSTRAINT activity_logs_user_id_foreign FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL NOT VALID;
ALTER TABLE activity_logs ADD CONSTRAINT activity_logs_actor_id_foreign FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL NOT VALID;
ALTER TABLE activity_logs ADD CONSTRAINT activity_logs_device_id_foreign FOREIGN KEY (device_id) REFERENCES devices (id) ON DELETE SET NULL NOT VALID;
DROP INDEX IF EXISTS activity_logs_actor_id_index;
SQL);
    }
};
