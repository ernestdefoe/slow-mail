import type PageStructureComponent from 'flarum/forum/components/PageStructure';
import type IndexSidebarComponent from 'flarum/forum/components/IndexSidebar';
import type LogInModalComponent from 'flarum/forum/components/LogInModal';

/** The part of the `flarum` global this extension reads: core's module registry, for its lazy chunks. */
interface FlarumRuntime {
  reg: { asyncModuleImport(path: string): Promise<unknown> };
}

const registry = () => (globalThis as unknown as { flarum: FlarumRuntime }).flarum.reg;

function defaultExport<T>(mod: unknown): T {
  const record = mod as { default?: T } | undefined;
  return (record?.default ?? mod) as T;
}

/** Core components the page builds on, loaded through the registry: they live in core's lazy chunks. */
export let PageStructure: typeof PageStructureComponent;
export let IndexSidebar: typeof IndexSidebarComponent;

export async function loadCore(): Promise<void> {
  if (PageStructure && IndexSidebar) return;
  const [structure, sidebar] = await Promise.all([
    registry().asyncModuleImport('flarum/forum/components/PageStructure'),
    registry().asyncModuleImport('flarum/forum/components/IndexSidebar'),
  ]);
  PageStructure = defaultExport<typeof PageStructureComponent>(structure);
  IndexSidebar = defaultExport<typeof IndexSidebarComponent>(sidebar);
}

export const logInModal = () =>
  registry()
    .asyncModuleImport('flarum/forum/components/LogInModal')
    .then((m) => defaultExport<typeof LogInModalComponent>(m));
