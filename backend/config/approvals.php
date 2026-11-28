<?php

use App\Modules\Assessment\Domain\CorrectionApprovals;
use App\Modules\Fees\Domain\FeeApprovals;
use App\Modules\Leave\Domain\LeaveApprovals;

/*
| Sources for the shared approvals inbox (GET /approvals/inbox). Each module
| adds its own (leave in Phase 5, mark corrections in Phase 6, fees in Phase 8).
*/

return [

    'sources' => [
        LeaveApprovals::class,
        CorrectionApprovals::class,
        FeeApprovals::class,
    ],

];
