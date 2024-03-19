<?php

namespace Silber\Bouncer\Conductors;

use Silber\Bouncer\BouncerFacade;
use Silber\Bouncer\CachedClipboard;
use Silber\Bouncer\Helpers;
use Illuminate\Support\Collection;
use Silber\Bouncer\Database\Models;
use Illuminate\Database\Eloquent\Model;
use function PHPUnit\Framework\isInstanceOf;

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
     * @param \Illuminate\Support\Collection|\Silber\Bouncer\Database\Role|string  $roles
     */
    public function __construct($roles)
    {
        $this->roles = Helpers::toArray($roles);
    }

    /**
     * Assign the roles to the given authority.
     *
     * @param \Illuminate\Database\Eloquent\Model|array|int $authority
     * @param \Illuminate\Database\Eloquent\Model|array $entities
     * @return bool
     */
    public function to($authority, $entities = null)
    {
        $authorities = is_array($authority) ? $authority : [$authority];

        $roles = Models::role()->findOrCreateRoles($this->roles);

        foreach (Helpers::mapAuthorityByClass($authorities) as $class => $ids) {
            $this->assignRoles($roles, $class, new Collection($ids), $entities);
        }
        if(BouncerFacade::getClipboard()instanceof CachedClipboard) {
            foreach ($authorities as $authority) {
                BouncerFacade::getClipboard()->refreshFor($authority);
            }
        }

        return true;
    }

    /**
     * Assign the given roles to the given authorities.
     *
     * @param  \Illuminate\Support\Collection  $roles
     * @param  string $authorityClass
     * @param  \Illuminate\Support\Collection  $authorityIds
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
     * @param  \Illuminate\Support\Collection  $roleIds
     * @param  string $morphType
     * @param  \Illuminate\Support\Collection  $authorityIds
     * @return \Illuminate\Support\Collection
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
     * @param  \Illuminate\Support\Collection  $roleIds
     * @param  string $morphType
     * @param  \Illuminate\Support\Collection  $authorityIds
     * @return \Illuminate\Support\Collection
     */
    protected function buildAttachRecords($roleIds, $morphType, $authorityIds, $entities)
    {
        if (!$entities) {
            $entities = collect([null]);
        } else if(is_array($entities)) {
            $entities = collect($entities);
        } else if(!is_a($entities, 'Illuminate\Support\Collection') && !is_a($entities, 'Illuminate\Database\Eloquent\Collection')) {
            $entities = collect([$entities]);
        }
        $attachRecordsBuilder = collect();
        $entities->each(function($entity) use($roleIds, $morphType, $authorityIds, &$attachRecordsBuilder) {
            $builderForEntity = $roleIds
                ->crossJoin($authorityIds)
                ->mapSpread(function ($roleId, $authorityId) use ($morphType, $entity) {
                    return Models::scope()->getAttachAttributes() + [
                            'role_id' => $roleId,
                            'entity_id' => $authorityId,
                            'entity_type' => $morphType,
                            'restricted_to_id' => $entity?$entity->id:null,
                            'restricted_to_type' => $entity?get_class($entity):null,
                        ];
                });
            $attachRecordsBuilder = $attachRecordsBuilder->merge($builderForEntity);
        });
        return $attachRecordsBuilder;
    }

    /**
     * Save the non-existing attach records in the DB.
     *
     * @param  \Illuminate\Support\Collection  $records
     * @param  \Illuminate\Support\Collection  $existing
     * @return void
     */
    protected function createMissingAssignRecords(Collection $records, Collection $existing)
    {
        $existing = $existing->keyBy(function ($record) {
            return $this->getAttachRecordHash((array) $record);
        });

        $records = $records->reject(function ($record) use ($existing) {
            return $existing->has($this->getAttachRecordHash($record));
        });

        $this->newPivotTableQuery()->insert($records->all());
    }

    /**
     * Get a string identifying the given attach record.
     *
     * @param  array  $record
     * @return string
     */
    protected function getAttachRecordHash(array $record)
    {
        return $record['role_id'].$record['entity_id'].$record['entity_type'].$record['restricted_to_id'].$record['restricted_to_type'];
    }

    /**
     * Get a query builder instance for the assigned roles pivot table.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    protected function newPivotTableQuery()
    {
        return Models::query('assigned_roles');
    }
}
