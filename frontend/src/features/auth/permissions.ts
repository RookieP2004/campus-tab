import type { AccessScope, Me } from './api';

/**
 * Whether the person holds an ability, at any scope or at one scope.
 * Permissions read like "students.view:class". Only for hiding what they
 * cannot do; the server checks every request.
 */
export function holds(me: Pick<Me, 'permissions'>, ability: string, scope?: string): boolean {
  return me.permissions.some((p) => (scope ? p === `${ability}:${scope}` : p === ability || p.startsWith(`${ability}:`)));
}

const emptyScope: AccessScope = { children: [], class_sections: [], subject_sections: [], wings: [] };

/** The person's scope facts, never undefined. */
export function scopeOf(me: Pick<Me, 'scope'>): AccessScope {
  return { ...emptyScope, ...me.scope };
}
