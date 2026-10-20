<?php

namespace Silber\Bouncer;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Silber\Bouncer\Database\Models;
use Silber\Bouncer\Database\Queries\Abilities;

abstract class BaseClipboard implements Contracts\Clipboard
{
    /**
     * Determine if the given authority has the given ability.
     *
     * @param  string  $ability
     * @param  Model|string|null  $model
     * @return bool
     */
    public function check(Model $authority, $ability, $model = null)
    {
        return ! is_null($id = $this->checkGetId($authority, $ability, $model)) && $id !== false;
    }

    /**
     * Check if an authority has the given roles.
     *
     * @param  array|string  $roles
     * @param  string  $boolean
     * @return bool
     */
    public function checkRole(Model $authority, $roles, $boolean = 'or')
    {
        $count = $this->countMatchingRoles($authority, $roles);

        if ($boolean == 'or') {
            return $count > 0;
        } elseif ($boolean === 'not') {
            return $count === 0;
        }

        return $count == count((array) $roles);
    }

    /**
     * Check if an authority has the given roles.
     *
     * @param  array|string  $roles
     * @param  string  $boolean
     * @return bool
     */
    public function checkRoleOn(Model $authority, $roles, $entities, $boolean = 'or')
    {
        $entities = collect(Helpers::toArray($entities));

        $assignedRoles = $authority->roles()->get([
            'name', Models::role()->getQualifiedKeyName(),
            'restricted_to_id',
            'restricted_to_type',
        ]);

        $roles = (array) $roles;
        $count = 0;

        foreach ($entities as $entity) {
            $entityId = $entity instanceof Model ? $entity->getKey() : null;
            $morphTypes = Helpers::getMorphTypes($entity);

            foreach ($roles as $role) {
                $matched = $assignedRoles->contains(function ($assignedRole) use ($role, $entityId, $morphTypes) {
                    $sameEntityId = is_null($entityId)
                        ? is_null($assignedRole->restricted_to_id)
                        : (! is_null($assignedRole->restricted_to_id) && (string) $assignedRole->restricted_to_id === (string) $entityId);

                    return $sameEntityId
                        && in_array($assignedRole->restricted_to_type, $morphTypes, true)
                        && $role == $assignedRole->name;
                });

                if ($matched) {
                    $count++;
                }
            }
        }

        if ($boolean == 'or') {
            return $count > 0;
        } elseif ($boolean === 'not') {
            return $count === 0;
        }

        return $count == (count($roles) * $entities->count());
    }

    /**
     * Count the authority's roles matching the given roles.
     *
     * @param  Model  $authority
     * @param  array|string  $roles
     * @return int
     */
    protected function countMatchingRoles($authority, $roles)
    {
        $lookups = $this->getRolesLookup($authority);

        return count(array_filter($roles, function ($role) use ($lookups) {
            switch (true) {
                case is_string($role):
                    return $lookups['names']->has($role);
                case is_numeric($role):
                    return $lookups['ids']->has($role);
                case $role instanceof Model:
                    return $lookups['ids']->has($role->getKey());
            }

            throw new InvalidArgumentException('Invalid model identifier');
        }));
    }

    /**
     * Get the given authority's roles' IDs and names.
     *
     * @return array
     */
    public function getRolesLookup(Model $authority)
    {
        $roles = $authority->roles()->get([
            'name', Models::role()->getQualifiedKeyName(),
        ])->pluck('name', Models::role()->getKeyName());

        return ['ids' => $roles, 'names' => $roles->flip()];
    }

    /**
     * Get the given authority's roles' names.
     *
     * @return Collection
     */
    public function getRoles(Model $authority)
    {
        return $this->getRolesLookup($authority)['names']->keys();
    }

    /**
     * Get a list of the authority's abilities.
     *
     * @param  bool  $allowed
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAbilities(Model $authority, $allowed = true, $abilities = null, $restrictionModel = null)
    {
        return Abilities::forAuthority($authority, $allowed, $abilities, $restrictionModel)->get();
    }

    /**
     * Get a list of the authority's forbidden abilities.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getForbiddenAbilities(Model $authority)
    {
        return $this->getAbilities($authority, false);
    }

    /**
     * Determine whether the authority owns the given model.
     *
     * @return bool
     */
    public function isOwnedBy($authority, $model)
    {
        return $model instanceof Model && Models::isOwnedBy($authority, $model);
    }
}
