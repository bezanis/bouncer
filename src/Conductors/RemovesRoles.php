<?php

namespace Silber\Bouncer\Conductors;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Silber\Bouncer\CachedClipboard;
use Silber\Bouncer\Contracts\Clipboard;
use Silber\Bouncer\Database\Models;
use Silber\Bouncer\Database\Role;
use Silber\Bouncer\Helpers;

class RemovesRoles
{
    /**
     * The roles to be removed.
     *
     * @var array
     */
    protected $roles;

    /**
     * Constructor.
     *
     * @param  Collection|Role|string  $roles
     */
    public function __construct($roles)
    {
        $this->roles = Helpers::toArray($roles);
    }

    /**
     * Remove the role from the given authority.
     *
     * @param  Model|array|int  $authority
     * @return void
     */
    public function from($authority, $entities = null)
    {
        $entities = Helpers::toArray($entities);

        foreach ($entities as $entity) {
            if ($entity instanceof Model && (! $entity->exists || is_null($entity->getKey()))) {
                throw new InvalidArgumentException('Cannot retract a role restricted to a non-existent model.');
            }
        }

        if (empty($entities)) {
            return;
        }

        if (! ($roleIds = $this->getRoleIds())) {
            return;
        }

        $authorities = is_array($authority) ? $authority : [$authority];

        foreach (Helpers::mapAuthorityByClass($authorities) as $class => $keys) {
            $this->retractRoles($roleIds, $class, $keys, $entities);
        }
        $this->refreshClipboardForAuthorities($authorities);
    }

    /**
     * Get the IDs of anyexisting roles provided.
     *
     * @return array
     */
    protected function getRoleIds()
    {
        [$models, $names] = Helpers::partition($this->roles, function ($role) {
            return $role instanceof Model;
        });

        $ids = $models->map(function ($model) {
            return $model->getKey();
        });

        if ($names->count()) {
            $ids = $ids->merge($this->getRoleIdsFromNames($names->all()));
        }

        return $ids->all();
    }

    /**
     * Get the IDs of the roles with the given names.
     *
     * @param  string[]  $names
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function getRoleIdsFromNames(array $names)
    {
        $key = Models::role()->getKeyName();

        return Models::role()
            ->whereIn('name', $names)
            ->get([$key])
            ->pluck($key);
    }

    /**
     * Retract the given roles from the given authorities.
     *
     * @param  array  $roleIds
     * @param  string  $authorityClass
     * @param  array  $authorityIds
     * @return void
     */
    protected function retractRoles($roleIds, $authorityClass, $authorityIds, $entities = null)
    {
        $query = $this->newPivotTableQuery();

        $morphType = (new $authorityClass)->getMorphClass();

        $entities = collect(Helpers::toArray($entities));

        foreach ($roleIds as $roleId) {
            foreach ($authorityIds as $authorityId) {
                foreach ($entities as $entity) {
                    $query->orWhere($this->getDetachQueryConstraint(
                        $roleId, $authorityId, $morphType, $entity
                    ));
                }
            }
        }

        $query->delete();
    }

    /**
     * Get a constraint for the detach query for the given parameters.
     *
     * @param  mixed  $roleId
     * @param  mixed  $authorityId
     * @param  string  $morphType
     * @return \Closure
     */
    protected function getDetachQueryConstraint($roleId, $authorityId, $morphType, $entity)
    {
        return function ($query) use ($roleId, $authorityId, $morphType, $entity) {
            $query->where(Models::scope()->getAttachAttributes() + [
                'role_id' => $roleId,
                'entity_id' => $authorityId,
                'entity_type' => $morphType,
            ]);

            $query->where('restricted_to_id', $entity instanceof Model ? $entity->getKey() : null);

            if (is_null($entity)) {
                $query->whereNull('restricted_to_type');
            } else {
                $query->whereIn('restricted_to_type', Helpers::getMorphTypes($entity));
            }
        };
    }

    /**
     * Refresh the cached clipboard for the given authorities.
     *
     * @return void
     */
    protected function refreshClipboardForAuthorities(array $authorities)
    {
        if (! Container::getInstance()->bound(Clipboard::class)) {
            return;
        }

        $clipboard = Container::getInstance()->make(Clipboard::class);

        if (! $clipboard instanceof CachedClipboard) {
            return;
        }

        foreach (Helpers::mapAuthorityByClass($authorities) as $class => $ids) {
            $model = new $class;

            foreach ($ids as $id) {
                $authority = clone $model;
                $authority->setAttribute($authority->getKeyName(), $id);
                $clipboard->refreshFor($authority);
            }
        }
    }

    /**
     * Get a query builder instance for the assigned roles pivot table.
     *
     * @return Builder
     */
    protected function newPivotTableQuery()
    {
        return Models::query('assigned_roles');
    }
}
