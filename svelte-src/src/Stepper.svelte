<script>
    import { Globe, MapPin, CalendarDays, UserRound, Clock, Users, Contact, CheckCircle2, Check } from '@lucide/svelte';

    export let steps; // [{ key, label }]
    export let currentIndex;
    export let onJump; // (key) => void, only called for done steps

    const ICONS = {
        language: Globe, location: MapPin, day: CalendarDays, staff: UserRound,
        time: Clock, persons: Users, contact: Contact, confirm: CheckCircle2,
    };
</script>

<div class="flex items-start w-full mb-8">
    {#each steps as step, i (step.key)}
        {@const done = i < currentIndex}
        {@const active = i === currentIndex}
        {@const Icon = ICONS[step.key] || Globe}
        <div class="relative flex-1 flex flex-col items-center gap-2 min-w-0">
            {#if i > 0}
            <div class="absolute top-[19px] right-1/2 w-full h-[2px] -z-0 bg-bf-border">
                <div
                    class="h-full bg-gradient-to-r from-bf-accent-dark to-bf-accent transition-all duration-500 ease-out"
                    style:width={done || active ? '100%' : '0%'}
                ></div>
            </div>
            {/if}
            <button
                type="button"
                disabled={!done}
                on:click={() => onJump(step.key)}
                class="relative z-10 flex items-center justify-center w-10 h-10 rounded-full border-2 transition-all duration-300 ease-out
                    {active ? 'border-bf-accent bg-bf-accent text-white shadow-[0_0_0_4px_rgba(201,162,75,0.22)] scale-110' : ''}
                    {done ? 'border-bf-accent bg-bf-accent text-white cursor-pointer hover:scale-105' : ''}
                    {!active && !done ? 'border-bf-border bg-bf-bg text-white/40 cursor-default' : ''}"
            >
                {#if done}
                    <Check size={18} strokeWidth={3} />
                {:else}
                    <Icon size={17} strokeWidth={2} />
                {/if}
            </button>
            <span class="hidden sm:block text-[10px] font-semibold uppercase tracking-wider text-center transition-colors duration-300
                {active ? 'text-white' : done ? 'text-bf-accent' : 'text-white/35'}">
                {step.label}
            </span>
        </div>
    {/each}
</div>
