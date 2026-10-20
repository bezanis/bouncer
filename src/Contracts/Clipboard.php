<?php

namespace Silber\Bouncer\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

interface Clipboard
{
    /**
     * Determine if the given authority has the given ability.
     *
     * @param  string  $ability
     * @param  Model|string|null  $model
     * @return bool
     */
    public function check(Model $authority, $ability, $model = null);

    /**
     * Determine if the given authority has the given ability, and return its identifier.
     *
     * @param  string  $ability
     * @param  Model|string|null  $model
     * @return string|bool|null
     */
    public function checkGetId(Model $authority, $ability, $model = null);

    /**
     * Check if an authority has the given roles.
     *
     * @param  array|string  $roles
     * @param  string  $boolean
     * @return bool
     */
    public function checkRole(Model $authority, $roles, $boolean = 'or');

    /**
     * Get the given authority's roles.
     *
     * @return Collection
     */
    public function getRoles(Model $authority);

    /**
     * Get a list of the authority's abilities.
     *
     * @param  bool  $allowed
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAbilities(Model $authority, $allowed = true);

    /**
     * Get a list of the authority's forbidden abilities.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getForbiddenAbilities(Model $authority);
}
