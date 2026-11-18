import type { BuilderModel, BuilderNode, ContainerNode } from './model';

export type Path = number[];

export interface Row {
  path: Path;
  depth: number;
  node: BuilderNode;
}

/** The tree as a flat list (document order) for display. The root container is row zero. */
export function flatten(model: BuilderModel): Row[] {
  const rows: Row[] = [{ path: [], depth: 0, node: model.root }];
  const walk = (container: ContainerNode, base: Path, depth: number): void => {
    container.children.forEach((child, index) => {
      const path = [...base, index];
      rows.push({ path, depth, node: child });
      if (child.type === 'container') walk(child, path, depth + 1);
    });
  };
  walk(model.root, [], 1);
  return rows;
}

export function nodeAt(model: BuilderModel, path: Path): BuilderNode | null {
  let current: BuilderNode = model.root;
  for (const index of path) {
    if (current.type !== 'container') return null;
    const next: BuilderNode | undefined = current.children[index];
    if (next === undefined) return null;
    current = next;
  }
  return current;
}

const clone = (model: BuilderModel): BuilderModel =>
  JSON.parse(JSON.stringify(model)) as BuilderModel;

function parentOf(
  model: BuilderModel,
  path: Path,
): { container: ContainerNode; index: number } | null {
  if (path.length === 0) return null;
  const parent = nodeAt(model, path.slice(0, -1));
  const index = path[path.length - 1] as number;
  return parent !== null && parent.type === 'container' ? { container: parent, index } : null;
}

/** Adds a node to the container at `path` (or to the root when the target is no container). Returns the new model. */
export function addNode(
  model: BuilderModel,
  path: Path,
  node: BuilderNode,
): { model: BuilderModel; path: Path } {
  const next = clone(model);
  let target = nodeAt(next, path);
  let targetPath = path;
  if (target === null || target.type !== 'container') {
    target = next.root;
    targetPath = [];
  }
  (target as ContainerNode).children.push(node);
  return { model: next, path: [...targetPath, (target as ContainerNode).children.length - 1] };
}

export function removeNode(model: BuilderModel, path: Path): BuilderModel {
  const next = clone(model);
  const found = parentOf(next, path);
  if (found !== null) found.container.children.splice(found.index, 1);
  return next;
}

/** Moves a node one step within its container; returns the model and the node's new path. */
export function moveNode(
  model: BuilderModel,
  path: Path,
  direction: -1 | 1,
): { model: BuilderModel; path: Path } {
  const next = clone(model);
  const found = parentOf(next, path);
  if (found === null) return { model, path };
  const target = found.index + direction;
  if (target < 0 || target >= found.container.children.length) return { model, path };
  const [moved] = found.container.children.splice(found.index, 1);
  found.container.children.splice(target, 0, moved as BuilderNode);
  return { model: next, path: [...path.slice(0, -1), target] };
}

/** Replaces the settings of a node (the children of a container are kept). */
export function updateNode(
  model: BuilderModel,
  path: Path,
  patch: Record<string, unknown>,
): BuilderModel {
  const next = clone(model);
  const node = nodeAt(next, path);
  if (node !== null) Object.assign(node, patch);
  return next;
}
