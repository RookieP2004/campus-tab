/** Joins class names, skipping empty ones. The package has no Tailwind merge step. */
export function cx(...parts: Array<string | false | null | undefined>): string {
  return parts.filter(Boolean).join(' ');
}
