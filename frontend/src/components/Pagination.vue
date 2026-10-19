<script setup>
import { computed } from 'vue';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';

const props = defineProps({
  // Object trả về từ API: { current_page, last_page, per_page, total, from, to }
  meta: {
    type: Object,
    default: null,
  },
  perPageOptions: {
    type: Array,
    default: () => [10, 20, 50, 100],
  },
});

const emit = defineEmits(['change-page', 'change-per-page']);

const currentPage = computed(() => props.meta?.current_page ?? 1);
const lastPage = computed(() => props.meta?.last_page ?? 1);

// Dãy số trang rút gọn, ví dụ: 1 … 4 5 6 … 20
const pages = computed(() => {
  const last = lastPage.value;
  const current = currentPage.value;
  if (last <= 7) return Array.from({ length: last }, (_, i) => i + 1);

  const result = [1];
  const start = Math.max(2, current - 1);
  const end = Math.min(last - 1, current + 1);
  if (start > 2) result.push('…');
  for (let p = start; p <= end; p++) result.push(p);
  if (end < last - 1) result.push('…');
  result.push(last);
  return result;
});

const goTo = (page) => {
  if (page < 1 || page > lastPage.value || page === currentPage.value) return;
  emit('change-page', page);
};
</script>

<template>
  <div
    v-if="meta && meta.total > 0"
    class="flex flex-col sm:flex-row items-center justify-between gap-3 border-t border-slate-100 px-4 py-3 text-xs sm:text-sm text-slate-600"
  >
    <div class="flex items-center gap-3">
      <span>
        Hiển thị <strong class="text-slate-900">{{ meta.from }}–{{ meta.to }}</strong>
        / <strong class="text-slate-900">{{ meta.total }}</strong>
      </span>
      <label class="flex items-center gap-1.5 text-slate-500">
        <select
          :value="meta.per_page"
          @change="emit('change-per-page', Number($event.target.value))"
          class="rounded-lg border border-slate-200 bg-white px-2 py-1 text-xs sm:text-sm text-slate-700 focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-500"
        >
          <option v-for="n in perPageOptions" :key="n" :value="n">{{ n }}</option>
        </select>
        <span>dòng / trang</span>
      </label>
    </div>

    <nav v-if="lastPage > 1" class="flex items-center gap-1" aria-label="Phân trang">
      <button
        type="button"
        :disabled="currentPage <= 1"
        @click="goTo(currentPage - 1)"
        class="flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 hover:bg-slate-50 disabled:opacity-40 disabled:cursor-not-allowed"
        aria-label="Trang trước"
      >
        <ChevronLeft class="h-4 w-4" />
      </button>

      <template v-for="(p, i) in pages" :key="`${p}-${i}`">
        <span v-if="p === '…'" class="px-1.5 text-slate-400">…</span>
        <button
          v-else
          type="button"
          @click="goTo(p)"
          :class="[
            p === currentPage
              ? 'bg-rose-600 border-rose-600 text-white font-bold'
              : 'bg-white border-slate-200 text-slate-700 hover:bg-slate-50',
            'h-8 min-w-8 rounded-lg border px-2 text-xs sm:text-sm transition-colors',
          ]"
          :aria-current="p === currentPage ? 'page' : undefined"
        >
          {{ p }}
        </button>
      </template>

      <button
        type="button"
        :disabled="currentPage >= lastPage"
        @click="goTo(currentPage + 1)"
        class="flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 hover:bg-slate-50 disabled:opacity-40 disabled:cursor-not-allowed"
        aria-label="Trang sau"
      >
        <ChevronRight class="h-4 w-4" />
      </button>
    </nav>
  </div>
</template>
