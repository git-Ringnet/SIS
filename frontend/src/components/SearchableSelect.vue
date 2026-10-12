<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { ChevronDown, Search, X, Check } from 'lucide-vue-next';

const props = defineProps({
  modelValue: {
    type: [String, Number],
    default: '',
  },
  options: {
    type: Array,
    default: () => [],
  },
  labelKey: {
    type: String,
    default: 'name',
  },
  valueKey: {
    type: String,
    default: 'id',
  },
  sublabelKey: {
    type: String,
    default: '',
  },
  placeholder: {
    type: String,
    default: 'Chọn hoặc tìm kiếm...',
  },
  searchPlaceholder: {
    type: String,
    default: 'Nhập để tìm kiếm...',
  },
  required: {
    type: Boolean,
    default: false,
  },
  disabled: {
    type: Boolean,
    default: false,
  },
  clearable: {
    type: Boolean,
    default: false,
  },
});

const emit = defineEmits(['update:modelValue', 'change']);

const isOpen = ref(false);
const searchQuery = ref('');
const highlightedIndex = ref(0);
const containerRef = ref(null);
const searchInputRef = ref(null);
const listboxRef = ref(null);

// Normalize Vietnamese string for accents-insensitive search
const removeVietnameseTones = (str) => {
  if (!str) return '';
  str = String(str);
  str = str.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
  str = str.replace(/[đĐ]/g, 'd');
  return str.toLowerCase().trim();
};

const selectedOption = computed(() => {
  return props.options.find((opt) => String(opt[props.valueKey]) === String(props.modelValue));
});

const filteredOptions = computed(() => {
  if (!searchQuery.value) return props.options;
  const q = removeVietnameseTones(searchQuery.value);
  return props.options.filter((opt) => {
    const label = removeVietnameseTones(opt[props.labelKey]);
    const sublabel = props.sublabelKey ? removeVietnameseTones(opt[props.sublabelKey]) : '';
    const code = opt.code ? removeVietnameseTones(opt.code) : '';
    return label.includes(q) || sublabel.includes(q) || code.includes(q);
  });
});

const toggleDropdown = () => {
  if (props.disabled) return;
  isOpen.value = !isOpen.value;
  if (isOpen.value) {
    searchQuery.value = '';
    // Find index of current selected option
    const idx = filteredOptions.value.findIndex(
      (opt) => String(opt[props.valueKey]) === String(props.modelValue)
    );
    highlightedIndex.value = idx >= 0 ? idx : 0;
    nextTick(() => {
      if (searchInputRef.value) {
        searchInputRef.value.focus();
      }
      scrollToHighlighted();
    });
  }
};

const closeDropdown = () => {
  isOpen.value = false;
  searchQuery.value = '';
};

const selectOption = (opt) => {
  if (!opt) return;
  const val = opt[props.valueKey];
  emit('update:modelValue', val);
  emit('change', opt);
  closeDropdown();
};

const clearSelection = (e) => {
  e.stopPropagation();
  emit('update:modelValue', '');
  emit('change', null);
};

const highlightNext = () => {
  if (!isOpen.value) {
    toggleDropdown();
    return;
  }
  if (filteredOptions.value.length === 0) return;
  highlightedIndex.value = (highlightedIndex.value + 1) % filteredOptions.value.length;
  scrollToHighlighted();
};

const highlightPrev = () => {
  if (!isOpen.value) {
    toggleDropdown();
    return;
  }
  if (filteredOptions.value.length === 0) return;
  highlightedIndex.value =
    (highlightedIndex.value - 1 + filteredOptions.value.length) % filteredOptions.value.length;
  scrollToHighlighted();
};

const selectHighlighted = () => {
  if (isOpen.value && filteredOptions.value.length > 0) {
    const opt = filteredOptions.value[highlightedIndex.value];
    if (opt) {
      selectOption(opt);
    }
  } else {
    toggleDropdown();
  }
};

const scrollToHighlighted = () => {
  nextTick(() => {
    if (!listboxRef.value) return;
    const items = listboxRef.value.querySelectorAll('[role="option"]');
    if (items[highlightedIndex.value]) {
      items[highlightedIndex.value].scrollIntoView({ block: 'nearest' });
    }
  });
};

const handleClickOutside = (e) => {
  if (containerRef.value && !containerRef.value.contains(e.target)) {
    closeDropdown();
  }
};

watch(searchQuery, () => {
  highlightedIndex.value = 0;
});

onMounted(() => {
  document.addEventListener('click', handleClickOutside);
});

onBeforeUnmount(() => {
  document.removeEventListener('click', handleClickOutside);
});
</script>

<template>
  <div ref="containerRef" class="relative w-full text-left select-none">
    <!-- Trigger Button -->
    <div
      tabindex="0"
      @click="toggleDropdown"
      @keydown.down.prevent="highlightNext"
      @keydown.up.prevent="highlightPrev"
      @keydown.enter.prevent="toggleDropdown"
      @keydown.esc.prevent="closeDropdown"
      :class="[
        'w-full cursor-pointer rounded-xl border px-3.5 py-2.5 text-xs sm:text-sm font-medium transition-all flex items-center justify-between gap-2',
        isOpen
          ? 'border-indigo-500 bg-white ring-2 ring-indigo-500/20'
          : 'border-slate-200 bg-slate-50/60 hover:bg-white hover:border-slate-300',
        disabled ? 'opacity-50 cursor-not-allowed bg-slate-100' : ''
      ]"
    >
      <div class="truncate flex-1 text-left">
        <span v-if="selectedOption" class="text-slate-800 font-semibold">
          {{ selectedOption[labelKey] }}
          <span v-if="sublabelKey && selectedOption[sublabelKey]" class="text-slate-500 font-normal ml-1">
            ({{ selectedOption[sublabelKey] }})
          </span>
        </span>
        <span v-else class="text-slate-400 font-normal">
          {{ placeholder }}
        </span>
      </div>

      <div class="flex items-center gap-1.5 shrink-0 text-slate-400">
        <button
          v-if="clearable && selectedOption && !disabled"
          type="button"
          @click="clearSelection"
          class="hover:text-slate-600 p-0.5 rounded"
        >
          <X class="h-3.5 w-3.5" />
        </button>
        <ChevronDown
          :class="['h-4 w-4 transition-transform duration-200', isOpen ? 'rotate-180 text-indigo-600' : '']"
        />
      </div>
    </div>

    <!-- Hidden input for HTML form validation if required -->
    <input
      v-if="required"
      type="text"
      tabindex="-1"
      aria-hidden="true"
      class="opacity-0 absolute pointer-events-none h-0 w-0"
      :value="modelValue"
      required
    />

    <!-- Dropdown Menu -->
    <transition
      enter-active-class="transition duration-150 ease-out"
      enter-from-class="transform scale-95 opacity-0"
      enter-to-class="transform scale-100 opacity-100"
      leave-active-class="transition duration-100 ease-in"
      leave-from-class="transform scale-100 opacity-100"
      leave-to-class="transform scale-95 opacity-0"
    >
      <div
        v-if="isOpen"
        class="absolute z-50 mt-1.5 w-full min-w-[240px] rounded-xl border border-slate-200 bg-white shadow-xl shadow-slate-900/10 overflow-hidden"
      >
        <!-- Search Field inside Dropdown -->
        <div class="p-2 border-b border-slate-100 bg-slate-50/60">
          <div class="relative">
            <Search class="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-slate-400" />
            <input
              ref="searchInputRef"
              type="text"
              v-model="searchQuery"
              @keydown.down.prevent="highlightNext"
              @keydown.up.prevent="highlightPrev"
              @keydown.enter.prevent="selectHighlighted"
              @keydown.esc.prevent="closeDropdown"
              :placeholder="searchPlaceholder"
              class="w-full rounded-lg border border-slate-200 bg-white pl-8 pr-3 py-1.5 text-xs sm:text-sm text-slate-800 placeholder-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
            />
          </div>
        </div>

        <!-- Options Listbox -->
        <div
          ref="listboxRef"
          role="listbox"
          class="max-h-56 overflow-y-auto p-1 divide-y divide-slate-50 focus:outline-none"
        >
          <div
            v-for="(opt, idx) in filteredOptions"
            :key="opt[valueKey]"
            role="option"
            :aria-selected="String(opt[valueKey]) === String(modelValue)"
            @click="selectOption(opt)"
            @mouseenter="highlightedIndex = idx"
            :class="[
              'cursor-pointer rounded-lg px-3 py-2 text-xs sm:text-sm transition-colors flex items-center justify-between gap-2',
              highlightedIndex === idx
                ? 'bg-indigo-50/80 text-indigo-900'
                : 'text-slate-700 hover:bg-slate-50',
              String(opt[valueKey]) === String(modelValue)
                ? 'font-bold text-indigo-600 bg-indigo-50/40'
                : 'font-medium'
            ]"
          >
            <div class="truncate">
              <div>{{ opt[labelKey] }}</div>
              <div
                v-if="sublabelKey && opt[sublabelKey]"
                class="text-[11px] text-slate-400 font-normal truncate mt-0.5"
              >
                {{ opt[sublabelKey] }}
              </div>
            </div>

            <Check
              v-if="String(opt[valueKey]) === String(modelValue)"
              class="h-4 w-4 text-indigo-600 shrink-0"
            />
          </div>

          <!-- Empty State -->
          <div
            v-if="filteredOptions.length === 0"
            class="py-6 text-center text-xs text-slate-400"
          >
            Không tìm thấy kết quả phù hợp
          </div>
        </div>
      </div>
    </transition>
  </div>
</template>
