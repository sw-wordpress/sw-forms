/** Mirrors Core/Validation/TagValues; tags are plain strings, never HTML. */
export function parseTags(raw: unknown): string[] {
  const tags: string[] = [];
  for (const value of Array.isArray(raw) ? raw : [raw]) {
    if (typeof value !== 'string') continue;
    for (const part of value.split(/[,\r\n]+/)) {
      const tag = Array.from(part.replace(/^[ \t\r\n\0\v]+|[ \t\r\n\0\v]+$/g, '')).slice(0, 201).join('');
      if (tag !== '' && !tags.includes(tag)) {
        tags.push(tag);
        if (tags.length === 101) return tags;
      }
    }
  }
  return tags;
}
