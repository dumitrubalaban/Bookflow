<script>
    export let steps; // [{ key, label }]
    export let currentIndex;
    export let onJump; // (key) => void, only called for done steps
</script>

<div class="mb-6">
    <div class="flex items-stretch w-full">
        {#each steps as step, i (step.key)}
            {@const done = i < currentIndex}
            {@const active = i === currentIndex}
            <div class="relative flex-1 min-w-0">
                <button
                    type="button"
                    disabled={!done}
                    on:click={() => onJump(step.key)}
                    title={step.label}
                    class="w-full flex flex-col items-center gap-1 bg-transparent pb-2 border-t-2 pt-2.5 transition-colors
                        {active ? 'border-bf-accent' : done ? 'border-bf-accent/60 cursor-pointer' : 'border-bf-border cursor-default'}"
                >
                    <span class="text-xs font-semibold {active ? 'text-bf-accent' : done ? 'text-gray-600' : 'text-gray-400'}">
                        {i + 1}
                    </span>
                </button>
            </div>
        {/each}
    </div>
</div>
