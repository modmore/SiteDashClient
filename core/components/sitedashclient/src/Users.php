<?php

namespace modmore\SiteDashClient;

use modUser;
use modUserGroup;
use modUserProfile;
use modX;
use MODX\Revolution\modUserGroupMember;

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
            $this->modx->log(1, $monthsAgo);
            $c->andCondition([
                'Profile.thislogin:<' => $monthsAgo,
            ]);
        }

        /** @var modUser $user */
        foreach ($this->modx->getIterator(modUser::class, $c) as $user) {
            // If this is an inactive user search, ignore users without access to the manager
            if ($this->inactiveMonths > 0 && !$this->checkMgrAccess($user)) {
                continue;
            }

            $ta = $user->toArray('', false, true);
            $ta['groups'] = [];

            $groups = $this->modx->getCollectionGraph('modUserGroup', '{"UserGroupMembers":{}}', array('UserGroupMembers.member' => $user->get('id')));
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

    /**
     * @param modUser $user
     * @return bool
     */
    public function checkMgrAccess(modUser $user): bool
    {
        $isMgrUser = false;
        $attributes = $this->modx->call(
            'modAccessContext',
            'loadAttributes',
            [
                $this->modx,
                'mgr',
                $user->get('id'),
            ]
        );
        foreach ($attributes as $aclContext => $acl) {
            if ($aclContext === 'mgr') {
                $isMgrUser = true;
            }
        }

        return $isMgrUser;
    }
}
