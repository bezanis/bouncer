<?php

namespace Silber\Bouncer\Conductors;

use Illuminate\Database\Eloquent\Model;
use Silber\Bouncer\Contracts\Clipboard;

class ChecksRoles
{
    /**
     * The authority against which to check for roles.
     *
     * @var Model
     */
    protected $authority;

    /**
     * The bouncer clipboard instance.
     *
     * @var Clipboard
     */
    protected $clipboard;

    /**
     * Constructor.
     */
    public function __construct(Model $authority, Clipboard $clipboard)
    {
        $this->authority = $authority;
        $this->clipboard = $clipboard;
    }

    /**
     * Check if the authority has any of the given roles.
     *
     * @param  string  ...$roles
     * @return bool
     */
    public function a(...$roles)
    {
        return $this->clipboard->checkRole($this->authority, $roles, 'or');
    }

    /**
     * Check if the authority has the given roles on the given entities.
     *
     * @param  array|string  $roles
     * @param  mixed  $entities
     * @param  string  $boolean
     * @return bool
     */
    public function on($roles, $entities, $boolean = 'or')
    {
        return $this->clipboard->checkRoleOn($this->authority, $roles, $entities, $boolean);
    }

    /**
     * Check if the authority doesn't have any of the given roles.
     *
     * @param  string  ...$roles
     * @return bool
     */
    public function notA(...$roles)
    {
        return $this->clipboard->checkRole($this->authority, $roles, 'not');
    }

    /**
     * Alias to the "a" method.
     *
     * @param  string  ...$roles
     * @return bool
     */
    public function an(...$roles)
    {
        return $this->clipboard->checkRole($this->authority, $roles, 'or');
    }

    /**
     * Alias to the "notA" method.
     *
     * @param  string  ...$roles
     * @return bool
     */
    public function notAn(...$roles)
    {
        return $this->clipboard->checkRole($this->authority, $roles, 'not');
    }

    /**
     * Check if the authority has all of the given roles.
     *
     * @param  string  ...$roles
     * @return bool
     */
    public function all(...$roles)
    {
        return $this->clipboard->checkRole($this->authority, $roles, 'and');
    }
}
