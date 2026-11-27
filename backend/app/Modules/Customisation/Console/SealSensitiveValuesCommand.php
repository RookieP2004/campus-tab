<?php

namespace App\Modules\Customisation\Console;

use App\Modules\Customisation\Models\CustomField;
use App\Modules\People\Models\Enrolment;
use App\Modules\People\Models\Staff;
use App\Modules\People\Models\Student;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Privacy\SensitiveValue;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Encrypts any value of a sensitive custom field that is still stored in
 * plain text (values written before encryption existed, or by a field that
 * was made sensitive later). Safe to run again: sealed values are skipped.
 *
 *   php artisan customisation:seal-sensitive
 */
class SealSensitiveValuesCommand extends Command
{
    protected $signature = 'customisation:seal-sensitive';

    protected $description = 'Encrypt plain-text values of sensitive custom fields';

    public function handle(TenantContext $context): int
    {
        foreach (Tenant::query()->get() as $tenant) {
            $sealed = $context->run($tenant->id, function (): int {
                $count = 0;
                $models = ['student' => Student::class, 'enrolment' => Enrolment::class, 'staff' => Staff::class];

                foreach (CustomField::query()->withTrashed()->where('sensitive', true)->get() as $field) {
                    $model = $models[$field->entity] ?? null;

                    if ($model === null) {
                        continue;
                    }

                    foreach ($model::query()->whereNotNull('custom.'.$field->key)->get() as $record) {
                        $custom = $record->custom ?? [];
                        $value = $custom[$field->key] ?? null;

                        if ($value === null || SensitiveValue::isSealed($value)) {
                            continue;
                        }

                        $custom[$field->key] = SensitiveValue::seal($value);
                        $record->forceFill(['custom' => $custom])->save();
                        $count++;
                    }
                }

                return $count;
            });

            $this->line("{$tenant->code}: {$sealed} value(s) encrypted.");
        }

        return self::SUCCESS;
    }
}
