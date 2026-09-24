<script setup>
import { computed } from 'vue';

const props = defineProps({
  variant: {
    type: String,
    default: 'default',
  },
  size: {
    type: String,
    default: 'md',
  },
});

const variantClasses = computed(() => {
  switch (props.variant) {
    case 'blue':
      return 'bg-blue-50 text-blue-700 border-blue-200 ring-blue-600/10';
    case 'orange':
      return 'bg-amber-50 text-amber-800 border-amber-200 ring-amber-600/10';
    case 'green':
      return 'bg-emerald-50 text-emerald-700 border-emerald-200 ring-emerald-600/10';
    case 'red':
      return 'bg-rose-50 text-rose-700 border-rose-200 ring-rose-600/10';
    case 'purple':
      return 'bg-purple-50 text-purple-700 border-purple-200 ring-purple-600/10';
    case 'indigo':
      return 'bg-indigo-50 text-indigo-700 border-indigo-200 ring-indigo-600/10';
    case 'gray':
      return 'bg-slate-100 text-slate-700 border-slate-200 ring-slate-500/10';
    default:
      return 'bg-slate-50 text-slate-700 border-slate-200 ring-slate-500/10';
  }
});

const sizeClasses = computed(() => {
  switch (props.size) {
    case 'sm':
      return 'px-2 py-0.5 text-xs';
    case 'lg':
      return 'px-3 py-1.5 text-sm font-semibold';
    default:
      return 'px-2.5 py-1 text-xs font-medium';
  }
});
</script>

<template>
  <span
    class="inline-flex items-center gap-1.5 rounded-md border font-medium transition-colors ring-1 ring-inset"
    :class="[variantClasses, sizeClasses]"
  >
    <slot />
  </span>
</template>
