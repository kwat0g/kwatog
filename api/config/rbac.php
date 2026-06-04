<?php

declare(strict_types=1);

return [
    /*
     * Role slugs that can never be edited or deleted via the UI.
     * Only database seeders may create or modify these roles.
     */
    'immutable_roles' => ['system_admin'],
];
