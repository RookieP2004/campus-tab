<?php

namespace App\Modules\Fees\Models;

use App\Support\Models\SchoolModel;

/**
 * A named charge: tuition, admission, exam, transport, annual charges.
 *
 * @property string $name
 * @property string $code
 * @property string $status active | archived
 */
class FeeHead extends SchoolModel
{
    protected $table = 'fee_heads';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'name', 'code', 'status'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'active'];
}
