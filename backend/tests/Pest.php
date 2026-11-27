<?php

use Tests\TestCase;

/*
| Every test runs on the Laravel test case. Tests that touch MongoDB add
| `uses(Tests\Concerns\RefreshMongoDatabase::class);` at the top of the file.
*/

pest()->extend(TestCase::class)->in('Feature', 'Unit');
