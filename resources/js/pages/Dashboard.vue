<script setup lang="ts">
import { Head, usePage, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import { logout } from '@/routes';
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
    <div class="flex min-h-screen items-center justify-center bg-black text-white dark:bg-black relative">
        <div class="flex flex-col items-center justify-center space-y-8">
            <h1 class="text-3xl font-bold tracking-tight">Selamat Datang, {{ userName }}</h1>
            
            <Link href="/fight-match-control">
                <Button size="lg" class="text-lg px-8 py-6 rounded-xl bg-red-600 hover:bg-red-700 text-white font-semibold">
                    Tanding Olahraga
                </Button>
            </Link>
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
