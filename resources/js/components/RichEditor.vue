<script setup>
// Tiptap editor (RTL) with @mentions and pasted / dropped images.
// Value: a ProseMirror JSON document or null. Images are uploaded through `uploader(file) -> { src }`.
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { useEditor, EditorContent } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import Mention from '@tiptap/extension-mention';
import Image from '@tiptap/extension-image';
import { TaskList, TaskItem } from '@tiptap/extension-list';
import { Placeholder } from '@tiptap/extensions';
import { Bold, Italic, Strikethrough, List, ListOrdered, ListTodo, Link2, Quote, Code, Heading2, ImagePlus, Loader2 } from '@lucide/vue';
import Avatar from '@/components/Avatar.vue';

const props = defineProps({
    modelValue: { type: Object, default: null },
    users: { type: Array, default: () => [] },
    editable: { type: Boolean, default: true },
    placeholder: { type: String, default: 'اكتب هنا… استخدم @ للإشارة إلى زميل' },
    uploader: { type: Function, default: null },
    compact: Boolean,
    toolbar: { type: Boolean, default: true },
});
const emit = defineEmits(['update:modelValue', 'blur', 'submit', 'error']);
const uploading = ref(0);
const fileInput = ref(null);

// Mention suggestion state, rendered below the caret.
const sug = reactive({ open: false, items: [], index: 0, rect: null, command: null });
const sugStyle = computed(() => (sug.rect ? { top: `${sug.rect.bottom + 6}px`, left: `${Math.max(8, sug.rect.right - 224)}px` } : {}));

const mention = Mention.configure({
    HTMLAttributes: { class: 'mention' },
    renderText: ({ node }) => `@${node.attrs.label ?? node.attrs.id}`,
    suggestion: {
        char: '@',
        items: ({ query }) => props.users.filter((u) => !query || u.name.includes(query)).slice(0, 8).map((u) => ({ id: String(u.id), label: u.name })),
        render: () => ({
            onStart: (p) => { Object.assign(sug, { open: true, items: p.items, index: 0, rect: p.clientRect?.(), command: p.command }); },
            onUpdate: (p) => { Object.assign(sug, { items: p.items, index: 0, rect: p.clientRect?.(), command: p.command }); },
            onKeyDown: ({ event }) => {
                if (!sug.open || !sug.items.length) return false;
                if (event.key === 'ArrowDown') { sug.index = (sug.index + 1) % sug.items.length; return true; }
                if (event.key === 'ArrowUp') { sug.index = (sug.index + sug.items.length - 1) % sug.items.length; return true; }
                if (event.key === 'Enter' || event.key === 'Tab') { pick(sug.items[sug.index]); return true; }
                if (event.key === 'Escape') { sug.open = false; return true; }
                return false;
            },
            onExit: () => { sug.open = false; },
        }),
    },
});
const pick = (item) => { if (item && sug.command) sug.command(item); sug.open = false; };

async function uploadFiles(files, view, pos) {
    if (!props.uploader) { emit('error', 'لا يمكن إضافة صور هنا.'); return; }
    for (const file of files) {
        uploading.value++;
        try {
            const res = await props.uploader(file);
            const node = view.state.schema.nodes.image.create({ src: res.src, alt: res.name ?? file.name });
            const at = pos ?? view.state.selection.from;
            view.dispatch(view.state.tr.insert(Math.min(at, view.state.doc.content.size), node));
        } catch (e) {
            emit('error', e.message);
        } finally {
            uploading.value--;
        }
    }
}
const images = (list) => Array.from(list ?? []).filter((f) => f.type.startsWith('image/'));

const editor = useEditor({
    content: props.modelValue,
    editable: props.editable,
    extensions: [
        StarterKit.configure({ heading: { levels: [2, 3] }, link: { openOnClick: !props.editable, autolink: true, HTMLAttributes: { rel: 'noopener noreferrer', target: '_blank' } } }),
        Image.configure({ HTMLAttributes: { class: 'rich-image' } }),
        TaskList, TaskItem.configure({ nested: true }),
        Placeholder.configure({ placeholder: props.placeholder }),
        mention,
    ],
    editorProps: {
        attributes: { dir: 'auto', class: 'rich-content focus:outline-none' },
        handlePaste: (view, event) => {
            const files = images(event.clipboardData?.files);
            if (!files.length) return false;
            uploadFiles(files, view);
            return true;
        },
        handleDrop: (view, event) => {
            const files = images(event.dataTransfer?.files);
            if (!files.length) return false;
            const pos = view.posAtCoords({ left: event.clientX, top: event.clientY })?.pos;
            uploadFiles(files, view, pos);
            return true;
        },
        handleKeyDown: (view, event) => {
            if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') { emit('submit'); return true; }
            return false;
        },
    },
    onUpdate: ({ editor: e }) => emit('update:modelValue', e.isEmpty ? null : e.getJSON()),
    onBlur: () => emit('blur'),
});

watch(() => props.modelValue, (v) => {
    const e = editor.value;
    if (!e) return;
    const current = e.isEmpty ? null : e.getJSON();
    if (JSON.stringify(current) !== JSON.stringify(v ?? null)) e.commands.setContent(v ?? '', { emitUpdate: false });
});
watch(() => props.editable, (v) => editor.value?.setEditable(v));
onBeforeUnmount(() => editor.value?.destroy());

const setLink = () => {
    const prev = editor.value.getAttributes('link').href ?? '';
    const url = window.prompt('الرابط', prev);
    if (url === null) return;
    if (!url) editor.value.chain().focus().unsetLink().run();
    else editor.value.chain().focus().extendMarkRange('link').setLink({ href: /^https?:\/\//.test(url) ? url : `https://${url}` }).run();
};
const pickImage = (e) => { uploadFiles(images(e.target.files), editor.value.view); e.target.value = ''; };
const tools = computed(() => [
    { icon: Bold, title: 'عريض', run: () => editor.value.chain().focus().toggleBold().run(), active: 'bold' },
    { icon: Italic, title: 'مائل', run: () => editor.value.chain().focus().toggleItalic().run(), active: 'italic' },
    { icon: Strikethrough, title: 'يتوسطه خط', run: () => editor.value.chain().focus().toggleStrike().run(), active: 'strike' },
    { icon: Heading2, title: 'عنوان', run: () => editor.value.chain().focus().toggleHeading({ level: 2 }).run(), active: 'heading' },
    { icon: List, title: 'قائمة نقطية', run: () => editor.value.chain().focus().toggleBulletList().run(), active: 'bulletList' },
    { icon: ListOrdered, title: 'قائمة مرقمة', run: () => editor.value.chain().focus().toggleOrderedList().run(), active: 'orderedList' },
    { icon: ListTodo, title: 'قائمة مهام', run: () => editor.value.chain().focus().toggleTaskList().run(), active: 'taskList' },
    { icon: Quote, title: 'اقتباس', run: () => editor.value.chain().focus().toggleBlockquote().run(), active: 'blockquote' },
    { icon: Code, title: 'كود', run: () => editor.value.chain().focus().toggleCode().run(), active: 'code' },
    { icon: Link2, title: 'رابط', run: setLink, active: 'link' },
]);

defineExpose({
    clear: () => editor.value?.commands.clearContent(true),
    focus: () => editor.value?.commands.focus('end'),
    isEmpty: () => editor.value?.isEmpty ?? true,
});
</script>

<template>
    <div class="rich-editor" :class="editable ? 'rounded-xl border border-slate-200 bg-white focus-within:border-brand focus-within:ring-4 focus-within:ring-brand/10' : ''">
        <div v-if="editable && toolbar && editor" class="flex flex-wrap items-center gap-0.5 border-b border-slate-100 px-1.5 py-1">
            <button v-for="t in tools" :key="t.title" type="button" class="rounded-md p-1.5 text-slate-500 hover:bg-slate-100" :class="{ 'bg-slate-100 text-navy': editor.isActive(t.active) }" :title="t.title" @mousedown.prevent="t.run()">
                <component :is="t.icon" class="size-4" />
            </button>
            <template v-if="uploader">
                <button type="button" class="rounded-md p-1.5 text-slate-500 hover:bg-slate-100" title="صورة (أو الصقها مباشرة)" @mousedown.prevent="fileInput?.click()"><ImagePlus class="size-4" /></button>
                <input ref="fileInput" type="file" accept="image/*" multiple class="hidden" @change="pickImage" />
            </template>
            <span v-if="uploading" class="ms-auto flex items-center gap-1 px-2 text-xs text-slate-500"><Loader2 class="size-3.5 animate-spin" /> رفع الصورة…</span>
            <slot name="toolbar-end" />
        </div>
        <EditorContent :editor="editor" :class="editable ? (compact ? 'min-h-[3.5rem] px-3 py-2' : 'min-h-[8rem] px-4 py-3') : ''" />
        <slot />
        <Teleport to="body">
            <div v-if="sug.open && sug.items.length" class="card fixed z-[70] w-56 p-1 shadow-xl" dir="rtl" :style="sugStyle">
                <button v-for="(u, i) in sug.items" :key="u.id" type="button" class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-sm"
                    :class="i === sug.index ? 'bg-brand/10 text-navy' : 'hover:bg-slate-50'" @mousedown.prevent="pick(u)">
                    <Avatar :name="u.label" /> {{ u.label }}
                </button>
            </div>
        </Teleport>
    </div>
</template>
