<?php

namespace Silber\Bouncer\Database\Queries;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Schema;
use Silber\Bouncer\Database\Models;
use Illuminate\Database\Eloquent\Model;

class Abilities
{
    /**
     * Get a query for the authority's abilities.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $authority
     * @param  bool  $allowed
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function forAuthority(Model $authority, $allowed = true, $abilities = null, $modelRestriction = null)
    {
        $abilitiesTable = Models::table('abilities');
        $abilityColumns = Schema::getColumnListing(Models::ability()->getTable());
        foreach ($abilityColumns as $k => $abilityColumn) {
            $abilityColumns[$k] = $abilitiesTable . '.' . $abilityColumn . ' as ' . $abilityColumn;
        }

        $abilitiesThroughRoles = self::abilitiesThroughRoles($authority, $allowed, $abilityColumns, $abilities, $modelRestriction);
        $existsThroughAuthority = Models::ability()->whereExists(static::getAuthorityConstraint($authority, $allowed, $abilities, $modelRestriction))->select($abilityColumns)->byName($abilities);
        $existsEveryone = Models::ability()->whereExists(static::getEveryoneConstraint($allowed))->select($abilityColumns)->byName($abilities);

        return $abilitiesThroughRoles->union($existsThroughAuthority)->union($existsEveryone);
    }

    /**
     * Get a query for the authority's forbidden abilities.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $authority
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function forbiddenForAuthority(Model $authority)
    {
        return static::forAuthority($authority, false);
    }

    /**
     * Get abilities that have been granted to the given authority through a role.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $authority
     * @param  bool  $allowed
     * @param array|null
     * @return Builder
     */
    protected static function abilitiesThroughRoles(Model $authority, $allowed, $abilityColumns, $abilities=null, $modelRestriction = null)
    {
        $assignedRolesTable = Models::table('assigned_roles');
        $permissionsTable = Models::table('permissions');
        $abilitiesTable = Models::table('abilities');
        $rolesTable = Models::table('roles');
        $authorityTable = $authority->getTable(); //users

        $query = Models::ability()->from($abilitiesTable)
            ->join($permissionsTable, $permissionsTable . '.ability_id', '=', $abilitiesTable . '.id')
            ->whereColumn("{$permissionsTable}.ability_id", "{$abilitiesTable}.id")
            ->where($permissionsTable . ".forbidden", !$allowed)
            ->where($permissionsTable . ".entity_type", Models::role()->getMorphClass())
            ->join($rolesTable, $rolesTable . '.id', '=', $permissionsTable . '.entity_id');

        Models::scope()->applyToModelQuery($query, $rolesTable);
        Models::scope()->applyToRelationQuery($query, $permissionsTable);
        $query->join($assignedRolesTable, "{$assignedRolesTable}.role_id", '=', $rolesTable . '.id');
        $query->join($authorityTable.' as authority', 'authority' . '.id', '=', $assignedRolesTable . '.entity_id')
            ->where("{$assignedRolesTable}.entity_type", $authority->getMorphClass())
            ->where("authority.{$authority->getKeyName()}", $authority->getKey());

        if ($modelRestriction) {
            if (is_string($modelRestriction)) {
                $query->whereNull('restricted_to_id');
                $query->where('restricted_to_type', $modelRestriction);
            } else {
                $query->addSelect('restricted_to_id');
                $query->where('restricted_to_id', $modelRestriction->id);
                $query->where('restricted_to_type', get_class($modelRestriction));
            }
            $query->addSelect('restricted_to_type');
        } else if ($abilities) {
            $query->whereNull('restricted_to_id');
            $query->whereNull('restricted_to_type');
        }

        Models::scope()->applyToRelationQuery($query, $assignedRolesTable);
        $abilityColumnsThroughRoles = $abilityColumns;
        foreach ($abilityColumnsThroughRoles as $k => $abilityColumnsThroughRole) {
            if ($abilityColumnsThroughRole == $abilitiesTable . '.' . 'entity_id as entity_id') {
                $abilityColumnsThroughRoles[$k] = 'restricted_to_id as entity_id';
            } else if ($abilityColumnsThroughRole == $abilitiesTable . '.' . 'entity_type as entity_type') {
                $abilityColumnsThroughRoles[$k] = 'restricted_to_type as entity_type';
            }
        }

        $query->select($abilityColumnsThroughRoles)
            ->byName($abilities);

        return $query;
    }

    /**
     * Get a constraint for roles that are assigned to the given authority.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $authority
     * @return \Closure
     */
    protected static function getAuthorityRoleConstraint(Model $authority, $allowed, $abilities, $modelRestriction = null)
    {
        return function ($query) use ($authority, $allowed, $abilities, $modelRestriction) {

            $assignedRolesTable  = Models::table('assigned_roles');
            $rolesTable  = Models::table('roles');
            $authorityTable  = $authority->getTable();

            $query->from($authorityTable)
                  ->join($assignedRolesTable, "{$authorityTable}.{$authority->getKeyName()}", '=', $assignedRolesTable.'.entity_id')
                  ->whereColumn("{$assignedRolesTable}.role_id", "{$rolesTable}.id")
                  ->where($assignedRolesTable.'.entity_type', $authority->getMorphClass())
                  ->where("{$authorityTable}.{$authority->getKeyName()}", $authority->getKey());

            if ($abilities) {
                $query->byName($abilities);
            }

            if($modelRestriction){
                if (is_string($modelRestriction)) {
                    $query->whereNull('restricted_to_id');
                    $query->where('restricted_to_type', $modelRestriction);
                    $query->addSelect('restricted_to_type');
                } else {
                    $query->addSelect('restricted_to_id');
                    $query->addSelect('restricted_to_type');
                    $query->where('restricted_to_id', $modelRestriction->id);
                    $query->where('restricted_to_type', get_class($modelRestriction));
                }
            } else if($abilities) {
                $query->whereNull('restricted_to_id');
                $query->whereNull('restricted_to_type');
            }

            Models::scope()->applyToModelQuery($query, $rolesTable);
            Models::scope()->applyToRelationQuery($query, $assignedRolesTable);
        };
    }

    /**
     * Get a constraint for abilities that have been granted to the given authority.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $authority
     * @param  bool  $allowed
     * @return \Closure
     */
    protected static function getAuthorityConstraint(Model $authority, $allowed, $abilities, $modelRestriction = null)
    {
        return function ($query) use ($authority, $allowed, $abilities, $modelRestriction) {
            $permissionsTable = Models::table('permissions');
            $abilitiesTable   = Models::table('abilities');
            $authorityTable       = $authority->getTable();

            $query->from($authorityTable)
                  ->join($permissionsTable, "{$authorityTable}.{$authority->getKeyName()}", '=', $permissionsTable.'.entity_id')
                  ->whereColumn("{$permissionsTable}.ability_id", "{$abilitiesTable}.id")
                  ->where("{$permissionsTable}.forbidden", ! $allowed)
                  ->where("{$permissionsTable}.entity_type", $authority->getMorphClass())
                  ->where("{$authorityTable}.{$authority->getKeyName()}", $authority->getKey());

            $query->where(function ($query2) use ($abilitiesTable, $abilities, $modelRestriction) {
                $query2->where(function ($query3) use ($abilitiesTable, $abilities, $modelRestriction) {
                    if($modelRestriction){
                        if (is_string($modelRestriction)) {
                            $query3->whereNull("{$abilitiesTable}.entity_id");
                            $query3->where("{$abilitiesTable}.entity_type", $modelRestriction);
                        } else {
                            $query3->where(function ($query4) use ($abilitiesTable, $modelRestriction) {
                                $query4->where("{$abilitiesTable}.entity_id", $modelRestriction->id)->orWhereNull("{$abilitiesTable}.entity_id");
                            });
                            $query3->where("{$abilitiesTable}.entity_type", get_class($modelRestriction));
                        }
                    } else if($abilities) {
                       $query3->whereNull("{$abilitiesTable}.entity_id");
                       $query3->whereNull("{$abilitiesTable}.entity_type");

                    }
                });
                if($modelRestriction || $abilities) {
                    $query2->orWhere("{$abilitiesTable}.entity_type", '*');
                }
            });

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
            $abilitiesTable   = Models::table('abilities');

            $query->from($permissionsTable)
                  ->whereColumn("{$permissionsTable}.ability_id", "{$abilitiesTable}.id")
                  ->where("{$permissionsTable}.forbidden", ! $allowed)
                  ->whereNull('entity_id');

            Models::scope()->applyToRelationQuery($query, $permissionsTable);
        };
    }
}
