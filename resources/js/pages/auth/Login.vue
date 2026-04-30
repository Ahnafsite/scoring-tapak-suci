<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { CardHeader, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/login';

defineOptions({
    layout: {
        title: 'Digital Scoring Tapak Suci',
        description: 'by ema.id',
    },
});

defineProps<{
    status?: string;
    canResetPassword: boolean;
    canRegister: boolean;
}>();
</script>

<template>
    <Head title="Log in" />

    <Form
        v-bind="store.form()"
        :reset-on-success="['password']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-5"
    >
        <CardHeader class="px-0 pb-0">
            <div
                class="flex items-center justify-center gap-4 sm:justify-start"
            >
                <img
                    src="/assets/images/js_logo.png"
                    alt="Tapak Suci Logo"
                    class="h-24 w-24 shrink-0 object-contain"
                />
                <div class="space-y-1">
                    <h1
                        class="text-[1.15rem] leading-[1.2] font-bold tracking-[0.04em] text-white uppercase sm:text-[1.35rem]"
                    >
                        Digital Scoring
                        <br />
                        Tapak Suci
                        <br />
                        by ema.id
                    </h1>
                    <p
                        class="text-[10px] tracking-[0.22em] text-yellow-400 uppercase"
                    >
                        Sistem Penilaian Digital
                    </p>
                </div>
            </div>
        </CardHeader>

        <div
            v-if="status"
            class="rounded-xl border border-green-500/20 bg-green-500/10 px-4 py-2.5 text-sm font-medium text-green-300"
        >
            {{ status }}
        </div>

        <CardContent class="grid gap-4 px-0">
            <div class="grid gap-2">
                <Label
                    for="email"
                    class="text-base font-semibold tracking-tight text-white"
                >
                    Email address
                </Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    required
                    autofocus
                    :tabindex="1"
                    autocomplete="email"
                    placeholder="email@example.com"
                    class="h-12 rounded-xl border-white/10 bg-white/12 px-4 text-base text-white placeholder:text-white/45"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <Label
                    for="password"
                    class="text-base font-semibold tracking-tight text-white"
                >
                    Password
                </Label>
                <PasswordInput
                    id="password"
                    name="password"
                    required
                    :tabindex="2"
                    autocomplete="current-password"
                    placeholder="Password"
                    class="h-12 rounded-xl border-white/10 bg-white/12 px-4 text-base text-white placeholder:text-white/45"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="flex items-center justify-between">
                <Label
                    for="remember"
                    class="flex items-center gap-2.5 text-sm font-medium text-white"
                >
                    <Checkbox
                        id="remember"
                        name="remember"
                        :tabindex="3"
                        class="h-5 w-5 rounded-md border-white/15 bg-white/10 data-[state=checked]:border-yellow-400 data-[state=checked]:bg-yellow-400 data-[state=checked]:text-black"
                    />
                    <span>Remember me</span>
                </Label>
            </div>

            <Button
                type="submit"
                class="mt-1 h-12 w-full rounded-xl bg-yellow-400 text-base font-bold text-black transition hover:bg-yellow-300"
                :tabindex="4"
                :disabled="processing"
                data-test="login-button"
            >
                <Spinner v-if="processing" />
                Log in
            </Button>
        </CardContent>
    </Form>
</template>
