<?php

namespace App\Modules\Fees\Models;

use App\Support\Models\SchoolModel;

/**
 * Who a plan applies to: new admission, existing student, staff ward, RTE seat.
 *
 * @property string $name
 * @property string $code
 * @property string $status active | archived
 */
class FeeCategory extends SchoolModel
{
    protected $table = 'fee_categories';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'name', 'code', 'status'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'active'];
}
