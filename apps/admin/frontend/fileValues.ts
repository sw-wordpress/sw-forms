/** Match IncomingFile::clean_name; file conditions use names, never local paths or stored URLs. */
export function fileNames(files: FileList | null): string[] {
  // eslint-disable-next-line no-control-regex -- keep the same plain-text names as PHP.
  return Array.from(files ?? []).map((file) => file.name.split(/[\\/]/).pop()!.replace(/[\u0000-\u001f\u007f]/g, '').replace(/^ +| +$/g, ''));
}
