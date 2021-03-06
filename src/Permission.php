<?php

namespace Nahid\Permit;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Config\Repository;
use Nahid\JsonQ\Jsonq;
use Nahid\Permit\Permissions\PermissionRepository;
use Nahid\Permit\Users\UserRepository;

class Permission
{
    /**
     * super user role
     * @var mixed
     */
    protected $superUser;

    /**
     * user table role column
     * @var mixed
     */
    protected $roleColumn;

    /**
     * @var Repository
     */
    protected $config;

    /**
     * @var PermissionRepository
     */
    protected $permission;

    /**
     * user model namespace
     * @var mixed
     */
    protected $userModelNamespace;

    /**
     * auth user model name
     * @var
     */
    protected $userModel;

    /**
     * @var UserRepository
     */
    protected $user;

    /**
     * @var Jsonq
     */
    protected $json;

    /**
     * @var array
     */
    protected $authPermissions = [];

    /**
     * Permission constructor.
     *
     * @param Repository           $config
     * @param PermissionRepository $permission
     * @param UserRepository       $user
     */
    public function __construct(Repository $config, PermissionRepository $permission, UserRepository $user)
    {
        $this->config = $config;
        $this->permission = $permission;
        $this->user = $user;
        $this->userModelNamespace = $this->config->get('permit.users.model');
        $this->superUser = $this->config->get('permit.super_user');
        $this->roleColumn = $this->config->get('permit.users.role_column');
        $this->userModel = new $this->userModelNamespace();

        $this->json = new Jsonq();
    }

    /**
     * check the given user is allows for the given permission
     *
     * @param       $user
     * @param       $permission
     * @param array $params
     * @return bool
     * @throws AuthorizationException
     */
    public function userAllows($user, $permission, $params = [])
    {
        if ($user instanceof $this->userModelNamespace) {
            if ($user->{$this->roleColumn} == $this->superUser) {
                return true;
            }

            if (!empty($user->permissions)) {
                $abilities = json_decode($user->permissions);
                $this->authPermissions = $this->json->collect($abilities);

                if (!is_null($user->permission)) {
                    if (is_array($permission)) {
                        if ($this->hasOnePermission($permission, $user)) {
                            return true;
                        }
                    }

                    if (is_string($permission)) {
                        if ($this->isPermissionDo($permission, $user, $params)) {
                            return true;
                        }
                    }
                }
            }
        }

        throw new AuthorizationException('Unauthorized');
    }

    /**
     * Its an alias of userAllows, but its return bool instead of Exception
     *
     * @param       $user
     * @param       $permission
     * @param array $params
     * @return bool
     */
    public function userCan($user, $permission, $params = [])
    {
        try {
            return $this->userAllows($user, $permission, $params);
        } catch (AuthorizationException $e) {
            return false;
        }
    }

    /**
     * check the given user's role is allows for the given permission
     *
     * @param       $user
     * @param       $permission
     * @param array $params
     * @return bool
     * @throws AuthorizationException
     */
    public function roleAllows($user, $permission, $params = [])
    {
        if ($user instanceof $this->userModelNamespace) {
            if ($user->{$this->roleColumn} == $this->superUser) {
                return true;
            }

            $abilities = json_decode($user->permission->permission);
            $this->authPermissions = $this->json->collect($abilities);

            if (!is_null($user->permission)) {
                if (is_array($permission)) {
                    if ($this->hasOnePermission($permission, $user)) {
                        return true;
                    }
                }

                if (is_string($permission)) {
                    if ($this->isPermissionDo($permission, $user, $params)) {
                        return true;
                    }
                }
            }
        }

        throw new AuthorizationException('Unauthorized');
    }

    /**
     * its an alias of roleAllows, but its return bool instead of Exception
     *
     * @param       $user
     * @param       $permission
     * @param array $params
     * @return bool
     */
    public function roleCan($user, $permission, $params = [])
    {
        try {
            return $this->roleAllows($user, $permission, $params);
        } catch (AuthorizationException $e) {
            return false;
        }
    }

    /**
     * check the given user are allows for then given permission.
     * here permission check for user specific permissions and role based permissions
     *
     * @param       $user
     * @param       $permission
     * @param array $params
     * @return bool
     * @throws AuthorizationException
     */
    public function allows($user, $permission, $params = [])
    {
        if ($user instanceof $this->userModelNamespace) {
            $user_permissions = json_decode($user->permissions, true);
            $role_permissions = json_decode($user->permission->permission, true);
            $abilities = array_merge($role_permissions, $user_permissions);

            $this->authPermissions = $this->json->collect($abilities);

            if (count($abilities) > 0) {
                if (is_array($permission)) {
                    if ($this->hasOnePermission($permission, $user)) {
                        return true;
                    }
                }

                if (is_string($permission)) {
                    if ($this->isPermissionDo($permission, $user, $params)) {
                        return true;
                    }
                }
            }
        }

        throw new AuthorizationException('Unauthorized');
    }

    /**
     * its an alias of allows, but its return bool instead of Exception
     *
     * @param       $user
     * @param       $permission
     * @param array $params
     * @return bool
     */
    public function can($user, $permission, $params = [])
    {
        try {
            return $this->allows($user, $permission, $params);
        } catch (AuthorizationException $e) {
            return false;
        }
    }

    /**
     * this method is set user role for given user id
     *
     * @param $user_id
     * @param $role_name
     * @return bool
     */
    public function setUserRole($user_id, $role_name)
    {
        $user = $this->user->find($user_id);

        if ($user) {
            $this->userModel->unguard();
            $this->user->update($user_id, [$this->config->get('permit.users.role_column') => $role_name]);
            $this->userModel->reguard();
            return true;
        }
    }

    /**
     * fetch policy for given permission
     *
     * @param $ability
     * @return string
     */
    protected function fetchPolicy($ability)
    {
        $policies = $this->config->get('permit.policies');
        $policy_str = explode('.', $ability);
        $policy = '';
        if (isset($policies[$policy_str[0]][$policy_str[1]])) {
            $policy = $policies[$policy_str[0]][$policy_str[1]];
        }

        return $policy;
    }

    /**
     * set permissions for given user
     *
     * @param       $user_id
     * @param       $module
     * @param array $abilities
     * @return bool
     */
    public function setUserPermissions($user_id, $module, $abilities = [])
    {
        $user = $this->user->find($user_id);
        if ($user) {
            $permission = json_decode($user->permissions, true);
            foreach ($abilities as $name => $val) {
                if (is_bool($val)) {
                    $permission[$module][$name] = $val;
                } elseif (is_string($val)) {
                    $policy = $this->fetchPolicy($val);
                    $permission[$module][$name] = $policy;
                }
            }

            $this->userModel->unguard();
            $this->user->update($user_id, ['permissions' => json_encode($permission)]);
            $this->userModel->reguard();
        }
        return true;
    }

    /**
     * set permission for given role
     *
     * @param       $role_name
     * @param       $module
     * @param array $abilities
     * @return bool
     */
    public function setRolePermissions($role_name, $module, $abilities = [])
    {
        $role = $this->permission->findBy('role_name', $role_name);
        if ($role) {
            $permission = json_decode($role->permission, true);
            foreach ($abilities as $name => $val) {
                if (is_bool($val)) {
                    $permission[$module][$name] = $val;
                } elseif (is_string($val)) {
                    $policy = $this->fetchPolicy($val);
                    $permission[$module][$name] = $policy;
                }
            }
            $role->update(['permission' => $permission]);
        } else {
            $row = ['role_name' => $role_name, 'permission' => []];
            foreach ($abilities as $name => $val) {
                if (is_bool($val)) {
                    $row['permission'][$module][$name] = $val;
                } elseif (is_string($val)) {
                    $policy = $this->fetchPolicy($val);
                    $row['permission'][$module][$name] = $policy;
                }
            }
            //dd($row);
            $this->permission->create($row);
        }
        return true;
    }

    /**
     * execute policy method with its parameters
     *
     * @param       $callable
     * @param array $params
     * @return bool|mixed
     */
    protected function callPolicy($callable, $params = [])
    {
        $arr_callable = explode('@', $callable);

        if (count($arr_callable)>1) {
            if (class_exists($arr_callable[0])) {
                $class = new $arr_callable[0]();
                $method = $arr_callable[1];

                if (method_exists($class, $method)) {
                    return call_user_func_array([$class, $method], $params);
                }
            }
        }

        return false;
    }

    /**
     * check this permission for the given user is allowed
     *
     * @param       $permission
     * @param       $user
     * @param array $params
     * @return bool|mixed
     */
    protected function isPermissionDo($permission, $user, $params = [])
    {
        $parameters = [$user];

        $permit = explode('.', $permission);

        if (count($permit) == 2) {
            $auth_permissions = (array) $this->authPermissions->node($permit[0])->get(false);
            foreach ($params as $param) {
                array_push($parameters, $param);
            }


            if (is_null($permission)) {
                return false;
            }

            if (isset($auth_permissions[$permit[1]])) {
                if ($auth_permissions[$permit[1]] === true) {
                    return true;
                } elseif (is_string($auth_permissions[$permit[1]])) {
                    return $this->callPolicy($auth_permissions[$permit[1]], $parameters);
                }
            }
        }
        return false;
    }

    /**
     * check the given users has attest one permission
     *
     * @param array $permissions
     * @param       $user
     * @return bool
     */
    protected function hasOnePermission($permissions = [], $user)
    {
        foreach ($permissions as $key => $value) {
            $permission = '';
            $params = [];
            if (is_int($key)) {
                $permission = $value;
            } else {
                $permission = $key;
                $params = $value;
            }


            if ($this->isPermissionDo($permission, $user, $params)) {
                return true;
            }
        }

        return false;
    }
}
