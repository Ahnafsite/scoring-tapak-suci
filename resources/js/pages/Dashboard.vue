<script setup lang="ts">
import { Head, usePage, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
    logout,
    fightMatchControl,
    fightSecretary,
    fightStreaming,
    fightJury,
} from '@/routes';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { LogOut } from 'lucide-vue-next';

const page = usePage<any>();
const userName = computed(() => page.props.auth?.user?.name || 'User');

const handleLogout = () => {
    router.flushAll();
};
</script>

<template>
    <Head title="Dashboard" />
    <div
        class="relative flex min-h-screen items-center justify-center bg-background text-foreground"
    >
        <div class="flex flex-col items-center justify-center space-y-8">
            <h1 class="text-3xl font-bold tracking-tight">
                Selamat Datang, {{ userName.replace('Juri', 'Pembantu Wasit') }}
            </h1>

            <template v-if="page.props.auth?.user?.role?.name === 'Operator'">
                <Link :href="fightMatchControl().url">
                    <Button
                        size="lg"
                        class="rounded-xl px-8 py-6 text-lg font-semibold"
                    >
                        Control Panel Tanding
                    </Button>
                </Link>
            </template>
            <template
                v-else-if="page.props.auth?.user?.role?.name === 'Sekretaris'"
            >
                <Link :href="fightSecretary().url">
                    <Button
                        size="lg"
                        class="rounded-xl px-8 py-6 text-lg font-semibold"
                    >
                        Sekretaris Pertandingan
                    </Button>
                </Link>
            </template>
            <template v-else-if="page.props.auth?.user?.role?.name === 'Juri'">
                <Link :href="fightJury().url">
                    <Button
                        size="lg"
                        class="rounded-xl px-8 py-6 text-lg font-semibold"
                    >
                        Tanding Olahraga
                    </Button>
                </Link>
            </template>
            <template
                v-else-if="page.props.auth?.user?.role?.name === 'Streamer'"
            >
                <Link :href="fightStreaming().url">
                    <Button
                        size="lg"
                        class="rounded-xl px-8 py-6 text-lg font-semibold"
                    >
                        Fight Streaming
                    </Button>
                </Link>
            </template>
        </div>

        <div class="fixed bottom-6 left-6">
            <Dialog>
                <DialogTrigger as-child>
                    <Button variant="secondary" class="shadow-lg">
                        <LogOut class="h-4 w-4" />
                    </Button>
                </DialogTrigger>
                <DialogContent class="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Konfirmasi Logout</DialogTitle>
                        <DialogDescription>
                            Apakah Anda yakin ingin keluar dari aplikasi?
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter class="sm:justify-start">
                        <DialogClose as-child>
                            <Button type="button" variant="secondary">
                                Batal
                            </Button>
                        </DialogClose>
                        <Link
                            :href="logout()"
                            @click="handleLogout"
                            as="button"
                        >
                            <Button type="button" variant="default">
                                Ya, Keluar
                            </Button>
                        </Link>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    </div>
</template>
