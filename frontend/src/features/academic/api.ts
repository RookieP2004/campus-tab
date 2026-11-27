import { useQuery } from '@tanstack/react-query';
import { apiGet } from '@/lib/api';
import { send } from '@/lib/requests';

export interface AcademicYear {
  id: string;
  name: string;
  starts_on: string;
  ends_on: string;
  status: 'planned' | 'current' | 'closed';
}

export interface Section {
  id: string;
  class_id: string;
  name: string;
  label: string;
  wing: string | null;
  room: string | null;
  class_teacher_id: string | null;
  class_teacher_name: string | null;
}

export interface SchoolClass {
  id: string;
  year_id: string;
  grade: string;
  name: string;
  order: number;
  wing: string | null;
  sections: Section[];
}

export interface Subject {
  id: string;
  code: string;
  name: string;
  kind: 'scholastic' | 'co_scholastic';
  elective: boolean;
}

export interface TeachingAssignment {
  id: string;
  section_id: string;
  section_label: string | null;
  subject_id: string;
  subject_name: string | null;
  teacher_id: string;
  teacher_name: string | null;
}

export const academicKeys = {
  years: ['academic', 'years'] as const,
  classes: ['academic', 'classes'] as const,
  subjects: ['academic', 'subjects'] as const,
  assignments: ['academic', 'assignments'] as const,
};

export function useYears(enabled = true) {
  return useQuery({ queryKey: academicKeys.years, queryFn: apiGet<AcademicYear[]>('/academic-years'), enabled });
}

/** Classes of the current year with their sections. */
export function useClasses(enabled = true) {
  return useQuery({ queryKey: academicKeys.classes, queryFn: apiGet<SchoolClass[]>('/classes'), enabled, retry: false });
}

export function useSubjects(enabled = true) {
  return useQuery({ queryKey: academicKeys.subjects, queryFn: apiGet<Subject[]>('/subjects'), enabled, retry: false });
}

export function useAssignments(enabled = true) {
  return useQuery({ queryKey: academicKeys.assignments, queryFn: apiGet<TeachingAssignment[]>('/teaching-assignments'), enabled });
}

/** Every section of the current year, in class order. */
export function allSections(classes: SchoolClass[] | undefined): Section[] {
  return Array.isArray(classes) ? classes.flatMap((c) => c.sections ?? []) : [];
}

export const structure = {
  createYear: (body: { name: string; starts_on: string; ends_on: string; make_current: boolean }) => send<AcademicYear>('POST', '/academic-years', { body }),
  makeCurrent: (id: string) => send<AcademicYear>('POST', '/academic-years/{id}/make-current', { path: { id } }),
  createClass: (body: { grade: string; name: string; wing?: string; sections: string[] }) => send<SchoolClass>('POST', '/classes', { body }),
  deleteClass: (id: string) => send('DELETE', '/classes/{id}', { path: { id } }),
  createSection: (body: { class_id: string; name: string }) => send<Section>('POST', '/sections', { body }),
  updateSection: (id: string, body: { class_teacher_id: string | null }) => send<Section>('PATCH', '/sections/{id}', { path: { id }, body }),
  deleteSection: (id: string) => send('DELETE', '/sections/{id}', { path: { id } }),
  createSubject: (body: { code: string; name: string; kind: Subject['kind']; elective: boolean }) => send<Subject>('POST', '/subjects', { body }),
  assign: (body: { section_id: string; subject_id: string; teacher_id: string }) => send<TeachingAssignment>('POST', '/teaching-assignments', { body }),
  unassign: (id: string) => send('DELETE', '/teaching-assignments/{id}', { path: { id } }),
};
