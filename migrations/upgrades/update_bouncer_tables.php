<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Silber\Bouncer\Database\Models;

return new class extends Migration
{
    /**
     * Add missing fork columns and upstream indexes without replacing existing data.
     */
    public function up(): void
    {
        if (! Schema::hasColumn(Models::table('abilities'), 'role_based')) {
            Schema::table(Models::table('abilities'), function (Blueprint $table) {
                $table->boolean('role_based')->default(false);
            });
        }

        if (! Schema::hasColumn(Models::table('assigned_roles'), 'restricted_to_id')) {
            Schema::table(Models::table('assigned_roles'), function (Blueprint $table) {
                $table->bigInteger('restricted_to_id')->unsigned()->nullable();
            });
        }

        if (! Schema::hasColumn(Models::table('assigned_roles'), 'restricted_to_type')) {
            Schema::table(Models::table('assigned_roles'), function (Blueprint $table) {
                $table->string('restricted_to_type')->nullable();
            });
        }

        foreach (['assigned_roles', 'permissions'] as $table) {
            $index = $table.'_entity_index';

            if (! Schema::hasIndex(Models::table($table), $index)) {
                Schema::table(Models::table($table), function (Blueprint $table) use ($index) {
                    $table->index(['entity_id', 'entity_type', 'scope'], $index);
                });
            }
        }
    }

    /**
     * Existing installations may have populated these columns before this migration.
     */
    public function down(): void
    {
        throw new LogicException('The Bouncer upgrade cannot be rolled back without risking existing entity-role data.');
    }
};
