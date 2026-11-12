<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Security report P1-15: purge_activity_logs() ignored the age of the entries, so a role allowed to EXECUTE it
 * could erase the whole log. The function now never deletes entries younger than a hard floor of 12 months
 * (a constant in the function, never read from settings, which the runtime role can update), and the runtime
 * role (DB_APP_ROLE) loses EXECUTE: the purge command runs on the owner connection.
 */
return new class extends Migration
{
    private function create(string $guard): void
    {
        $schema = '"'.str_replace('"', '""', (string) DB::scalar('SELECT current_schema()')).'"';
        $sql = <<<'SQL'
CREATE OR REPLACE FUNCTION purge_activity_logs(cutoff timestamptz) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = __SCHEMA__, pg_catalog AS $$
DECLARE
    deleted bigint;
BEGIN
    __GUARD__
    PERFORM set_config('attendance.allow_log_purge', 'on', true);
    DELETE FROM activity_logs WHERE created_at < cutoff;
    GET DIAGNOSTICS deleted = ROW_COUNT;
    PERFORM set_config('attendance.allow_log_purge', 'off', true);
    RETURN deleted;
END;
$$
SQL;
        DB::statement(str_replace(['__SCHEMA__', '__GUARD__'], [$schema, $guard], $sql));
        DB::statement('REVOKE ALL ON FUNCTION purge_activity_logs(timestamptz) FROM PUBLIC');
    }

    public function up(): void
    {
        $this->create("cutoff := LEAST(cutoff, now() - interval '12 months');");
        $appRole = config('database.app_role');
        if (is_string($appRole) && $appRole !== '' && $appRole !== DB::scalar('SELECT current_user')) {
            $role = '"'.str_replace('"', '""', $appRole).'"';
            DB::statement("REVOKE EXECUTE ON FUNCTION purge_activity_logs(timestamptz) FROM {$role}");
        }
    }

    public function down(): void
    {
        $this->create('');
        $appRole = config('database.app_role');
        if (is_string($appRole) && $appRole !== '' && $appRole !== DB::scalar('SELECT current_user')) {
            $role = '"'.str_replace('"', '""', $appRole).'"';
            DB::statement("GRANT EXECUTE ON FUNCTION purge_activity_logs(timestamptz) TO {$role}");
        }
    }
};
