<?php

namespace Silber\Bouncer;

use Illuminate\Cache\TaggedCache;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection as BaseCollection;
use Silber\Bouncer\Database\Models;

class CachedClipboard extends BaseClipboard implements Contracts\CachedClipboard
{
    /**
     * The tag used for caching.
     *
     * @var string
     */
    protected $tag = 'silber-bouncer';

    /**
     * The cache store.
     *
     * @var Store
     */
    protected $cache;

    /**
     * Constructor.
     */
    public function __construct(Store $cache)
    {
        $this->setCache($cache);
    }

    /**
     * Set the cache instance.
     *
     * @return $this
     */
    public function setCache(Store $cache)
    {
        if (method_exists($cache, 'tags')) {
            $cache = $cache->tags($this->tag());
        }

        $this->cache = $cache;

        return $this;
    }

    /**
     * Get the cache instance.
     *
     * @return Store
     */
    public function getCache()
    {
        return $this->cache;
    }

    /**
     * Determine if the given authority has the given ability, and return its identifier.
     *
     * @param  string  $ability
     * @param  Model|string|null  $model
     * @return string|bool|null
     */
    public function checkGetId(Model $authority, $ability, $model = null)
    {
        $applicable = $this->compileAbilityIdentifiers($ability, $model);

        // We will first check if any of the applicable abilities have been forbidden.
        // If so, we'll return false right away, so as to not pass the check. Then,
        // we'll check if any of them have been allowed & return the matched ID.
        $forbiddenId = $this->findMatchingAbility(
            $this->getAbilities($authority, false), $applicable, $model, $authority
        );

        if (! is_null($forbiddenId)) {
            return false;
        }

        return $this->findMatchingAbility(
            $this->getAbilities($authority, true), $applicable, $model, $authority
        );
    }

    /**
     * Determine if any of the abilities can be matched against the provided applicable ones.
     *
     * @param  BaseCollection  $abilities
     * @param  BaseCollection  $applicable
     * @param  Model  $model
     * @param  Model  $authority
     * @return string|null
     */
    protected function findMatchingAbility($abilities, $applicable, $model, $authority)
    {
        $abilities = $abilities->toBase()->mapWithKeys(function ($ability) use ($model) {
            $identifier = $ability->identifier;

            if (! is_null($model) && $model !== '*' && $ability->role_based && is_null($ability->entity_type)) {
                $identifier = strtolower($ability->name).'-*'.($ability->only_owned ? '-owned' : '');
            }

            return [$ability->identifier => $identifier];
        });

        if (! is_null($id = $this->getMatchedAbilityId($abilities, $applicable))) {
            return $id;
        }

        if ($this->isOwnedBy($authority, $model)) {
            return $this->getMatchedAbilityId(
                $abilities,
                $applicable->map(function ($identifier) {
                    return $identifier.'-owned';
                })
            );
        }
    }

    /**
     * Get the identifier of the ability that matches one of the applicable abilities.
     *
     * @param  BaseCollection  $abilityMap
     * @param  BaseCollection  $applicable
     * @return string|null
     */
    protected function getMatchedAbilityId($abilityMap, $applicable)
    {
        foreach ($abilityMap as $id => $identifier) {
            if ($applicable->contains($identifier)) {
                return (string) $id;
            }
        }
    }

    /**
     * Compile a list of ability identifiers that match the provided parameters.
     *
     * @param  string  $ability
     * @param  Model|string|null  $model
     * @return BaseCollection
     */
    protected function compileAbilityIdentifiers($ability, $model)
    {
        $identifiers = new BaseCollection(
            is_null($model)
                ? [$ability, '*-*', '*']
                : $this->compileModelAbilityIdentifiers($ability, $model)
        );

        return $identifiers->map(function ($identifier) {
            return strtolower($identifier);
        });
    }

    /**
     * Compile a list of ability identifiers that match the given model.
     *
     * @param  string  $ability
     * @param  Model|string  $model
     * @return array
     */
    protected function compileModelAbilityIdentifiers($ability, $model)
    {
        if ($model === '*') {
            return ["{$ability}-*", '*-*'];
        }

        $model = $model instanceof Model ? $model : new $model;

        $type = $model->getMorphClass();

        $abilities = [
            "{$ability}-{$type}",
            "{$ability}-*",
            "*-{$type}",
            '*-*',
        ];

        if ($model->exists) {
            $abilities[] = "{$ability}-{$type}-{$model->getKey()}";
            $abilities[] = "*-{$type}-{$model->getKey()}";
        }

        return $abilities;
    }

    /**
     * Get the given authority's abilities.
     *
     * @param  bool  $allowed
     * @return Collection
     */
    public function getAbilities(Model $authority, $allowed = true, $abilityNames = null, $restrictionModel = null)
    {
        if (! is_null($abilityNames) || ! is_null($restrictionModel)) {
            return parent::getAbilities($authority, $allowed, $abilityNames, $restrictionModel);
        }

        $key = $this->getCacheKey($authority, 'abilities', $allowed);

        if (is_array($abilities = $this->cache->get($key))) {
            return $this->deserializeAbilities($abilities);
        }

        $abilities = $this->getFreshAbilities($authority, $allowed);

        $this->cache->forever($key, $this->serializeAbilities($abilities));

        return $abilities;
    }

    /**
     * Get a fresh copy of the given authority's abilities.
     *
     * @param  bool  $allowed
     * @return Collection
     */
    public function getFreshAbilities(Model $authority, $allowed, $abilityNames = null, $restrictionModel = null)
    {
        return parent::getAbilities($authority, $allowed, $abilityNames, $restrictionModel);
    }

    /**
     * Get the given authority's roles' IDs and names.
     *
     * @return array
     */
    public function getRolesLookup(Model $authority)
    {
        $key = $this->getCacheKey($authority, 'roles');

        return $this->sear($key, function () use ($authority) {
            return parent::getRolesLookup($authority);
        });
    }

    /**
     * Get an item from the cache, or store the default value forever.
     *
     * @param  string  $key
     * @return mixed
     */
    protected function sear($key, callable $callback)
    {
        if (is_null($value = $this->cache->get($key))) {
            $this->cache->forever($key, $value = $callback());
        }

        return $value;
    }

    /**
     * Clear the cache.
     *
     * @param  null|Model  $authority
     * @return $this
     */
    public function refresh($authority = null)
    {
        if (! is_null($authority)) {
            return $this->refreshFor($authority);
        }

        if ($this->cache instanceof TaggedCache) {
            $this->cache->flush();
        } else {
            $this->refreshAllIteratively();
        }

        return $this;
    }

    /**
     * Clear the cache for the given authority.
     *
     * @return $this
     */
    public function refreshFor(Model $authority)
    {
        $this->cache->forget($this->getCacheKey($authority, 'abilities', true));
        $this->cache->forget($this->getCacheKey($authority, 'abilities', false));
        $this->cache->forget($this->getCacheKey($authority, 'roles'));

        return $this;
    }

    /**
     * Refresh the cache for all roles and users, iteratively.
     *
     * @return void
     */
    protected function refreshAllIteratively()
    {
        foreach (Models::user()->all() as $user) {
            $this->refreshFor($user);
        }

        foreach (Models::role()->all() as $role) {
            $this->refreshFor($role);
        }
    }

    /**
     * Get the cache key for the given model's cache type.
     *
     * @param  string  $type
     * @param  bool  $allowed
     * @return string
     */
    protected function getCacheKey(Model $model, $type, $allowed = true)
    {
        return implode('-', [
            $this->tag(),
            $type,
            $model->getMorphClass(),
            $model->getKey(),
            $allowed ? 'a' : 'f',
        ]);
    }

    /**
     * Get the cache tag.
     *
     * @return string
     */
    protected function tag()
    {
        return Models::scope()->appendToCacheKey($this->tag);
    }

    /**
     * Deserialize an array of abilities into a collection of models.
     *
     * @return Collection
     */
    protected function deserializeAbilities(array $abilities)
    {
        return Models::ability()->hydrate($abilities);
    }

    /**
     * Serialize a collection of ability models into a plain array.
     *
     * @return array
     */
    protected function serializeAbilities(Collection $abilities)
    {
        return $abilities->map(function ($ability) {
            return $ability->getAttributes();
        })->all();
    }
}
