<?php

namespace App\Modules\Tenancy\Domain;

/**
 * The difference between two config bodies as a flat list of changed
 * settings:  [{ "path": "policies.leave.chain", "from": [...], "to": [...] }]
 *
 * Objects are walked key by key; lists (such as terms or the leave chain)
 * are compared as one value, so changing one term shows the whole list.
 * A setting that was added has "from": null; one that was removed has
 * "to": null.
 */
class ConfigDiff
{
    /**
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     * @return list<array{path: string, from: mixed, to: mixed}>
     */
    public static function between(array $from, array $to): array
    {
        $old = self::flatten($from);
        $new = self::flatten($to);
        $paths = array_unique([...array_keys($old), ...array_keys($new)]);
        sort($paths);

        $changes = [];

        foreach ($paths as $path) {
            $before = $old[$path] ?? null;
            $after = $new[$path] ?? null;

            if ($before !== $after) {
                $changes[] = ['path' => (string) $path, 'from' => $before, 'to' => $after];
            }
        }

        return $changes;
    }

    /**
     * @param  list<array{path: string, from: mixed, to: mixed}>  $diff
     * @return list<string>
     */
    public static function paths(array $diff): array
    {
        return array_map(fn (array $change) => $change['path'], $diff);
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<string, mixed>
     */
    private static function flatten(array $value, string $prefix = ''): array
    {
        $flat = [];

        foreach ($value as $key => $item) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($item) && $item !== [] && ! array_is_list($item)) {
                $flat += self::flatten($item, $path);
            } else {
                $flat[$path] = $item;
            }
        }

        return $flat;
    }
}
