<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { EditorState } from '@codemirror/state';
import { EditorView, keymap, lineNumbers } from '@codemirror/view';
import { defaultKeymap, history, historyKeymap } from '@codemirror/commands';
import { css } from '@codemirror/lang-css';
import { html } from '@codemirror/lang-html';
import { defaultHighlightStyle, syntaxHighlighting } from '@codemirror/language';
import { autocompletion } from '@codemirror/autocomplete';
import type { CompletionSource } from '@codemirror/autocomplete';
import { lintGutter, setDiagnostics } from '@codemirror/lint';
import type { Diagnostic } from '@codemirror/lint';

const props = defineProps<{
  modelValue: string;
  language: 'html' | 'css';
  label: string;
  completion?: CompletionSource;
  diagnostics?: (doc: EditorState['doc']) => Diagnostic[];
}>();
const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

const host = ref<HTMLElement | null>(null);
let view: EditorView | null = null;

function refreshDiagnostics(): void {
  if (view === null || props.diagnostics === undefined) return;
  view.dispatch(setDiagnostics(view.state, props.diagnostics(view.state.doc)));
}

onMounted(() => {
  if (host.value === null) return;
  view = new EditorView({
    parent: host.value,
    state: EditorState.create({
      doc: props.modelValue,
      extensions: [
        lineNumbers(),
        history(),
        keymap.of([...defaultKeymap, ...historyKeymap]),
        syntaxHighlighting(defaultHighlightStyle),
        props.language === 'html' ? html() : css(),
        lintGutter(),
        EditorView.lineWrapping,
        EditorView.contentAttributes.of({ 'aria-label': props.label }),
        ...(props.completion === undefined
          ? []
          : [autocompletion({ override: [props.completion] })]),
        EditorView.updateListener.of((update) => {
          if (update.docChanged) emit('update:modelValue', update.state.doc.toString());
        }),
      ],
    }),
  });
  refreshDiagnostics();
});

watch(
  () => props.modelValue,
  (value) => {
    if (view !== null && value !== view.state.doc.toString()) {
      view.dispatch({ changes: { from: 0, to: view.state.doc.length, insert: value } });
    }
  },
);
watch(() => props.diagnostics, refreshDiagnostics);

onBeforeUnmount(() => {
  view?.destroy();
  view = null;
});

/** Inserts text at the cursor (placeholder panel). */
function insert(text: string): void {
  if (view === null) return;
  const { from, to } = view.state.selection.main;
  view.dispatch({ changes: { from, to, insert: text }, selection: { anchor: from + text.length } });
  view.focus();
}

/** Re-evaluates the diagnostics after the findings changed. */
function refresh(): void {
  refreshDiagnostics();
}

defineExpose({ insert, refresh });
</script>

<template>
  <div ref="host" class="swp-code-editor" :data-language="language" />
</template>
