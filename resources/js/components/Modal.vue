<script setup>
import { watch, onBeforeUnmount } from 'vue';
import { X } from '@lucide/vue';

const open = defineModel({ type: Boolean, default: false });
defineProps({ title: String, wide: Boolean });

const onKey = (e) => { if (e.key === 'Escape') open.value = false; };
watch(open, (v) => { document.body.style.overflow = v ? 'hidden' : ''; v ? addEventListener('keydown', onKey) : removeEventListener('keydown', onKey); });
onBeforeUnmount(() => { document.body.style.overflow = ''; removeEventListener('keydown', onKey); });
</script>

<template>
    <Teleport to="body">
        <Transition enter-from-class="opacity-0" leave-to-class="opacity-0" enter-active-class="transition" leave-active-class="transition">
            <div v-if="open" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-navy-900/40 p-4 backdrop-blur-[2px] sm:p-8" @mousedown.self="open = false">
                <div class="card w-full shadow-2xl" :class="wide ? 'max-w-4xl' : 'max-w-lg'" dir="rtl">
                    <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                        <h2 class="text-lg font-semibold text-navy">{{ title }}</h2>
                        <button type="button" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600" @click="open = false"><X class="size-5" /></button>
                    </div>
                    <div class="px-6 py-5"><slot /></div>
                    <div v-if="$slots.footer" class="flex justify-end gap-2 border-t border-slate-100 bg-slate-50/60 px-6 py-4 rounded-b-2xl"><slot name="footer" /></div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
