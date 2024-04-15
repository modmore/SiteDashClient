<?php

namespace modmore\SiteDashClient;

use modUser;
use modUserGroup;
use modUserProfile;
use modX;

class Users implements CommandInterface {
    protected $modx;
    /**
     * @var string
     */
    private $query;
    /**
     * @var int
     */
    private $inactiveMonths;

    public function __construct(modX $modx, string $query, int $inactiveMonths)
    {
        $this->modx = $modx;
        $this->query = trim($query);
        $this->inactiveMonths = $inactiveMonths;
    }

    public function run()
    {
        $data = [];

        if ((empty($this->query) || strlen($this->query) < 2) && empty($this->inactiveMonths)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Invalid query. Must be at least 2 characters, or have an inactive manager user time period selected',
            ], JSON_PRETTY_PRINT);
            return;
        }

        if (!(bool)$this->modx->getOption('sitedashclient.allow_user_search')) {
            echo json_encode([
                'success' => false,
                'message' => 'User search is disabled on this site.',
            ], JSON_PRETTY_PRINT);
            return;
        }

        $c = $this->modx->newQuery(modUser::class);
        $c->innerJoin(modUserProfile::class, 'Profile');
        $c->select($this->modx->getSelectColumns(modUser::class, 'modUser', '', ['id', 'username', 'active', 'sudo', 'createdon']));
        $c->select($this->modx->getSelectColumns(modUserProfile::class, 'Profile', 'profile_', ['email', 'fullname', 'lastlogin']));
        $c->where([
            'username:LIKE' => "%{$this->query}%",
            'OR:Profile.email:LIKE' => "%{$this->query}%",
            'OR:Profile.fullname:LIKE' => "%{$this->query}%",
        ]);

        if ($this->inactiveMonths > 0) {
            $monthsAgo = strtotime("-{$this->inactiveMonths} months");
            $c->andCondition([
                'Profile.thislogin:<' => $monthsAgo,
            ]);

            $mgrQuery = $this->modx->newQuery('modUserGroup');
            $mgrQuery->leftJoin('modUserGroupMember', 'UserGroupMembers');
            $mgrQuery->innerJoin('modAccessContext', 'Access', [
                'Access.principal = modUserGroup.id',
                'Access.target' => 'mgr',
                [
                    'Access.principal_class:=' => 'modUserGroup', // MODX 2.x
                    'OR:Access.principal_class:=' => 'MODX\\Revolution\\modUserGroup' // MODX 3.x
                ],
            ]);

            $groups = [];
            foreach ($this->modx->getIterator('modUserGroup', $mgrQuery) as $group) {
                $groups[] = $group->get('id');
            }

            $c->leftJoin('modUserGroupMember', 'UserGroupMembers');
            $c->where([
                'UserGroupMembers.user_group:IN' => $groups,
            ]);
        }

        /** @var modUser $user */
        foreach ($this->modx->getIterator(modUser::class, $c) as $user) {
            $ta = $user->toArray('', false, true);
            $ta['groups'] = [];

            $c = $this->modx->newQuery('modUserGroup');
            $c->leftJoin('modUserGroupMember', 'UserGroupMembers');
            $c->where([
                'UserGroupMembers.member' => $user->get('id'),
            ]);

            $groups = $this->modx->getCollection('modUserGroup', $c);
            /** @var modUserGroup $group */
            foreach ($groups as $group) {
                $ta['groups'][] = $group->get('name');
            }

            $data[] = $ta;
        }

        // Output the requested info
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'total' => count($data),
            'data' => $data,
        ], JSON_PRETTY_PRINT);
    }
}
