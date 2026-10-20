<?php

namespace Silber\Bouncer\Tests;

use Illuminate\Cache\ArrayStore;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Silber\Bouncer\Bouncer;
use Silber\Bouncer\BouncerFacade;
use Silber\Bouncer\CachedClipboard;
use Silber\Bouncer\Clipboard;
use Silber\Bouncer\Guard;
use Workbench\App\Models\Account;
use Workbench\App\Models\User;

class EntityRoleChecksTest extends BaseTestCase
{
    #[Test]
    public function empty_retraction_selections_never_delete_other_assignments()
    {
        $bouncer = $this->bouncer($user = User::create());
        $other = User::create();
        $target = User::create();
        $bouncer->assign('editor')->to([$user, $other], [null, $target]);

        foreach ([[], collect()] as $entities) {
            $bouncer->retract('editor')->from($user, $entities);
            $this->assertSame(4, $this->db()->table('assigned_roles')->count());
        }

        $bouncer->retract('editor')->from([], $target);
        $this->assertSame(4, $this->db()->table('assigned_roles')->count());
    }

    #[Test]
    public function unsaved_entity_assignments_fail_before_any_role_or_pivot_is_created()
    {
        $bouncer = $this->bouncer($user = User::create());
        $target = User::create();

        try {
            $bouncer->assign('editor')->to($user, [$target, new User]);
            $this->fail('Assigning a role to an unsaved entity must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(0, $this->db()->table('roles')->count());
            $this->assertSame(0, $this->db()->table('assigned_roles')->count());
        }
    }

    #[Test]
    public function unsaved_entity_retractions_cannot_delete_class_wide_grants()
    {
        $bouncer = $this->bouncer($user = User::create());
        $target = User::create();
        $bouncer->assign('editor')->to($user, [User::class, $target]);

        try {
            $bouncer->retract('editor')->from($user, [$target, new User]);
            $this->fail('Retracting a role from an unsaved entity must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(2, $this->db()->table('assigned_roles')->count());
            $this->assertTrue($bouncer->is($user)->on('editor', User::class));
            $this->assertTrue($bouncer->is($user)->on('editor', $target));
        }
    }

    #[Test]
    public function distinct_role_and_authority_pairs_do_not_collide_during_duplicate_filtering()
    {
        $bouncer = $this->bouncer();
        $first = User::forceCreate(['id' => 3]);
        $second = User::forceCreate(['id' => 23]);
        $editor = $bouncer->role()->forceCreate(['id' => 1, 'name' => 'editor']);
        $manager = $bouncer->role()->forceCreate(['id' => 12, 'name' => 'manager']);

        $bouncer->assign($editor)->to($second);
        $bouncer->assign([$editor, $manager])->to([$first, $second]);

        $this->assertSame(4, $this->db()->table('assigned_roles')->count());
        $this->assertTrue($bouncer->is($first)->all('editor', 'manager'));
        $this->assertTrue($bouncer->is($second)->all('editor', 'manager'));
    }

    #[Test]
    public function role_checks_accept_entity_arrays_and_morph_map_aliases()
    {
        $morphMap = Relation::morphMap();
        Relation::morphMap(['restricted-user' => User::class], false);

        try {
            $authority = User::create();
            $firstEntity = User::create();
            $secondEntity = User::create();
            $otherEntity = User::create();
            $bouncer = $this->bouncer($authority);

            $bouncer->assign('entity-editor')->to($authority, [$firstEntity, $secondEntity]);

            $this->assertSame(
                ['restricted-user', 'restricted-user'],
                $this->db()->table('assigned_roles')->orderBy('restricted_to_id')->pluck('restricted_to_type')->all()
            );
            $this->assertTrue($bouncer->is($authority)->on('entity-editor', [$firstEntity, $secondEntity]));
            $this->assertTrue($bouncer->is($authority)->on('entity-editor', collect([$firstEntity, $secondEntity])));
            $this->assertFalse($bouncer->is($authority)->on('entity-editor', [$otherEntity]));

            $bouncer->assign('entity-class-reader')->to($authority, User::class);
            $this->assertTrue($bouncer->is($authority)->on('entity-class-reader', User::class));

            $bouncer->retract('entity-editor')->from($authority, [$firstEntity]);

            $this->assertFalse($bouncer->is($authority)->on('entity-editor', [$firstEntity]));
            $this->assertTrue($bouncer->is($authority)->on('entity-editor', [$secondEntity]));
        } finally {
            Relation::morphMap($morphMap, false);
        }
    }

    #[Test]
    public function cached_role_lookups_refresh_for_scalar_authorities_with_mixed_restrictions()
    {
        $authority = User::create();
        $firstEntity = User::create();
        $secondEntity = User::create();
        $bouncer = $this->bouncer($authority)
            ->setClipboard(new CachedClipboard(new ArrayStore));
        BouncerFacade::swap(new Bouncer(new Guard(new Clipboard)));

        try {
            $this->assertFalse($bouncer->is($authority)->a('entity-editor'));

            $bouncer->assign('entity-editor')->to($authority->getKey(), [$firstEntity, $secondEntity]);
            $bouncer->assign('entity-viewer')->to($authority->getKey());

            $this->assertTrue($bouncer->is($authority)->all('entity-editor', 'entity-viewer'));
            $this->assertTrue($bouncer->is($authority)->on('entity-editor', [$firstEntity, $secondEntity]));

            $bouncer->retract('entity-editor')->from($authority->getKey(), [$firstEntity, $secondEntity]);

            $this->assertFalse($bouncer->is($authority)->a('entity-editor'));
            $this->assertTrue($bouncer->is($authority)->a('entity-viewer'));
        } finally {
            BouncerFacade::swap($bouncer);
        }
    }

    #[Test]
    public function cached_ability_lookups_preserve_entity_restrictions()
    {
        $authority = User::create();
        $restrictedEntity = User::create();
        $otherEntity = User::create();
        $bouncer = $this->bouncer($authority)
            ->setClipboard(new CachedClipboard(new ArrayStore));
        BouncerFacade::swap($bouncer);

        $bouncer->allow('entity-editor')->to(
            'view-entity', null, ['title' => 'View Entity', 'role_based' => true]
        );
        $bouncer->assign('entity-editor')->to($authority, $restrictedEntity);

        $bouncer->getClipboard()->getAbilities($authority);

        $this->assertCount(
            1,
            $bouncer->getClipboard()->getAbilities($authority, true, 'view-entity', $restrictedEntity)
        );
        $this->assertCount(
            0,
            $bouncer->getClipboard()->getAbilities($authority, true, 'view-entity', $otherEntity)
        );
        $this->assertTrue($bouncer->can('view-entity', $restrictedEntity));
        $this->assertFalse($bouncer->can('view-entity', $otherEntity));
    }

    #[Test]
    public function cached_authorization_checks_reuse_authority_ability_lists()
    {
        $authority = User::create();
        $restrictedEntity = User::create();
        $otherEntity = User::create();
        $ownedAccount = Account::create(['user_id' => $authority->getKey()]);
        $bouncer = $this->bouncer($authority)
            ->setClipboard(new CachedClipboard(new ArrayStore));
        BouncerFacade::swap($bouncer);

        $bouncer->allow('entity-editor')->to(
            'view-entity', null, ['title' => 'View Entity', 'role_based' => true]
        );
        $bouncer->assign('entity-editor')->to($authority, $restrictedEntity);
        $bouncer->allow($authority)->toOwn(Account::class);
        $bouncer->allow($authority)->to('delete', $restrictedEntity);
        $bouncer->forbid($authority)->to('delete', $restrictedEntity);
        $bouncer->allow($authority)->to('simple-only');

        $connection = $this->db()->connection();
        $connection->enableQueryLog();

        $this->assertTrue($bouncer->can('view-entity', $restrictedEntity));
        $this->assertFalse($bouncer->can('view-entity', $otherEntity));
        $this->assertTrue($bouncer->can('view', $ownedAccount));
        $this->assertTrue($bouncer->cannot('delete', $restrictedEntity));
        $this->assertFalse($bouncer->can('simple-only', $otherEntity));

        $this->assertNotSame([], $connection->getQueryLog());
        $connection->flushQueryLog();

        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue($bouncer->can('view-entity', $restrictedEntity));
            $this->assertFalse($bouncer->can('view-entity', $otherEntity));
            $this->assertTrue($bouncer->can('view', $ownedAccount));
            $this->assertTrue($bouncer->cannot('delete', $restrictedEntity));
            $this->assertFalse($bouncer->can('simple-only', $otherEntity));
        }

        $this->assertSame([], $connection->getQueryLog());
    }

    #[Test]
    public function all_and_not_role_checks_count_array_entities()
    {
        $authority = User::create();
        $entities = [User::create(), User::create(), User::create()];
        $roles = ['entity-editor', 'entity-viewer'];
        $bouncer = $this->bouncer($authority);
        $clipboard = new Clipboard;

        foreach ($roles as $role) {
            $bouncer->assign($role)->to($authority, $entities);
        }

        $this->assertTrue($clipboard->checkRoleOn($authority, $roles, $entities, 'and'));
        $this->assertFalse($clipboard->checkRoleOn($authority, ['entity-editor', 'missing'], $entities, 'and'));
        $this->assertFalse($clipboard->checkRoleOn($authority, ['entity-editor'], $entities, 'not'));
        $this->assertTrue($clipboard->checkRoleOn($authority, ['missing'], $entities, 'not'));
    }

    #[Test]
    public function duplicate_entities_in_batch_assignment_and_database_do_not_corrupt_role_checks()
    {
        $authority = User::create();
        $firstEntity = User::create();
        $secondEntity = User::create();
        $bouncer = $this->bouncer($authority);

        $bouncer->assign(['editor', 'editor'])->to([$authority, $authority], [$firstEntity, $firstEntity]);

        $this->assertSame(1, $this->db()->table('assigned_roles')->count());
        $this->assertFalse($bouncer->is($authority)->on(['editor', 'admin'], $firstEntity, 'and'));

        // Even if duplicate rows exist in the database, a duplicate for one role must not satisfy a missing role or entity.
        $row = (array) $this->db()->table('assigned_roles')->first();
        unset($row['id']);
        $this->db()->table('assigned_roles')->insert($row);

        $this->assertSame(2, $this->db()->table('assigned_roles')->count());
        $this->assertFalse($bouncer->is($authority)->on(['editor', 'admin'], $firstEntity, 'and'));
        $this->assertFalse($bouncer->is($authority)->on('editor', [$firstEntity, $secondEntity], 'and'));
        $this->assertTrue($bouncer->is($authority)->on('editor', $firstEntity, 'and'));
    }
}
