<script setup>
import { computed, onMounted, onUnmounted, watch } from 'vue';
import { X } from 'lucide-vue-next';

const props = defineProps({
  show: {
    type: Boolean,
    default: false,
  },
  maxWidth: {
    type: String,
    default: '2xl',
  },
  closeable: {
    type: Boolean,
    default: true,
  },
  // Có title thì Modal tự vẽ thanh tiêu đề + nút đóng và padding cho nội dung
  title: {
    type: String,
    default: '',
  },
});

const emit = defineEmits(['close']);

watch(
  () => props.show,
  () => {
    if (props.show) {
      document.body.style.overflow = 'hidden';
    } else {
      document.body.style.overflow = null;
    }
  }
);

const close = () => {
  if (props.closeable) {
    emit('close');
  }
};

const closeOnEscape = (e) => {
  if (e.key === 'Escape' && props.show) {
    close();
  }
};

onMounted(() => document.addEventListener('keydown', closeOnEscape));
onUnmounted(() => {
  document.removeEventListener('keydown', closeOnEscape);
  document.body.style.overflow = null;
});

const maxWidthClass = computed(() => {
  return {
    sm: 'sm:max-w-sm',
    md: 'sm:max-w-md',
    lg: 'sm:max-w-lg',
    xl: 'sm:max-w-xl',
    '2xl': 'sm:max-w-2xl',
    '3xl': 'sm:max-w-3xl',
    '4xl': 'sm:max-w-4xl',
  }[props.maxWidth] || 'sm:max-w-2xl';
});
</script>

<template>
  <teleport to="body">
    <transition
      enter-active-class="ease-out duration-200"
      enter-from-class="opacity-0"
      enter-to-class="opacity-100"
      leave-active-class="ease-in duration-150"
      leave-from-class="opacity-100"
      leave-to-class="opacity-0"
    >
      <div v-show="show" class="fixed inset-0 z-50 overflow-y-auto overscroll-contain">
        <!-- Backdrop -->
        <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs transition-opacity" @click="close" />

        <!-- Centered Container -->
        <div class="flex min-h-full items-center justify-center p-3 sm:p-6 text-center">
          <transition
            enter-active-class="ease-out duration-200"
            enter-from-class="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
            enter-to-class="opacity-100 translate-y-0 sm:scale-100"
            leave-active-class="ease-in duration-150"
            leave-from-class="opacity-100 translate-y-0 sm:scale-100"
            leave-to-class="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
          >
            <div
              v-show="show"
              class="relative w-full transform overflow-hidden rounded-2xl sm:rounded-3xl bg-white text-left shadow-2xl transition-all border border-slate-200/90 my-auto"
              :class="maxWidthClass"
            >
              <template v-if="show">
                <template v-if="title">
                  <div class="flex items-center justify-between gap-4 border-b border-slate-100 px-5 sm:px-6 py-4">
                    <h3 class="text-base font-bold text-slate-900">{{ title }}</h3>
                    <button
                      v-if="closeable"
                      type="button"
                      @click="close"
                      class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 transition-colors"
                      aria-label="Đóng"
                    >
                      <X class="h-5 w-5" />
                    </button>
                  </div>
                  <div class="p-5 sm:p-6">
                    <slot />
                  </div>
                </template>
                <slot v-else />
              </template>
            </div>
          </transition>
        </div>
      </div>
    </transition>
  </teleport>
</template>
