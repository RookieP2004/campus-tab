<?php

use App\Support\Privacy\Aadhaar;
use Tests\Concerns\BuildsSchoolRecords;

/*
| A record id is never mistaken for a full Aadhaar number. MongoDB ids are
| 24 random hex characters, so now and then one holds a 12-digit run that
| passes the Verhoeff check; the guard used to refuse such a request, and
| tests that sent ids ("a subject teacher adds a column", "a parent applies
| for a child") failed now and then.
*/

it('does not take a record id for an Aadhaar number', function () {
    $aadhaar = BuildsSchoolRecords::fakeAadhaar();
    $objectId = 'ab'.$aadhaar.'cdef0123ab';
    $ulid = '01J'.$aadhaar.'ABCDEFGHJKM';

    expect(strlen($objectId))->toBe(24)->and(strlen($ulid))->toBe(26)
        ->and(Aadhaar::containsFullNumber($objectId))->toBeFalse()
        ->and(Aadhaar::containsFullNumber($objectId.':'.$objectId))->toBeFalse()
        ->and(Aadhaar::containsFullNumber($ulid))->toBeFalse()
        ->and(Aadhaar::findIn(['student_id' => $objectId, 'scope' => ['ref' => $objectId.':'.$objectId]]))->toBe([]);
});

it('still finds a real Aadhaar number, alone or in text', function () {
    $aadhaar = BuildsSchoolRecords::fakeAadhaar();

    expect(Aadhaar::containsFullNumber($aadhaar))->toBeTrue()
        ->and(Aadhaar::containsFullNumber('Aadhaar '.substr($aadhaar, 0, 4).' '.substr($aadhaar, 4, 4).' '.substr($aadhaar, 8)))->toBeTrue()
        ->and(Aadhaar::containsFullNumber('note: '.$aadhaar.' given at admission'))->toBeTrue()
        // Next to an id, the number itself is still caught.
        ->and(Aadhaar::containsFullNumber('ab12cd34ef56ab78cd90ef12 '.$aadhaar))->toBeTrue();
});
