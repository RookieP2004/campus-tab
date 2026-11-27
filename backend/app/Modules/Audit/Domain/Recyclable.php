<?php

namespace App\Modules\Audit\Domain;

/**
 * A soft-deleted school record that shows in the recycle bin. Register the
 * model in config('audit.recyclable') under its type name.
 */
interface Recyclable
{
    /** The type name used in /recycle-bin/{type}/{id}. */
    public function recycleType(): string;

    /** What the bin shows, and what the Chief types to confirm a purge. */
    public function recycleLabel(): string;
}
