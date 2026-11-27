<?php

namespace App\Modules\Platform\Models;

use MongoDB\Laravel\Eloquent\Model;

/**
 * Our client record for one school (platform level, not school data): who
 * we deal with and what they pay. Every change is in the platform audit
 * log. Payments are separate, append-only records (SchoolPayment).
 *
 * @property string $id
 * @property string $school_id
 * @property string|null $contact_person
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $address
 * @property string|null $package_name
 * @property float|int|null $price in rupees
 * @property string|null $billing_period monthly | quarterly | half_yearly | yearly
 * @property string|null $paid_until Y-m-d
 * @property string|null $notes
 */
class SchoolClient extends Model
{
    public const BILLING_PERIODS = ['monthly', 'quarterly', 'half_yearly', 'yearly'];

    public const FIELDS = ['contact_person', 'phone', 'email', 'address', 'package_name', 'price', 'billing_period', 'paid_until', 'notes'];

    protected $connection = 'mongodb';

    protected $table = 'school_clients';

    /** @var list<string> */
    protected $fillable = ['school_id', ...self::FIELDS];

    /**
     * @return array<string, mixed>
     */
    public function present(): array
    {
        return array_merge(array_fill_keys(self::FIELDS, null), array_intersect_key($this->getAttributes(), array_flip(self::FIELDS)));
    }
}
