/** Match IncomingFile::clean_name; file conditions use names, never local paths or stored URLs. */
export function fileNames(files: FileList | null): string[] {
  return Array.from(files ?? []).map((file) => {
    const basename = file.name.split(/[\\/]/).pop() ?? '';
    // eslint-disable-next-line no-control-regex -- keep the same plain-text names as PHP.
    const name = basename.replace(/[\u0000-\u001f\u007f]/g, '');
    // PHP trims ASCII spaces here, retaining Unicode spaces. Scan once without backtracking.
    let start = 0;
    let end = name.length;
    while (start < end && name[start] === ' ') start++;
    while (end > start && name[end - 1] === ' ') end--;
    return name.slice(start, end);
  });
}
