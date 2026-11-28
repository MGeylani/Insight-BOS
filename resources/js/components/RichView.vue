<script setup>
// Read-only rendering of a rich-text document (no editor instance: cheap for long comment threads).
// The document is cleaned on the server (RichText::normalize) and generateHTML escapes all text.
import { computed } from 'vue';
import { generateHTML } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import Mention from '@tiptap/extension-mention';
import Image from '@tiptap/extension-image';
import { TaskList, TaskItem } from '@tiptap/extension-list';

const props = defineProps({ doc: Object });
const extensions = [
    StarterKit.configure({ link: { HTMLAttributes: { rel: 'noopener noreferrer', target: '_blank' } } }),
    Image.configure({ HTMLAttributes: { class: 'rich-image' } }),
    TaskList, TaskItem,
    Mention.configure({ HTMLAttributes: { class: 'mention' }, renderText: ({ node }) => `@${node.attrs.label}`, renderHTML: ({ node }) => ['span', { class: 'mention' }, `@${node.attrs.label}`] }),
];
const html = computed(() => {
    if (!props.doc) return '';
    try { return generateHTML(props.doc, extensions); } catch { return ''; }
});
</script>

<template>
    <div class="rich-content" dir="auto" v-html="html" />
</template>
