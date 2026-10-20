<?php

namespace Silber\Bouncer;

use App\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Silber\Bouncer\Database\Models;

class Helpers
{
    /**
     * Ensure that the given logical operator is 'and'|'or'.
     *
     * @param  string  $operator
     * @return void
     *
     * @throws InvalidArgumentException
     */
    public static function ensureValidLogicalOperator($operator)
    {
        if (! in_array($operator, ['and', 'or'])) {
            throw new InvalidArgumentException(
                "{$operator} is an invalid logical operator"
            );
        }
    }

    /**
     * Extract the model instance and model keys from the given parameters.
     *
     * @param  Model|\Illuminate\Database\Eloquent\Collection|string  $model
     * @return array
     */
    public static function extractModelAndKeys($model, ?array $keys = null)
    {
        if (! is_null($keys)) {
            if (is_string($model)) {
                $model = new $model;
            }

            return [$model, $keys];
        }

        if ($model instanceof Model) {
            return [$model, [$model->getKey()]];
        }

        if ($model instanceof Collection) {
            $keys = $model->map(function ($model) {
                return $model->getKey();
            });

            return [$model->first(), $keys];
        }
    }

    /**
     * Fill the given array with the given value for any missing keys.
     *
     * @param  iterable  $array
     * @param  mixed  $value
     * @param  iterable  $keys
     * @return iterable
     */
    public static function fillMissingKeys($array, $value, $keys)
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $array)) {
                $array[$key] = $value;
            }
        }

        return $array;
    }

    /**
     * Group models and their identifiers by type (models, strings & integers).
     *
     * @param  iterable  $models
     * @return array
     */
    public static function groupModelsAndIdentifiersByType($models)
    {
        $groups = (new Collection($models))->groupBy(function ($model) {
            if (is_numeric($model)) {
                return 'integers';
            } elseif (is_string($model)) {
                return 'strings';
            } elseif ($model instanceof Model) {
                return 'models';
            }

            throw new InvalidArgumentException('Invalid model identifier');
        })->map(function ($items) {
            return $items->all();
        })->all();

        return static::fillMissingKeys($groups, [], ['integers', 'strings', 'models']);
    }

    /**
     * Determines if an array is associative.
     *
     * An array is "associative" if it doesn't have sequential numerical keys beginning with zero.
     *
     * @param  mixed  $array
     * @return bool
     */
    public static function isAssociativeArray($array)
    {
        if (! is_array($array)) {
            return false;
        }

        $keys = array_keys($array);

        return array_keys($keys) !== $keys;
    }

    /**
     * Determines if an array is numerically indexed.
     *
     * @param  mixed  $array
     * @return bool
     */
    public static function isIndexedArray($array)
    {
        if (! is_array($array)) {
            return false;
        }

        foreach ($array as $key => $value) {
            if (! is_numeric($key)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determines whether the given model is set to soft delete.
     *
     * @return bool
     */
    public static function isSoftDeleting(Model $model)
    {
        // Soft deleting models is controlled by adding the SoftDeletes trait
        // to the model. Instead of recursively looking for that trait, we
        // will check for the existence of the `isForceDeleting` method.
        if (! method_exists($model, 'isForceDeleting')) {
            return false;
        }

        return ! $model->isForceDeleting();
    }

    /**
     * Convert the given value to an array.
     *
     * @param  mixed  $value
     * @return array
     */
    public static function toArray($value)
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value instanceof Collection) {
            return $value->all();
        }

        return [$value];
    }

    /**
     * Get the morph class for the given model or model class.
     *
     * @param  Model|string  $model
     * @return string
     */
    public static function getMorphClass($model)
    {
        if ($model instanceof Model) {
            return $model->getMorphClass();
        }

        if (is_string($model) && class_exists($model) && is_subclass_of($model, Model::class)) {
            return (new $model)->getMorphClass();
        }

        return $model;
    }

    /**
     * Get the morph classes for the given model, including its class name for legacy pivot records.
     *
     * @param  Model|string  $model
     * @return array
     */
    public static function getMorphTypes($model)
    {
        $types = [static::getMorphClass($model)];

        if ($model instanceof Model) {
            $types[] = get_class($model);
        } elseif (is_string($model) && class_exists($model) && is_subclass_of($model, Model::class)) {
            $types[] = $model;
        }

        return array_unique($types);
    }

    /**
     * Map a list of authorities by their class name.
     *
     * @return array
     */
    public static function mapAuthorityByClass(array $authorities)
    {
        $map = [];

        foreach ($authorities as $authority) {
            if ($authority instanceof Model) {
                $map[get_class($authority)][] = $authority->getKey();
            } else {
                $map[Models::classname(User::class)][] = $authority;
            }
        }

        return $map;
    }

    /**
     * Partition the given collection into two collection using the given callback.
     *
     * @param  iterable  $items
     * @return Collection
     */
    public static function partition($items, callable $callback)
    {
        $partitions = [new Collection, new Collection];

        foreach ($items as $key => $item) {
            $partitions[(int) ! $callback($item, $key)][$key] = $item;
        }

        return new Collection($partitions);
    }

    public static function rawQuery($query)
    {
        $sql = (string) $query->toSql();
        $sql = str_replace('%', '%%', $sql);
        $bindings = Arr::flatten($query->getBindings());

        array_walk($bindings, function (&$item) {
            $item = DB::connection()->getPdo()->quote($item);
        });
        $sqlString = vsprintf(str_replace('?', '%s', $sql), $bindings);

        return $sqlString;
    }
}
