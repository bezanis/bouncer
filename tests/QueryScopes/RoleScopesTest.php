<?php

namespace Silber\Bouncer\Tests\QueryScopes;

use Silber\Bouncer\Tests\User;
use Silber\Bouncer\Database\Role;
use Silber\Bouncer\Tests\BaseTestCase;

class RoleScopesTest extends BaseTestCase
{
    /**
     * @test
     */
    function roles_can_be_constrained_by_a_user()
    {
        $bouncer = $this->bouncer($user = User::create());

        Role::create(['name' => 'admin']);
        Role::create(['name' => 'editor']);
        Role::create(['name' => 'manager']);
        Role::create(['name' => 'subscriber']);

        $bouncer->assign('admin')->to($user);
        $bouncer->assign('manager')->to($user);

        $roles = Role::whereAssignedTo($user)->get();

        $this->assertCount(2, $roles);
        $this->assertTrue($roles->contains('name', 'admin'));
        $this->assertTrue($roles->contains('name', 'manager'));
        $this->assertFalse($roles->contains('name', 'editor'));
        $this->assertFalse($roles->contains('name', 'subscriber'));
    }

    /**
     * @test
     */
    function roles_can_be_constrained_by_a_collection_of_users()
    {
        $user1 = User::create();
        $user2 = User::create();

        $bouncer = $this->bouncer($user1);

        Role::create(['name' => 'admin']);
        Role::create(['name' => 'editor']);
        Role::create(['name' => 'manager']);
        Role::create(['name' => 'subscriber']);

        $bouncer->assign('editor')->to($user1);
        $bouncer->assign('manager')->to($user1);
        $bouncer->assign('subscriber')->to($user2);

        $roles = Role::whereAssignedTo(User::all())->get();

        $this->assertCount(3, $roles);
        $this->assertTrue($roles->contains('name', 'manager'));
        $this->assertTrue($roles->contains('name', 'editor'));
        $this->assertTrue($roles->contains('name', 'subscriber'));
        $this->assertFalse($roles->contains('name', 'admin'));
    }

    /**
     * @test
     */
    function roles_can_be_constrained_by_a_model_name_and_keys()
    {
        $user1 = User::create();
        $user2 = User::create();

        $bouncer = $this->bouncer($user1);

        Role::create(['name' => 'admin']);
        Role::create(['name' => 'editor']);
        Role::create(['name' => 'manager']);
        Role::create(['name' => 'subscriber']);

        $bouncer->assign('editor')->to($user1);
        $bouncer->assign('manager')->to($user1);
        $bouncer->assign('subscriber')->to($user2);

        $roles = Role::whereAssignedTo(User::class, User::all()->modelKeys())->get();

        $this->assertCount(3, $roles);
        $this->assertTrue($roles->contains('name', 'manager'));
        $this->assertTrue($roles->contains('name', 'editor'));
        $this->assertTrue($roles->contains('name', 'subscriber'));
        $this->assertFalse($roles->contains('name', 'admin'));
    }


    /**
     * @test
     */
    function roles_can_be_constrained_to_an_entity_for_a_model()
    {
        $user = User::create();
        $bouncer = $this->bouncer($user);

        Role::create(['name' => 'user-viewer']);
        Role::create(['name' => 'user-editor']);

        $onUser2 = User::create();
        $onUser3 = User::create();
        //Single assigned role
        $bouncer->assign('user-editor')->to($user, $onUser2);
        $this->assertEquals(1, $this->db()->table('assigned_roles')->count());
        $this->assertFalse($bouncer->is($user)->on('user-booper', $onUser2));
        $this->assertFalse($bouncer->is($user)->on('user-editor', $onUser3));
        $this->assertTrue($bouncer->is($user)->on('user-editor', $onUser2));


        $user2 = User::create();
        $bouncer = $this->bouncer($user2);
        $onUser4 = User::create();
        $onUser5 = User::create();
        $onUser6 = User::create();
        //Array of assigned roles
        $bouncer->assign(['user-viewer', 'user-editor'])->to($user2, [$onUser4, $onUser5]);
        $this->assertEquals(5, $this->db()->table('assigned_roles')->count());
        $this->assertFalse($bouncer->is($user2)->on('user-booper', $onUser6));
        $this->assertFalse($bouncer->is($user2)->on('user-viewer', $onUser6));
        $this->assertTrue($bouncer->is($user2)->on('user-viewer', $onUser4));
        $this->assertTrue($bouncer->is($user2)->on('user-editor', $onUser4));
        $this->assertTrue($bouncer->is($user2)->on('user-viewer', $onUser5));
        $this->assertTrue($bouncer->is($user2)->on('user-editor', $onUser5));


        $bouncer->assign('user-editor')->to($user, $onUser4);

        $this->assertEquals(6, $this->db()->table('assigned_roles')->count());


        $user3 = User::create();
        $bouncer = $this->bouncer($user3);
        $onUser7 = User::create();
        $onUser8 = User::create();
        $onUser9 = User::create();
        //Collection of assigned roles
        $bouncer->assign(collect(['user-viewer', 'user-editor']))->to($user3, collect([$onUser7, $onUser8]));
        $this->assertEquals(10, $this->db()->table('assigned_roles')->count());
        $this->assertFalse($bouncer->is($user3)->on('user-booper', $onUser9));
        $this->assertFalse($bouncer->is($user3)->on('user-viewer', $onUser9));
        $this->assertTrue($bouncer->is($user3)->on('user-viewer', $onUser7));
        $this->assertTrue($bouncer->is($user3)->on('user-editor', $onUser7));
        $this->assertTrue($bouncer->is($user3)->on('user-viewer', $onUser8));
        $this->assertTrue($bouncer->is($user3)->on('user-editor', $onUser8));

        $bouncer->assign(collect(['user-viewer', 'user-editor']))->to($user2, collect([$onUser7, $onUser8]));
        $this->assertEquals(14, $this->db()->table('assigned_roles')->count());
    }
}
