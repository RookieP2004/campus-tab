<?php

namespace App\Modules\Communication\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Support\Carbon;
use MongoDB\Laravel\Eloquent\Model;

/**
 * A read receipt: this user opened this notice. One per user and notice.
 * Bookkeeping, not a change to school data, so it is not audited.
 *
 * @property string $tenant_id
 * @property string $notice_id
 * @property string $user_id
 * @property Carbon $read_at
 */
class NoticeRead extends Model
{
    use BelongsToTenant;

    protected $connection = 'mongodb';

    protected $table = 'notice_reads';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'notice_id', 'user_id', 'read_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }
}
