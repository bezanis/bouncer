<?php

namespace Silber\Bouncer;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Silber\Bouncer\Database\Models;
use Silber\Bouncer\Database\Queries\Abilities;

class Clipboard extends BaseClipboard
{
    /**
     * Determine if the given authority has the given ability, and return its identifier.
     *
     * @param  string  $ability
     * @param  Model|string|null  $model
     * @return string|bool|null
     */
    public function checkGetId(Model $authority, $ability, $model = null)
    {
        if ($this->isForbidden($authority, $ability, $model)) {
            return false;
        }

        $ability = $this->getAllowingAbility($authority, $ability, $model);

        return $ability ? $ability->identifier : null;
    }

    /**
     * Determine whether the given ability request is explicitely forbidden.
     *
     * @param  string  $ability
     * @param  Model|string|null  $model
     * @return bool
     */
    protected function isForbidden(Model $authority, $ability, $model = null)
    {
        return $this->getHasAbilityQuery(
            $authority, $ability, $model, false
        )->exists();
    }

    /**
     * Get the ability model that allows the given ability request.
     *
     * Returns null if the ability is not allowed.
     *
     * @param  string  $ability
     * @param  Model|string|null  $model
     * @return Model|null
     */
    protected function getAllowingAbility(Model $authority, $ability, $model = null)
    {
        return $this->getHasAbilityQuery(
            $authority, $ability, $model, true
        )->first();
    }

    /**
     * Get the query for where the given authority has the given ability.
     *
     * @param  Model  $authority
     * @param  string  $ability
     * @param  Model|string|null  $model
     * @param  bool  $allowed
     * @return Builder
     */
    protected function getHasAbilityQuery($authority, $ability, $model, $allowed)
    {
        $query = Abilities::forAuthority($authority, $allowed, $ability, $model);

        if (! $this->isOwnedBy($authority, $model)) {
            $query->where('only_owned', false);
        }

        if (is_null($model)) {
            return $this->constrainToSimpleAbility($query, $ability);
        }

        return $query->forModel($model);
    }

    /**
     * Constrain the query to the given non-model ability.
     *
     * @param  Builder  $query
     * @param  string  $ability
     * @return Builder
     */
    protected function constrainToSimpleAbility($query, $ability)
    {
        $abilitiesTable = Models::table('abilities');

        return $query->where(function ($query) use ($ability, $abilitiesTable) {
            $query->whereIn($abilitiesTable.'.name', ['*', $ability])->where(function ($query) use ($abilitiesTable) {
                $query->whereNull($abilitiesTable.'.entity_type')
                    ->orWhere(function ($query) use ($abilitiesTable) {
                        $query->where($abilitiesTable.'.entity_type', '*')
                            ->where($abilitiesTable.'.name', '*');
                    });
            });
        });
    }
}
