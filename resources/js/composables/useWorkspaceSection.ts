import { router, usePage, useRemember } from '@inertiajs/vue3';
import { computed, unref, watch } from 'vue';

export function useWorkspaceSection(
    sections: string[],
    defaultSection: string,
    key: string,
) {
    const page = usePage();
    const fromUrl = () =>
        new URL(page.url, 'http://localhost').searchParams.get('section');
    const initial = fromUrl();
    const state = useRemember(
        {
            section:
                initial && sections.includes(initial)
                    ? initial
                    : defaultSection,
        },
        key,
    );
    const section = computed({
        get: () => unref(state).section,
        set: (value: string) => {
            unref(state).section = value;
        },
    });
    watch(
        () => page.url,
        () => {
            const value = fromUrl();

            if (value && sections.includes(value)) {
                section.value = value;
            }
        },
    );
    function selectSection(value: string) {
        if (!sections.includes(value)) {
            return;
        }

        section.value = value;
        const url = new URL(page.url, 'http://localhost');
        url.searchParams.set('section', value);
        router.push({
            url: url.pathname + url.search,
            preserveState: true,
            preserveScroll: true,
        });
    }

    return { section, selectSection };
}
