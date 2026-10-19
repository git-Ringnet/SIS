<script setup>
import { computed, watch, nextTick, ref, onMounted, onBeforeUnmount } from 'vue';
import { AlertTriangle, Info, Trash2 } from 'lucide-vue-next';
import { confirmState, settleConfirm } from '../services/useConfirm';

const confirmButton = ref(null);

const styles = computed(() => {
  switch (confirmState.variant) {
    case 'warning':
      return { icon: AlertTriangle, iconWrap: 'bg-amber-100 text-amber-600', button: 'bg-amber-500 hover:bg-amber-600 focus-visible:ring-amber-500' };
    case 'primary':
      return { icon: Info, iconWrap: 'bg-indigo-100 text-indigo-600', button: 'bg-indigo-600 hover:bg-indigo-700 focus-visible:ring-indigo-500' };
    default:
      return { icon: Trash2, iconWrap: 'bg-rose-100 text-rose-600', button: 'bg-rose-600 hover:bg-rose-700 focus-visible:ring-rose-500' };
  }
});

watch(
  () => confirmState.open,
  async (open) => {
    if (open) {
      await nextTick();
      confirmButton.value?.focus();
    }
  },
);

const onKeydown = (e) => {
  if (confirmState.open && e.key === 'Escape') settleConfirm(false);
};
onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
  <Teleport to="body">
    <Transition
      enter-active-class="transition duration-150 ease-out"
      enter-from-class="opacity-0"
      leave-active-class="transition duration-100 ease-in"
      leave-to-class="opacity-0"
    >
      <div
        v-if="confirmState.open"
        class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-900/50 backdrop-blur-xs p-4"
        @click.self="settleConfirm(false)"
      >
        <div
          role="alertdialog"
          aria-modal="true"
          class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-2xl"
        >
          <div class="flex items-start gap-4">
            <div :class="[styles.iconWrap, 'flex h-11 w-11 shrink-0 items-center justify-center rounded-full']">
              <component :is="styles.icon" class="h-5 w-5" />
            </div>
            <div class="min-w-0 pt-0.5">
              <h3 class="text-base font-bold text-slate-900">{{ confirmState.title }}</h3>
              <p v-if="confirmState.message" class="mt-1.5 text-sm text-slate-600 whitespace-pre-line">
                {{ confirmState.message }}
              </p>
            </div>
          </div>

          <div class="mt-6 flex justify-end gap-2.5">
            <button
              type="button"
              @click="settleConfirm(false)"
              class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 transition-colors"
            >
              {{ confirmState.cancelText }}
            </button>
            <button
              ref="confirmButton"
              type="button"
              @click="settleConfirm(true)"
              :class="[styles.button, 'rounded-xl px-4 py-2 text-sm font-bold text-white shadow-xs transition-all active:scale-[0.98] focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2']"
            >
              {{ confirmState.confirmText }}
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>
