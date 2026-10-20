<?php

namespace Silber\Bouncer\Tests;

use Illuminate\Database\Eloquent\Relations\Relation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Silber\Bouncer\Database\Models;
use Silber\Bouncer\Database\Scope\Scope;
use Workbench\App\Models\Account;
use Workbench\App\Models\User;

class EntityAbilityQueriesTest extends BaseTestCase
{
    use Concerns\TestsClipboards;

    protected function tearDown(): void
    {
        Models::scope(new Scope);

        parent::tearDown();
    }

    #[Test]
    #[DataProvider('bouncerProvider')]
    public function unrestricted_roles_preserve_model_ability_restrictions($provider)
    {
        [$bouncer, $user] = $provider();
        $target = User::create();
        $other = User::create();

        $bouncer->allow('editor')->to('edit', $target);
        $bouncer->assign('editor')->to($user);

        $this->assertTrue($bouncer->can('edit', $target));
        $this->assertFalse($bouncer->can('edit', $other));
        $this->assertFalse($bouncer->can('edit'));
        $this->assertFalse($bouncer->can('edit', User::class));
    }

    #[Test]
    #[DataProvider('bouncerProvider')]
    public function entity_role_abilities_support_morph_aliases($provider)
    {
        $morphMap = Relation::morphMap();

        try {
            Relation::morphMap(['person' => User::class]);
            [$bouncer, $user] = $provider();
            $target = User::create();
            $other = User::create();

            $bouncer->allow('editor')->to('edit-person', null, ['role_based' => true]);
            $bouncer->assign('editor')->to($user, $target);

            $this->assertTrue($bouncer->can('edit-person', $target));
            $this->assertFalse($bouncer->can('edit-person', $other));
            $this->assertFalse($bouncer->can('edit-person'));
        } finally {
            Relation::morphMap($morphMap, false);
        }
    }

    #[Test]
    #[DataProvider('bouncerProvider')]
    public function class_restricted_roles_cover_instances_but_not_other_types($provider)
    {
        [$bouncer, $user] = $provider();
        $target = User::create();

        $bouncer->allow('editor')->to('edit-person', null, ['role_based' => true]);
        $bouncer->assign('editor')->to($user, User::class);

        $this->assertTrue($bouncer->can('edit-person', User::class));
        $this->assertTrue($bouncer->can('edit-person', $target));
        $this->assertFalse($bouncer->can('edit-person', Account::class));
        $this->assertFalse($bouncer->can('edit-person'));
    }

    #[Test]
    #[DataProvider('bouncerProvider')]
    public function role_abilities_remain_isolated_between_tenants($provider)
    {
        [$bouncer, $user] = $provider();
        $target = User::create();

        $bouncer->scope()->to(1);
        $bouncer->allow('editor')->to('edit-person', null, ['role_based' => true]);
        $bouncer->assign('editor')->to($user, $target);
        $this->assertTrue($bouncer->can('edit-person', $target));

        $bouncer->scope()->to(2);
        $this->assertFalse($bouncer->can('edit-person', $target));

        $bouncer->scope()->to(1);
        $this->assertTrue($bouncer->can('edit-person', $target));
    }

    #[Test]
    #[DataProvider('bouncerProvider')]
    public function legacy_entity_restrictions_work_with_morph_aliases($provider)
    {
        $morphMap = Relation::morphMap();

        try {
            Relation::morphMap(['person' => User::class]);
            [$bouncer, $user] = $provider();
            $target = User::create();

            $bouncer->allow('editor')->to('edit-person', null, ['role_based' => true]);
            $bouncer->assign('editor')->to($user, $target);
            $this->db()->table('assigned_roles')->update(['restricted_to_type' => User::class]);

            $this->assertTrue($bouncer->can('edit-person', $target));
            $this->assertFalse($bouncer->can('edit-person', $user));
        } finally {
            Relation::morphMap($morphMap, false);
        }
    }

    #[Test]
    #[DataProvider('bouncerProvider')]
    public function role_assignment_cannot_broaden_an_abilitys_existing_restriction($provider)
    {
        [$bouncer, $user] = $provider();
        $target = User::create();
        $other = User::create();

        $bouncer->allow('editor')->to('edit', $target);
        $bouncer->assign('editor')->to($user, $other);

        $this->assertFalse($bouncer->can('edit', $other));
        $this->assertFalse($bouncer->can('edit', $target));
        $this->assertFalse($bouncer->can('edit'));
    }

    #[Test]
    #[DataProvider('bouncerProvider')]
    public function forbids_only_block_the_matching_entity_across_all_grant_sources($provider)
    {
        [$bouncer, $user] = $provider();
        $target = User::create();
        $other = User::create();

        $bouncer->allow('editor')->to('edit', User::class);
        $bouncer->assign('editor')->to($user);
        $bouncer->forbid($user)->to('edit', $target);

        $this->assertFalse($bouncer->can('edit', $target));
        $this->assertTrue($bouncer->can('edit', $other));

        $bouncer->forbidEveryone()->to('edit', $other);
        $bouncer->refreshFor($user);
        $this->assertFalse($bouncer->can('edit', $other));
    }

    #[Test]
    #[DataProvider('bouncerProvider')]
    public function unrestricted_role_based_abilities_match_models_in_both_clipboards($provider)
    {
        [$bouncer, $user] = $provider();
        $target = User::create();

        $bouncer->allow('editor')->to('edit-person', null, ['role_based' => true]);
        $bouncer->assign('editor')->to($user);

        $this->assertTrue($bouncer->can('edit-person', $target));
        $this->assertTrue($bouncer->can('edit-person', User::class));
        $this->assertTrue($bouncer->can('edit-person'));
        $this->assertFalse($bouncer->can('edit-person', '*'));
    }

    #[Test]
    #[DataProvider('bouncerProvider')]
    public function optional_argument_checks_retain_global_fallback_without_changing_can($provider)
    {
        [$bouncer, $user] = $provider();
        $target = User::create();
        $bouncer->allow($user)->to('edit-person');

        $this->assertFalse($bouncer->can('edit-person', $target));
        $this->assertTrue($bouncer->canWithOptionalArgs('edit-person', $target));
        $this->assertFalse($bouncer->cannotWithOptionalArgs('edit-person', $target));
        $this->assertTrue($bouncer->canWithOptionalArgs('edit-person'));
        $this->assertTrue($bouncer->cannotWithOptionalArgs('unknown', $target));
    }

    #[Test]
    public function authority_ability_union_excludes_json_options_column_and_deduplicates_grants()
    {
        $bouncer = $this->bouncer($user = User::create());
        $target = User::create();

        $bouncer->allow('editor')->to('edit-site');
        $bouncer->allow('manager')->to('edit-site');
        $bouncer->allow($user)->to('edit-site');
        $bouncer->allowEveryone()->to('edit-site');
        $bouncer->assign(['editor', 'manager'])->to($user);

        $bouncer->allow('entity-editor')->to('edit-person', null, ['role_based' => true]);
        $bouncer->assign('entity-editor')->to($user, $target);

        $sql = strtolower(\Silber\Bouncer\Database\Queries\Abilities::forAuthority($user)->toSql());

        // Ensure the UNION subquery only compares id/entity_id/entity_type and never includes `options` inside a UNION branch.
        $this->assertStringContainsString('union', $sql);
        $this->assertDoesNotMatchRegularExpression('/select[^()]*options[^()]*union/', $sql);

        $abilities = $user->getAbilities();
        $this->assertSame(['edit-person', 'edit-site'], $abilities->pluck('name')->sort()->values()->all());
    }

    #[Test]
    #[DataProvider('bouncerProvider')]
    public function falsey_ability_identifier_zero_is_authorized_and_forbiddable($provider)
    {
        [$bouncer, $user] = $provider();

        $zeroAbility = $bouncer->ability()->create(['name' => '0']);
        $bouncer->allow($user)->to($zeroAbility);

        $this->assertTrue($bouncer->can('0'));
        $this->assertSame(
            'Bouncer granted permission via ability #0',
            $bouncer->authorize('0')->message()
        );

        $bouncer->allow($user)->everything();
        $bouncer->forbid($user)->to($zeroAbility);
        $bouncer->refreshFor($user);

        $this->assertFalse($bouncer->can('0'));
        $this->assertTrue($bouncer->cannot('0'));
    }
}
