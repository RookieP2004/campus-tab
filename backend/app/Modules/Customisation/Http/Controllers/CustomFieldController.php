<?php

namespace App\Modules\Customisation\Http\Controllers;

use App\Modules\Customisation\Domain\CustomFields;
use App\Modules\Customisation\Domain\FieldRules;
use App\Modules\Customisation\Models\CustomField;
use App\Modules\Identity\Models\User;
use App\Support\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Custom fields (columns and form fields), filtered by the user's level.
 *
 *   GET   /custom-fields ?entity ?section_id ?include_archived   the fields you may see
 *   POST  /custom-fields { entity, label{en,hi?}, type, options?, required?, sensitive?, scope{level,ref?}, visible_to? }
 *   PATCH /custom-fields/{id} { label?, options?, required?, visible_to?, order? }
 *   POST  /custom-fields/{id}/archive · /restore · /promote
 *
 * Every change is checked against the owner level and scope (FieldRules).
 */
class CustomFieldController
{
    public function __construct(
        private readonly FieldRules $rules,
        private readonly CustomFields $fields,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', CustomField::class);
        $user = $this->user($request);

        $entity = $request->query('entity');
        $entities = is_string($entity) && in_array($entity, CustomField::ENTITIES, true) ? [$entity] : CustomField::ENTITIES;
        $section = $request->query('section_id');
        $section = is_string($section) && $section !== '' ? $section : null;

        $fields = $this->rules->visibleFields($user, $entities, $section);

        if ($request->boolean('include_archived')) {
            $archived = CustomField::query()->whereIn('entity', $entities)->where('status', 'archived')->get()
                ->filter(fn (CustomField $f) => $this->rules->canChange($user, $f));
            $fields = $fields->concat($archived)->values();
        }

        return response()->json(['data' => $fields->map(fn (CustomField $f) => $this->present($user, $f))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', CustomField::class);

        $input = $request->validate([
            'entity' => ['required', Rule::in(CustomField::ENTITIES)],
            'label' => ['required', 'array'],
            'label.en' => ['required', 'string', 'min:1', 'max:60'],
            'label.*' => ['nullable', 'string', 'max:60'],
            'type' => ['required', 'string', 'max:20'],
            'options' => ['sometimes', 'array', 'max:100'],
            'options.*' => ['string', 'max:60', 'distinct'],
            'required' => ['sometimes', 'boolean'],
            'sensitive' => ['sometimes', 'boolean'],
            'scope' => ['required', 'array'],
            'scope.level' => ['required', Rule::in(CustomField::SCOPE_LEVELS)],
            'scope.ref' => ['nullable', 'string', 'max:80'],
            'visible_to' => ['sometimes', 'array'],
            'visible_to.*' => ['string', Rule::in(['subject_teacher', 'class_teacher'])],
        ]);

        // Checked before the type list, so an "aadhaar" type gets the clear refusal.
        CustomFields::refuseAadhaarOrBank($input['label'], $input['type']);

        if (! in_array($input['type'], CustomField::TYPES, true)) {
            throw new ApiException('custom_field_type_invalid', 'Choose one of the field types.', 422, ['types' => CustomField::TYPES]);
        }

        $field = $this->fields->define($this->user($request), $input);

        return response()->json(['data' => $this->present($this->user($request), $field)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $field = CustomField::query()->findOrFail($id);
        Gate::authorize('update', $field);

        $input = $request->validate([
            'label' => ['sometimes', 'array'],
            'label.en' => ['required_with:label', 'string', 'max:60'],
            'label.*' => ['nullable', 'string', 'max:60'],
            'options' => ['sometimes', 'array', 'max:100'],
            'options.*' => ['string', 'max:60', 'distinct'],
            'required' => ['sometimes', 'boolean'],
            'visible_to' => ['sometimes', 'array'],
            'visible_to.*' => ['string', Rule::in(['subject_teacher', 'class_teacher'])],
            'order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ]);

        $field = $this->fields->change($this->user($request), $field, $input);

        return response()->json(['data' => $this->present($this->user($request), $field)]);
    }

    public function archive(Request $request, string $id): JsonResponse
    {
        $field = CustomField::query()->findOrFail($id);
        Gate::authorize('update', $field);

        return response()->json(['data' => $this->present($this->user($request), $this->fields->archive($this->user($request), $field))]);
    }

    public function restore(Request $request, string $id): JsonResponse
    {
        $field = CustomField::query()->findOrFail($id);
        Gate::authorize('update', $field);

        return response()->json(['data' => $this->present($this->user($request), $this->fields->archive($this->user($request), $field, false))]);
    }

    public function promote(Request $request, string $id): JsonResponse
    {
        $field = CustomField::query()->findOrFail($id);
        Gate::authorize('update', $field);

        return response()->json(['data' => $this->present($this->user($request), $this->fields->promote($this->user($request), $field))]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(User $user, CustomField $f): array
    {
        return [
            'id' => (string) $f->getKey(),
            'entity' => $f->entity,
            'key' => $f->key,
            'label' => $f->label,
            'type' => $f->type,
            'options' => $f->options,
            'required' => $f->required,
            'sensitive' => $f->sensitive,
            'scope' => $f->scope,
            'owner_level' => $f->owner_level,
            'owner' => $f->owner,
            'visible_to' => $f->visible_to,
            'status' => $f->status,
            'order' => $f->order,
            'can_change' => $this->rules->canChange($user, $f),
            'can_fill' => $this->rules->canFill($user, $f),
        ];
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new ApiException('school_account_required', 'Sign in with a school account to continue.', 403);
    }
}
