import { getConfig } from '@/config';
import type { Capability } from '@/types/config';

/** UX only: hides actions the user cannot perform. The server stays authoritative. */
export function useCapabilities() {
  const caps = new Set(getConfig().capabilities);
  return { can: (cap: Capability): boolean => caps.has(cap) };
}
