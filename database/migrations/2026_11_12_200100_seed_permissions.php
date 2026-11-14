<?php

use App\Enums\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        foreach (Permission::cases() as $permission) {
            DB::table('permissions')->insertOrIgnore([
                'key' => $permission->value,
                'label' => $permission->label(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('permissions')->whereIn('key', array_map(fn (Permission $p) => $p->value, Permission::cases()))->delete();
    }
};
