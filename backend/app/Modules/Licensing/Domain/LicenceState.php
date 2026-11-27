<?php

namespace App\Modules\Licensing\Domain;

/**
 * What a licence allows right now.
 *
 *  active    - everything
 *  grace     - everything, with a banner (X-Licence-State: grace);
 *              lasts licensing.grace_days after valid_to
 *  read_only - reads and exports only; data is never deleted for expiry
 *  inactive  - revoked, not yet activated, not yet valid, or the signature
 *              does not match
 */
enum LicenceState: string
{
    case Active = 'active';
    case Grace = 'grace';
    case ReadOnly = 'read_only';
    case Inactive = 'inactive';
}
