<script setup>
// A popover anchored to its trigger, rendered in <body> so tables and cards never clip it.
import { nextTick, onBeforeUnmount, ref } from 'vue';

const props = defineProps({ width: { type: String, default: 'w-56' }, disabled: Boolean, align: { type: String, default: 'start' } });
const emit = defineEmits(['open', 'close']);
const open = ref(false);
const trigger = ref(null);
const panel = ref(null);
const style = ref({});

function place() {
    const r = trigger.value?.getBoundingClientRect();
    if (!r) return;
    const w = panel.value?.offsetWidth ?? 224;
    const h = panel.value?.offsetHeight ?? 200;
    // RTL: "start" is the right edge.
    let left = props.align === 'start' ? r.right - w : r.left;
    left = Math.max(8, Math.min(left, window.innerWidth - w - 8));
    let top = r.bottom + 4;
    if (top + h > window.innerHeight - 8 && r.top - h - 4 > 8) top = r.top - h - 4;
    style.value = { top: `${top}px`, left: `${left}px` };
}
function onDoc(e) {
    if (!panel.value?.contains(e.target) && !trigger.value?.contains(e.target)) close();
}
function onKey(e) { if (e.key === 'Escape') close(); }
async function toggle() {
    if (props.disabled) return;
    open.value ? close() : show();
}
async function show() {
    open.value = true;
    emit('open');
    await nextTick();
    place();
    document.addEventListener('mousedown', onDoc);
    document.addEventListener('keydown', onKey);
    window.addEventListener('scroll', place, true);
    window.addEventListener('resize', place);
}
function close() {
    if (!open.value) return;
    open.value = false;
    emit('close');
    document.removeEventListener('mousedown', onDoc);
    document.removeEventListener('keydown', onKey);
    window.removeEventListener('scroll', place, true);
    window.removeEventListener('resize', place);
}
onBeforeUnmount(close);
defineExpose({ close, show });
</script>

<template>
    <span ref="trigger" class="inline-flex" @click.stop="toggle"><slot name="trigger" :open="open" /></span>
    <Teleport to="body">
        <div v-if="open" ref="panel" dir="rtl" class="card fixed z-[60] max-h-80 overflow-y-auto p-1 shadow-xl" :class="width" :style="style" @click.stop>
            <slot :close="close" />
        </div>
    </Teleport>
</template>
