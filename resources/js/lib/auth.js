import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

export function useAuth() {
    const page = usePage();
    const user = computed(() => page.props.auth?.user ?? null);
    const perms = computed(() => page.props.auth?.permissions ?? {});
    const can = (key) => Boolean(perms.value['*'] || perms.value[key]);
    const canAny = (keys) => keys.some(can);
    return { user, perms, can, canAny };
}
