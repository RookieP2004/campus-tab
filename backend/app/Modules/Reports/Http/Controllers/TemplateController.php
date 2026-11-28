<?php

namespace App\Modules\Reports\Http\Controllers;

use App\Modules\Reports\Models\ReportTemplate;
use App\Support\Http\ApiException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Report card templates (the Principal).
 *
 *   GET    /report-templates                 the school's templates
 *   GET    /report-templates/presets         starter layouts (starting points only)
 *   POST   /report-templates { name, grades[], blocks[] | preset }
 *   PUT    /report-templates/{id} { name?, grades?, blocks? }
 *   POST   /report-templates/{id}/letterhead  multipart "file" (png or jpg)
 *   DELETE /report-templates/{id}/letterhead
 */
class TemplateController
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => ReportTemplate::query()->orderBy('name')->get()->map(fn (ReportTemplate $t) => self::present($t))->values()]);
    }

    public function presets(): JsonResponse
    {
        $out = [];
        foreach ((array) config('reports.template_presets') as $key => $preset) {
            $out[] = ['key' => $key, 'label' => $preset['label'], 'blocks' => $preset['blocks'], 'note' => config('reports.preset_note')];
        }

        return response()->json(['data' => $out, 'blocks' => config('reports.blocks'), 'co_scholastic' => config('reports.co_scholastic')]);
    }

    public function store(Request $request): JsonResponse
    {
        $input = $this->validated($request, true);
        $preset = $input['preset'] ?? null;
        $blocks = $input['blocks'] ?? null;

        if ($blocks === null) {
            $blocks = $preset !== null ? (array) config("reports.template_presets.{$preset}.blocks") : (array) config('reports.blocks');
            $blocks = array_map(fn (string $type) => ['type' => $type, 'options' => []], $blocks);
        }
        $this->assertUniqueGrades($input['grades'] ?? [], null);

        $template = ReportTemplate::create(['name' => trim($input['name']), 'grades' => array_values($input['grades'] ?? []), 'blocks' => self::blocks($blocks), 'preset' => $preset]);

        return response()->json(['data' => self::present($template)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $template = ReportTemplate::query()->findOrFail($id);
        $input = $this->validated($request, false);
        if (isset($input['grades'])) {
            $this->assertUniqueGrades($input['grades'], $template);
        }
        $template->fill(array_filter([
            'name' => isset($input['name']) ? trim($input['name']) : null,
            'grades' => isset($input['grades']) ? array_values($input['grades']) : null,
            'blocks' => isset($input['blocks']) ? self::blocks($input['blocks']) : null,
        ], fn ($v) => $v !== null))->save();

        return response()->json(['data' => self::present($template)]);
    }

    public function letterhead(Request $request, string $id, TenantContext $context): JsonResponse
    {
        $template = ReportTemplate::query()->findOrFail($id);
        $request->validate(['file' => ['required', 'file', 'mimes:'.implode(',', (array) config('reports.letterhead.types')), 'max:'.(int) config('reports.letterhead.max_kb')]]);
        $file = $request->file('file');
        $ext = strtolower((string) $file?->extension());
        if ($file === null || ! in_array($ext, (array) config('reports.letterhead.types'), true)) {
            throw new ApiException('letterhead_type', 'Upload the letterhead as a PNG or JPG image.', 422);
        }
        $dir = 'tenants/'.$context->require().'/report-templates/'.$template->getKey();
        Storage::disk('local')->deleteDirectory($dir);
        $path = $dir.'/letterhead.'.$ext;
        Storage::disk('local')->put($path, (string) $file->get());
        $template->fill(['letterhead' => ['path' => $path, 'mime' => $ext === 'png' ? 'image/png' : 'image/jpeg']])->save();

        return response()->json(['data' => self::present($template)], 201);
    }

    public function removeLetterhead(string $id, TenantContext $context): JsonResponse
    {
        $template = ReportTemplate::query()->findOrFail($id);
        Storage::disk('local')->deleteDirectory('tenants/'.$context->require().'/report-templates/'.$template->getKey());
        $template->fill(['letterhead' => null])->save();

        return response()->json(['data' => self::present($template)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'min:2', 'max:80'],
            'grades' => ['sometimes', 'array', 'max:20'],
            'grades.*' => ['string', 'max:10'],
            'preset' => ['sometimes', 'nullable', Rule::in(array_keys((array) config('reports.template_presets')))],
            'blocks' => ['sometimes', 'array', 'min:1', 'max:12'],
            'blocks.*.type' => ['required', Rule::in((array) config('reports.blocks'))],
            'blocks.*.options' => ['sometimes', 'array'],
        ]);
    }

    /**
     * @param  list<string>  $grades
     */
    private function assertUniqueGrades(array $grades, ?ReportTemplate $except): void
    {
        foreach (ReportTemplate::query()->get() as $other) {
            if ($except !== null && (string) $other->getKey() === (string) $except->getKey()) {
                continue;
            }
            $both = array_intersect($grades, $other->grades);
            if ($both !== []) {
                throw new ApiException('template_grades_taken', 'Class '.implode(', ', $both)." already uses the template \"{$other->name}\".", 422);
            }
        }
    }

    /**
     * @param  list<array{type: string, options?: array<string, mixed>}>  $blocks
     * @return list<array{type: string, options: array<string, mixed>}>
     */
    private static function blocks(array $blocks): array
    {
        $seen = [];
        $out = [];
        foreach ($blocks as $b) {
            if (isset($seen[$b['type']])) {
                throw new ApiException('block_duplicate', 'Each block can appear once.', 422, ['type' => $b['type']]);
            }
            $seen[$b['type']] = true;
            $out[] = ['type' => $b['type'], 'options' => (array) ($b['options'] ?? [])];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(ReportTemplate $t): array
    {
        return [
            'id' => (string) $t->getKey(),
            'name' => $t->name,
            'grades' => $t->grades,
            'blocks' => $t->blocks,
            'preset' => $t->preset,
            'has_letterhead' => $t->letterhead !== null,
            'updated_at' => $t->updated_at?->toIso8601String(),
        ];
    }
}
