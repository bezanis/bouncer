<?php

namespace Silber\Bouncer\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Silber\Bouncer\BouncerServiceProvider;
use Silber\Bouncer\Database\Models;
use Workbench\App\Models\User;

class UpgradeMigrationTest extends BaseTestCase
{
    #[Test]
    public function upgrades_upstream_tables_without_losing_grants()
    {
        $bouncer = $this->bouncer($user = User::create());
        $bouncer->allow('editor')->to('edit-site');
        $bouncer->assign('editor')->to($user);

        Schema::table('abilities', function (Blueprint $table) {
            $table->dropColumn('role_based');
        });
        Schema::table('assigned_roles', function (Blueprint $table) {
            $table->dropColumn(['restricted_to_id', 'restricted_to_type']);
            $table->dropIndex('assigned_roles_entity_index');
        });
        Schema::table('permissions', function (Blueprint $table) {
            $table->dropIndex('permissions_entity_index');
        });

        $migration = require __DIR__.'/../migrations/upgrades/update_bouncer_tables.php';
        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasColumns('assigned_roles', ['restricted_to_id', 'restricted_to_type']));
        $this->assertTrue(Schema::hasColumn('abilities', 'role_based'));
        $this->assertTrue(Schema::hasIndex('assigned_roles', 'assigned_roles_entity_index'));
        $this->assertTrue(Schema::hasIndex('permissions', 'permissions_entity_index'));
        $this->assertTrue($bouncer->can('edit-site'));
        $this->assertSame(1, $this->db()->table('assigned_roles')->count());
    }

    #[Test]
    public function preserves_existing_fork_restrictions_and_rejects_destructive_rollback()
    {
        $bouncer = $this->bouncer($user = User::create());
        $target = User::create();
        $bouncer->allow('editor')->to('edit-person', null, ['role_based' => true]);
        $bouncer->assign('editor')->to($user, $target);

        $migration = require __DIR__.'/../migrations/upgrades/update_bouncer_tables.php';
        $migration->up();
        $migration->up();

        $this->assertTrue($bouncer->can('edit-person', $target));
        $this->assertFalse($bouncer->can('edit-person', $user));
        $this->assertSame($target->getKey(), $this->db()->table('assigned_roles')->value('restricted_to_id'));

        $this->expectException(LogicException::class);
        $migration->down();
    }

    #[Test]
    public function registers_upgrade_migration_publish_tag_and_keeps_mysql_sql_aligned()
    {
        $provider = new BouncerServiceProvider($this->app);
        $provider->boot();

        $published = ServiceProvider::pathsToPublish(BouncerServiceProvider::class, 'bouncer.upgrade');
        $this->assertCount(1, $published);
        $this->assertFileExists(array_key_first($published));

        $sql = file_get_contents(__DIR__.'/../migrations/sql/MySQL.sql');
        $this->assertStringContainsString('`role_based` tinyint(1) not null default \'0\'', $sql);
        $this->assertStringContainsString('`restricted_to_id` bigint unsigned null', $sql);
        $this->assertStringContainsString('`restricted_to_type` varchar(255) null', $sql);
        $this->assertStringContainsString('`abilities_entity_id_entity_type_index`(`entity_id`, `entity_type`)', $sql);
        $this->assertStringContainsString('`assigned_roles_entity_index`(`entity_id`, `entity_type`, `scope`)', $sql);
        $this->assertStringContainsString('`permissions_entity_index`(`entity_id`, `entity_type`, `scope`)', $sql);
        $this->assertStringNotContainsString('`level`', $sql);

        Models::reset();
    }
}
