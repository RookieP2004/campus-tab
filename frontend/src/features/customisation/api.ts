import { useQuery } from '@tanstack/react-query';
import type { CustomColumn, FieldScope, FieldType, Localised } from '@/features/people/api';
import { apiGet } from '@/lib/api';
import { send } from '@/lib/requests';

export interface CustomFieldDef extends Omit<CustomColumn, 'can_fill'> {
  id: string;
  required: boolean;
  owner: { id: string; name: string } | null;
  visible_to: string[];
  status: 'active' | 'archived';
  order: number;
  can_change: boolean;
  can_fill: boolean;
}

export interface CustomFieldInput {
  entity: CustomColumn['entity'];
  label: Localised;
  type: FieldType;
  options?: string[];
  required: boolean;
  scope: FieldScope;
  visible_to?: string[];
}

export type CustomFieldPatch = Partial<Pick<CustomFieldInput, 'label' | 'options' | 'required' | 'visible_to'>>;

/** Types a school can create here (file and grade come with later modules). */
export const CREATABLE_TYPES: FieldType[] = ['text', 'long_text', 'number', 'date', 'dropdown', 'multi_select', 'yes_no', 'phone', 'email'];

export const customFieldsKey = ['custom-fields'] as const;

export function useCustomFields() {
  return useQuery({ queryKey: customFieldsKey, queryFn: apiGet<CustomFieldDef[]>('/custom-fields', { include_archived: 1 }) });
}

export const customFields = {
  create: (body: CustomFieldInput) => send<CustomFieldDef>('POST', '/custom-fields', { body }),
  update: (id: string, body: CustomFieldPatch) => send<CustomFieldDef>('PATCH', '/custom-fields/{id}', { path: { id }, body }),
  archive: (id: string) => send<CustomFieldDef>('POST', '/custom-fields/{id}/archive', { path: { id } }),
  restore: (id: string) => send<CustomFieldDef>('POST', '/custom-fields/{id}/restore', { path: { id } }),
  promote: (id: string) => send<CustomFieldDef>('POST', '/custom-fields/{id}/promote', { path: { id } }),
};
