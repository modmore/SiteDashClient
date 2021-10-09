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

    public function __construct(modX $modx, string $query)
    {
        $this->modx = $modx;
        $this->query = trim($query);
    }

    public function run()
    {
        $data = [];

        if (empty($this->query) || strlen($this->query) < 2) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Invalid query, must be at least 2 characters.',
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

        /** @var modUser $user */
        foreach ($this->modx->getIterator(modUser::class, $c) as $user) {
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
}
