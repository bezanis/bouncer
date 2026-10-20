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

class AssignsRoles
{
    /**
     * The roles to be assigned.
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
     * Assign the roles to the given authority.
     *
     * @param  Model|array|int  $authority
     * @param  Model|array  $entities
     * @return bool
     */
    public function to($authority, $entities = null)
    {
        $authorities = is_array($authority) ? $authority : [$authority];
        $entities = Helpers::toArray($entities);

        foreach ($entities as $entity) {
            if ($entity instanceof Model && (! $entity->exists || is_null($entity->getKey()))) {
                throw new InvalidArgumentException('Cannot assign a role restricted to a non-existent model.');
            }
        }

        if (empty($authorities) || empty($entities)) {
            return true;
        }

        $roles = Models::role()->findOrCreateRoles($this->roles);

        foreach (Helpers::mapAuthorityByClass($authorities) as $class => $ids) {
            $this->assignRoles($roles, $class, new Collection($ids), $entities);
        }
        $this->refreshClipboardForAuthorities($authorities);

        return true;
    }

    /**
     * Assign the given roles to the given authorities.
     *
     * @param  string  $authorityClass
     * @return void
     */
    protected function assignRoles(Collection $roles, $authorityClass, Collection $authorityIds, $entities)
    {
        $roleIds = $roles->map(function ($model) {
            return $model->getKey();
        });

        $morphType = (new $authorityClass)->getMorphClass();

        $records = $this->buildAttachRecords($roleIds, $morphType, $authorityIds, $entities);

        $existing = $this->getExistingAttachRecords($roleIds, $morphType, $authorityIds);

        $this->createMissingAssignRecords($records, $existing);
    }

    /**
     * Get the pivot table records for the roles already assigned.
     *
     * @param  Collection  $roleIds
     * @param  string  $morphType
     * @param  Collection  $authorityIds
     * @return Collection
     */
    protected function getExistingAttachRecords($roleIds, $morphType, $authorityIds)
    {
        $query = $this->newPivotTableQuery()
            ->whereIn('role_id', $roleIds->all())
            ->whereIn('entity_id', $authorityIds->all())
            ->where('entity_type', $morphType);

        Models::scope()->applyToRelationQuery($query, $query->from);

        return new Collection($query->get());
    }

    /**
     * Build the raw attach records for the assigned roles pivot table.
     *
     * @param  Collection  $roleIds
     * @param  string  $morphType
     * @param  Collection  $authorityIds
     * @return Collection
     */
    protected function buildAttachRecords($roleIds, $morphType, $authorityIds, $entities)
    {
        $entities = collect(Helpers::toArray($entities));
        $attachRecordsBuilder = collect();
        $entities->each(function ($entity) use ($roleIds, $morphType, $authorityIds, &$attachRecordsBuilder) {
            $builderForEntity = $roleIds
                ->crossJoin($authorityIds)
                ->mapSpread(function ($roleId, $authorityId) use ($morphType, $entity) {
                    return Models::scope()->getAttachAttributes() + [
                        'role_id' => $roleId,
                        'entity_id' => $authorityId,
                        'entity_type' => $morphType,
                        'restricted_to_id' => $entity instanceof Model ? $entity->getKey() : null,
                        'restricted_to_type' => is_null($entity) ? null : Helpers::getMorphClass($entity),
                    ];
                });
            $attachRecordsBuilder = $attachRecordsBuilder->merge($builderForEntity);
        });

        return $attachRecordsBuilder;
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
     * Save the non-existing attach records in the DB.
     *
     * @return void
     */
    protected function createMissingAssignRecords(Collection $records, Collection $existing)
    {
        $existing = $existing->keyBy(function ($record) {
            return $this->getAttachRecordHash((array) $record);
        });

        $records = $records->unique(function ($record) {
            return $this->getAttachRecordHash($record);
        })->reject(function ($record) use ($existing) {
            return $existing->has($this->getAttachRecordHash($record));
        });

        $this->newPivotTableQuery()->insert($records->all());
    }

    /**
     * Get a string identifying the given attach record.
     *
     * @return string
     */
    protected function getAttachRecordHash(array $record)
    {
        return json_encode([
            (string) $record['role_id'],
            (string) $record['entity_id'],
            $record['entity_type'],
            is_null($record['restricted_to_id']) ? null : (string) $record['restricted_to_id'],
            Helpers::getMorphClass($record['restricted_to_type']),
        ]);
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
