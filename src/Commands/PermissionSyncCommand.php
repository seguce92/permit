<?php

namespace Nahid\Permit\Commands;

use Illuminate\Console\Command;
use Nahid\Permit\Permissions\PermissionRepository;
use Nahid\Permit\Users\UserRepository;
use Nahid\JsonQ\Jsonq;

class PermissionSyncCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'permit:sync';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync permissions to database';


    /**
     * @var mixed
     */
    protected $abilities;

    /**
     * @var mixed
     */
    protected $roles;

    /**
     * @var mixed
     */
    protected $policies;

    /**
     * @var UserRepository
     */
    protected $user;

    /**
     * @var PermissionRepository
     */
    protected $permission;

    /**
     * @var
     */
    protected $userColumn;


    /**
     * @var mixed
     */
    protected $superUser;


    /**
     * PermissionSyncCommand constructor.
     *
     * @param UserRepository       $userRepository
     * @param PermissionRepository $permissionRepository
     */
    public function __construct(UserRepository $userRepository, PermissionRepository $permissionRepository)
    {
        parent::__construct();
        $this->abilities = config('permit.abilities');
        $this->roles = config('permit.roles');
        $this->policies = config('permit.policies');
        $this->roleColumn = config('permit.users.role_column');
        $this->superUser = config('permit.super_user');
        $this->user = $userRepository;
        $this->permission = $permissionRepository;
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->syncRolePermissions();
    }

    /**
     * sync local role permissions to database
     */
    protected function syncRolePermissions()
    {
        $data = [];
        $jsonq = new Jsonq();
        $permission_object = $jsonq->collect($this->abilities);
        foreach ($this->roles as $role=>$permission) {
            $permissions = [];
            foreach ($permission as $rules) {
                $rule = explode('.', $rules);
                $perms = $permission_object->node($rule[0])->get(false);
                if ($rule[1] == '*') {
                    if (!is_null($perms)) {
                        if (!isset($permissions[$rule[0]])) {
                            $permissions[$rule[0]] = [];
                        }

                        $auth_perms = [];
                        foreach ($perms as $perm => $permission) {
                            if (is_int($perm)) {
                                $auth_perms[$permission] = true;
                            } elseif (is_string($permission)) {
                                $policies = explode('.', $permission);
                                if (count($policies)==2) {
                                    $auth_perms[$perm] = $this->policies[$policies[0]][$policies[1]];
                                }
                            }
                        }
                        $permissions[$rule[0]] = $auth_perms;
                    }
                } else {
                    if (!is_null($perms)) {
                        if (!isset($permissions[$rule[0]])) {
                            $permissions[$rule[0]] = [];
                        }

//                        dd($rule, $perms);

                        if (in_array($rule[1], $perms)) {
                            $permissions[$rule[0]][$rule[1]] = true;
                        } elseif (array_key_exists($rule[1], $perms)) {
                            $policies = explode('.', $perms[$rule[1]]);
                            if (count($policies)==2) {
                                $permissions[$rule[0]][$rule[1]] = $this->policies[$policies[0]][$policies[1]];
                            }
                        }
                    }
                }
            }

            $data[] = ['role_name'=>$role, 'permission'=>json_encode($permissions)];
        }

        $db = app('db');
        if (is_array($data)) {
            if ($this->confirm('Do you wish to sync with existing permissions?')) {
                $db->beginTransaction();
                foreach ($data as $d) {
                    if (!$this->permission->syncRolePermissions($d['role_name'], $d)) {
                        $db->rollback();
                    }
                }
                $db->commit();
                $this->info('Permissions Synced!');
            } else {
                $this->error('Process Canceled!');
            }
        }
    }
}
