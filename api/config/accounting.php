<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Journal Entry self-post limit (OGAMI-002)
    |--------------------------------------------------------------------------
    |
    | Maker-checker / segregation of duties on journal entries: by default the
    | user who creates a draft JE may NOT also post it. This limit relaxes that
    | for small entries — a JE whose total is strictly below the limit may be
    | self-posted (maker === checker).
    |
    | A value of '0' (the default) disables the relaxation entirely, i.e. a
    | different user must ALWAYS post, regardless of amount. Stored as a string
    | to stay exact under the decimal Money helpers.
    |
    */

    'je_self_post_limit' => env('ACCOUNTING_JE_SELF_POST_LIMIT', '0'),

];
