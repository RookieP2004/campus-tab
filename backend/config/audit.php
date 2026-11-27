<?php

use App\Modules\Identity\Models\User;

return [

    // Days a record stays in the recycle bin before the Chief may purge it.
    'purge_after_days' => 30,

    /*
    | Soft-deleted models listed in the recycle bin, by type name. Later
    | phases add students, staff records and the rest.
    */
    'recyclable' => [
        'user' => User::class,
    ],

];
