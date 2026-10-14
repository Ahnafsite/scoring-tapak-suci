<script setup lang="ts">
import { Menu } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';

type Props = {
    /** Current sidebar state, for assistive tech and the tooltip. */
    open?: boolean;
};

const props = withDefaults(defineProps<Props>(), {
    open: false,
});

defineEmits<{ toggle: [] }>();
</script>

<template>
    <Button
        variant="ghost"
        size="icon"
        class="h-9 w-9 shrink-0 text-muted-foreground hover:text-foreground"
        :title="props.open ? 'Sembunyikan jadwal' : 'Tampilkan jadwal'"
        :aria-label="props.open ? 'Sembunyikan jadwal' : 'Tampilkan jadwal'"
        :aria-expanded="props.open"
        @click="$emit('toggle')"
    >
        <Menu class="h-5 w-5" />
    </Button>
</template>
