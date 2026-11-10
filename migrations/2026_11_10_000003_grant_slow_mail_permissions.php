<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

return Migration::addPermissions([
    'slow-mail.send' => Group::MEMBER_ID,
]);
