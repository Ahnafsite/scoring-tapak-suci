<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref, onMounted } from 'vue';
import axios from 'axios';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogFooter,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

const props = defineProps<{
    schedules: any[];
    arena: any;
}>();

const isSetupDialogOpen = ref(false);
const isLoading = ref(false);

const gelanggangList = ref<any[]>([]);
const sesiList = ref<any[]>([]);

const selectedGelanggang = ref('');
const selectedSesi = ref('');

const fetchGelanggang = async () => {
    try {
        const response = await axios.get('/api/source/gelanggang');
        if (response.data?.data) {
            gelanggangList.value = response.data.data;
        }
    } catch (e) {
        console.error('Failed to fetch gelanggang', e);
    }
};

const handleGelanggangChange = async (val: any) => {
    if (!val) return;
    selectedGelanggang.value = val.toString();
    selectedSesi.value = '';
    sesiList.value = [];
    try {
        const response = await axios.get(`/api/source/sesi/${val}`);
        if(response.data?.data) {
            sesiList.value = response.data.data;
        }
    } catch (e) {
        console.error('Failed to fetch sesi', e);
    }
};

const openSetup = () => {
    isSetupDialogOpen.value = true;
    fetchGelanggang();
};

const saveSetup = async () => {
    if (!selectedGelanggang.value || !selectedSesi.value) return;
    isLoading.value = true;
    try {
        const g = gelanggangList.value.find((i: any) => i.id.toString() === selectedGelanggang.value);
        
        await axios.post('/api/arena/setup', {
            gelanggang_id: selectedGelanggang.value,
            sesi_tanding_id: selectedSesi.value,
            arena_name: g ? g.nama_gelanggang : null,
        });
        isSetupDialogOpen.value = false;
        router.reload({ only: ['schedules', 'arena'] });
    } catch (e) {
        console.error('Failed to setup arena', e);
    } finally {
        isLoading.value = false;
    }
};

</script>

<template>
    <Head title="Control Panel - Tapak Suci" />
    <div class="flex h-screen bg-black text-slate-200 overflow-hidden">
        <!-- Sidebar -->
        <div class="w-80 bg-zinc-950 border-r border-zinc-800 flex flex-col h-full shadow-xl z-10 shrink-0">
            <!-- Sidebar Header -->
            <div class="p-6 border-b border-zinc-800 flex flex-col gap-4">
                <div>
                    <h2 class="text-xl font-bold tracking-tight">Partai</h2>
                    <p class="text-xs text-zinc-500 mt-1 uppercase tracking-widest font-semibold" v-if="props.arena?.gelanggang_id">
                        {{ props.arena.arena_name ?? 'Gelanggang ' + props.arena.gelanggang_id }} | Sesi {{ props.arena.sesi_tanding_id }}
                    </p>
                </div>
                <!-- Setup Button -->
                <Button @click="openSetup" class="w-full">
                    Setup Gelanggang
                </Button>
            </div>

            <!-- Match Schedules List -->
            <div class="flex-1 overflow-y-auto p-4 space-y-3 custom-scrollbar">
                <div v-if="props.schedules.length === 0" class="text-center text-zinc-600 text-sm mt-10">
                    <div class="text-4xl mb-4 opacity-20">🏟️</div>
                    <p>Belum ada jadwal partai.</p>
                    <p class="text-xs mt-1">Silakan set gelanggang dan sesi terlebih dahulu.</p>
                </div>
                
                <div v-for="match in props.schedules" :key="match.id" 
                     class="bg-zinc-900 border border-zinc-800 rounded-lg p-4 cursor-pointer hover:border-red-900 transition-all hover:bg-zinc-800/80 group">
                    <div class="flex justify-between items-center mb-3">
                        <span class="text-xs font-bold px-2 py-1 bg-red-900/40 text-red-400 rounded-sm uppercase tracking-wider">Partai {{ match.match_number }}</span>
                        <span class="text-[10px] text-zinc-500 font-medium uppercase tracking-wider">{{ match.category }} - {{ match.group }}</span>
                    </div>

                    <div class="flex items-center justify-between mt-2 gap-4">
                        <div class="flex-1 text-right">
                             <p class="text-xs font-semibold truncate text-yellow-400 drop-shadow-sm">{{ match.athlete_yellow || '-' }}</p>
                             <p class="text-[10px] text-zinc-500 truncate">{{ match.contingent_yellow || 'Kontingen' }}</p>
                        </div>
                        <div class="px-2 font-bold text-zinc-700 text-xs italic">VS</div>
                        <div class="flex-1 text-left">
                             <p class="text-xs font-semibold truncate text-blue-400 drop-shadow-sm">{{ match.athlete_blue || '-' }}</p>
                             <p class="text-[10px] text-zinc-500 truncate">{{ match.contingent_blue || 'Kontingen' }}</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="p-4 border-t border-zinc-800">
                <Button @click="router.visit('/dashboard')" variant="ghost" class="w-full text-zinc-500 hover:text-white hover:bg-zinc-900 text-xs font-medium">
                    &larr; KEMBALI KE DASHBOARD
                </Button>
            </div>
        </div>

        <!-- Main Content Area (Empty for now) -->
        <div class="flex-1 h-full bg-[#0a0a0a] flex items-center justify-center relative overflow-hidden">
             <!-- Background Decal -->
            <div class="absolute inset-0 flex items-center justify-center opacity-[0.02] pointer-events-none">
                 <svg viewBox="0 0 100 100" class="w-[800px] h-[800px]" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="50" cy="50" r="40" stroke="currentColor" stroke-width="2" fill="none" />
                    <path d="M50 10 L50 90 M10 50 L90 50" stroke="currentColor" stroke-width="2" />
                    <circle cx="50" cy="50" r="20" stroke="currentColor" stroke-width="2" fill="none" />
                </svg>
            </div>
           
            <div class="text-center z-10">
                <h3 class="text-2xl text-zinc-800 font-bold uppercase tracking-widest">TAPAK SUCI SCORING</h3>
                <p class="text-zinc-600 mt-2 text-sm uppercase tracking-widest">Pilih partai dari sidebar untuk memulai</p>
            </div>
        </div>

        <!-- Setup Dialog -->
        <Dialog :open="isSetupDialogOpen" @update:open="isSetupDialogOpen = $event">
            <DialogContent class="sm:max-w-[425px] bg-zinc-950 border-zinc-800 text-slate-200 shadow-2xl">
                <DialogHeader class="border-b border-zinc-800 pb-4">
                    <DialogTitle class="text-xl font-bold text-red-500">Setup Gelanggang</DialogTitle>
                </DialogHeader>

                <div class="grid gap-6 py-4">
                    
                    <div class="grid gap-2">
                        <Label class="text-zinc-400 text-xs font-bold uppercase tracking-wider">Pilih Gelanggang</Label>
                        <Select v-model="selectedGelanggang" @update:modelValue="handleGelanggangChange">
                            <SelectTrigger class="bg-zinc-900 border-zinc-800 focus:ring-red-900 hover:bg-zinc-800">
                                <SelectValue placeholder="Memuat gelanggang..." v-if="gelanggangList.length === 0" />
                                <SelectValue placeholder="Pilih Gelanggang" v-else />
                            </SelectTrigger>
                            <SelectContent class="bg-zinc-900 border-zinc-800">
                                <SelectItem v-for="g in gelanggangList" :key="g.id" :value="g.id.toString()" class="focus:bg-zinc-800 focus:text-white cursor-pointer">
                                    {{ g.nama_gelanggang ?? 'Gelanggang ' + g.id }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div class="grid gap-2">
                        <Label class="text-zinc-400 text-xs font-bold uppercase tracking-wider">Pilih Sesi Tanding</Label>
                        <Select v-model="selectedSesi" :disabled="!selectedGelanggang">
                            <SelectTrigger class="bg-zinc-900 border-zinc-800 focus:ring-red-900 hover:bg-zinc-800 disabled:opacity-50">
                                <SelectValue placeholder="Silakan pilih gelanggang" v-if="!selectedGelanggang" />
                                <SelectValue placeholder="Pilih Sesi" v-else />
                            </SelectTrigger>
                            <SelectContent class="bg-zinc-900 border-zinc-800">
                                <SelectItem v-for="s in sesiList" :key="s.id" :value="s.id.toString()" class="focus:bg-zinc-800 focus:text-white cursor-pointer">
                                    {{ s.sesi ?? 'Sesi ' + s.id }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                </div>

                <DialogFooter class="border-t border-zinc-800 pt-4">
                    <Button variant="ghost" @click="isSetupDialogOpen = false">
                        Batal
                    </Button>
                    <Button @click="saveSetup" :disabled="!selectedSesi || isLoading">
                        {{ isLoading ? 'Menyinkronkan...' : 'Simpan' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>

<style scoped>
.custom-scrollbar::-webkit-scrollbar {
    width: 6px;
}
.custom-scrollbar::-webkit-scrollbar-track {
    background: transparent;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
    background: #27272a;
    border-radius: 10px;
}
.custom-scrollbar::-webkit-scrollbar-thumb:hover {
    background: #3f3f46;
}
</style>
