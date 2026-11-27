<?php

namespace App\Modules\Communication\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Support\Carbon;
use MongoDB\Laravel\Eloquent\Model;

/**
 * One in-app notification for one user. The in-app text may name things
 * (it is shown only after sign-in); push and email never do (Notifier).
 *
 * @property string $tenant_id
 * @property string $user_id
 * @property string $type notice | attendance.absent | …
 * @property string $title
 * @property string|null $body
 * @property string|null $link a screen path inside the app
 * @property array<string, mixed> $data
 * @property Carbon|null $read_at
 * @property Carbon|null $created_at
 */
class Notification extends Model
{
    use BelongsToTenant;

    protected $connection = 'mongodb';

    protected $table = 'notifications';

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'user_id', 'type', 'title', 'body', 'link', 'data', 'read_at'];

    /** @var array<string, mixed> */
    protected $attributes = ['data' => [], 'read_at' => null];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    /**
     * @return array<string, mixed>
     */
    public function present(): array
    {
        return [
            'id' => (string) $this->getKey(),
            'type' => $this->type,
            'title' => $this->title,
            'body' => $this->body,
            'link' => $this->link,
            'data' => $this->data,
            'read' => $this->read_at !== null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
