<?php

namespace Silber\Bouncer\Database\Queries;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Silber\Bouncer\Database\Models;

class Abilities
{
    /**
     * Get a query for the authority's abilities.
     *
     * @param  bool  $allowed
     * @return Builder
     */
    public static function forAuthority(Model $authority, $allowed = true, $abilities = null, $modelRestriction = null)
    {
        $abilitiesTable = Models::table('abilities');
        $abilityColumns = Models::ability()->getConnection()->getSchemaBuilder()->getColumnListing($abilitiesTable);
        foreach ($abilityColumns as $k => $abilityColumn) {
            $sourceTable = in_array($abilityColumn, ['entity_id', 'entity_type'], true)
                ? 'bouncer_effective_abilities'
                : $abilitiesTable;

            $abilityColumns[$k] = $sourceTable.'.'.$abilityColumn.' as '.$abilityColumn;
        }

        $effectiveColumns = [
            $abilitiesTable.'.id as id',
            $abilitiesTable.'.entity_id as entity_id',
            $abilitiesTable.'.entity_type as entity_type',
        ];

        $abilitiesThroughRoles = static::abilitiesThroughRoles($authority, $allowed, $effectiveColumns, $abilities, $modelRestriction);
        $existsThroughAuthority = Models::ability()->whereExists(static::getAuthorityConstraint($authority, $allowed, $abilities, $modelRestriction))->select($effectiveColumns)->byName($abilities);
        $existsEveryone = Models::ability()->whereExists(static::getEveryoneConstraint($allowed))->select($effectiveColumns)->byName($abilities);

        $effectiveAbilities = $abilitiesThroughRoles->union($existsThroughAuthority)->union($existsEveryone);

        $abilitiesSubquery = Models::ability()
            ->from($abilitiesTable)
            ->joinSub(
                $effectiveAbilities,
                'bouncer_effective_abilities',
                'bouncer_effective_abilities.id',
                '=',
                $abilitiesTable.'.id'
            )
            ->select($abilityColumns);

        $query = Models::ability()->fromSub(
            $abilitiesSubquery,
            $abilitiesTable
        )->byName($abilities);

        if (! is_null($modelRestriction)) {
            $query->forModel($modelRestriction);
        } elseif (! is_null($abilities)) {
            $query->where(function ($query) use ($abilitiesTable) {
                $query->whereNull($abilitiesTable.'.entity_type')
                    ->orWhere($abilitiesTable.'.entity_type', '*');
            });
        }

        return $query;
    }

    /**
     * Get a query for the authority's forbidden abilities.
     *
     * @return Builder
     */
    public static function forbiddenForAuthority(Model $authority)
    {
        return static::forAuthority($authority, false);
    }

    /**
     * Get abilities that have been granted to the given authority through a role.
     *
     * @param  bool  $allowed
     * @param  array  $abilityColumns
     * @return Builder
     */
    protected static function abilitiesThroughRoles(Model $authority, $allowed, $abilityColumns, $abilities = null, $modelRestriction = null)
    {
        $assignedRolesTable = Models::table('assigned_roles');
        $permissionsTable = Models::table('permissions');
        $abilitiesTable = Models::table('abilities');
        $rolesTable = Models::table('roles');
        $authorityTable = $authority->getTable();

        $query = Models::ability()->from($abilitiesTable)
            ->join($permissionsTable, $permissionsTable.'.ability_id', '=', $abilitiesTable.'.id')
            ->where($permissionsTable.'.forbidden', ! $allowed)
            ->where($permissionsTable.'.entity_type', Models::role()->getMorphClass())
            ->join($rolesTable, $rolesTable.'.id', '=', $permissionsTable.'.entity_id');

        Models::scope()->applyToModelQuery($query, $rolesTable);
        Models::scope()->applyToRelationQuery($query, $permissionsTable);
        $query->join($assignedRolesTable, "{$assignedRolesTable}.role_id", '=', $rolesTable.'.id');
        $query->join($authorityTable.' as authority', 'authority.'.$authority->getKeyName(), '=', $assignedRolesTable.'.entity_id')
            ->where("{$assignedRolesTable}.entity_type", $authority->getMorphClass())
            ->where("authority.{$authority->getKeyName()}", $authority->getKey());

        Models::scope()->applyToRelationQuery($query, $assignedRolesTable);
        $abilityColumnsThroughRoles = $abilityColumns;
        $grammar = $query->getQuery()->getGrammar();
        $restrictedType = $grammar->wrap($assignedRolesTable.'.restricted_to_type');
        $restrictedId = $grammar->wrap($assignedRolesTable.'.restricted_to_id');
        $bindings = [];

        if (Relation::morphMap()) {
            $restrictedType = 'case '.$restrictedType;

            foreach (Relation::morphMap() as $alias => $class) {
                $restrictedType .= ' when ? then ?';
                $bindings[] = $class;
                $bindings[] = (string) $alias;
            }

            $restrictedType .= ' else '.$grammar->wrap($assignedRolesTable.'.restricted_to_type').' end';
        }

        $originalType = $grammar->wrap($abilitiesTable.'.entity_type');
        $originalId = $grammar->wrap($abilitiesTable.'.entity_id');
        $restrictionTypeColumn = $grammar->wrap($assignedRolesTable.'.restricted_to_type');
        $roleBased = $grammar->wrap($abilitiesTable.'.role_based');

        // Only blanket role-based abilities become global, and only when
        // the role assignment has no entity restriction.
        $globalRoleAbility = $roleBased.' = 1'
            .' and '.$originalId.' is null'
            .' and '.$restrictionTypeColumn.' is null'
            .' and '.$restrictedId.' is null';

        foreach ($abilityColumnsThroughRoles as $k => $column) {
            $abilityColumnsThroughRoles[$k] = match ($column) {
                $abilitiesTable.'.entity_id as entity_id' =>
                    'case when '.$restrictionTypeColumn.' is null'
                    .' then '.$originalId
                    .' else coalesce('.$restrictedId.', '.$originalId.')'
                    .' end as '.$grammar->wrap('entity_id'),

                $abilitiesTable.'.entity_type as entity_type' =>
                    'case when '.$globalRoleAbility.' then null'
                    .' when '.$restrictionTypeColumn.' is null then '.$originalType
                    .' else '.$restrictedType
                    .' end as '.$grammar->wrap('entity_type'),

                default => $grammar->wrap($column),
            };
        }

        $query->where(function ($query) use ($abilitiesTable, $assignedRolesTable, $restrictedType, $bindings) {
            $query->whereNull($assignedRolesTable.'.restricted_to_type')
                ->orWhereNull($abilitiesTable.'.entity_type')
                ->orWhere($abilitiesTable.'.entity_type', '*')
                ->orWhereRaw($query->getQuery()->getGrammar()->wrap($abilitiesTable.'.entity_type').' = '.$restrictedType, $bindings);
        })->where(function ($query) use ($abilitiesTable, $assignedRolesTable) {
            $query->whereNull($assignedRolesTable.'.restricted_to_id')
                ->orWhereNull($abilitiesTable.'.entity_id')
                ->orWhereColumn($abilitiesTable.'.entity_id', $assignedRolesTable.'.restricted_to_id');
        });

        $query->selectRaw(implode(', ', $abilityColumnsThroughRoles), $bindings)->byName($abilities);

        return $query;
    }

    /**
     * Get a constraint for abilities that have been granted to the given authority.
     *
     * @param  bool  $allowed
     * @return \Closure
     */
    protected static function getAuthorityConstraint(Model $authority, $allowed, $abilities, $modelRestriction = null)
    {
        return function ($query) use ($authority, $allowed) {
            $permissionsTable = Models::table('permissions');
            $abilitiesTable = Models::table('abilities');
            $authorityTable = $authority->getTable();

            $query->from($authorityTable)
                ->join($permissionsTable, "{$authorityTable}.{$authority->getKeyName()}", '=', $permissionsTable.'.entity_id')
                ->whereColumn("{$permissionsTable}.ability_id", "{$abilitiesTable}.id")
                ->where("{$permissionsTable}.forbidden", ! $allowed)
                ->where("{$permissionsTable}.entity_type", $authority->getMorphClass())
                ->where("{$authorityTable}.{$authority->getKeyName()}", $authority->getKey());

            Models::scope()->applyToModelQuery($query, $abilitiesTable);
            Models::scope()->applyToRelationQuery($query, $permissionsTable);
        };
    }

    /**
     * Get a constraint for abilities that have been granted to everyone.
     *
     * @param  bool  $allowed
     * @return \Closure
     */
    protected static function getEveryoneConstraint($allowed)
    {
        return function ($query) use ($allowed) {
            $permissionsTable = Models::table('permissions');
            $abilitiesTable = Models::table('abilities');

            $query->from($permissionsTable)
                ->whereColumn("{$permissionsTable}.ability_id", "{$abilitiesTable}.id")
                ->where("{$permissionsTable}.forbidden", ! $allowed)
                ->whereNull('entity_id');

            Models::scope()->applyToRelationQuery($query, $permissionsTable);
        };
    }
}
