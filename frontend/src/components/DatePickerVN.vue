<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { Calendar as CalendarIcon, ChevronLeft, ChevronRight, X } from 'lucide-vue-next';

const props = defineProps({
  modelValue: {
    type: String,
    default: '',
  },
  placeholder: {
    type: String,
    default: 'dd/mm/yyyy',
  },
  defaultToday: {
    type: Boolean,
    default: true,
  },
  required: {
    type: Boolean,
    default: false,
  },
  disabled: {
    type: Boolean,
    default: false,
  },
});

const emit = defineEmits(['update:modelValue', 'change']);

const containerRef = ref(null);
const isOpen = ref(false);
const inputText = ref('');

// Calendar internal state
const currentYear = ref(new Date().getFullYear());
const currentMonth = ref(new Date().getMonth()); // 0-11

const monthNames = [
  'Tháng 1', 'Tháng 2', 'Tháng 3', 'Tháng 4',
  'Tháng 5', 'Tháng 6', 'Tháng 7', 'Tháng 8',
  'Tháng 9', 'Tháng 10', 'Tháng 11', 'Tháng 12'
];

const dayNames = ['T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN'];

// Format helpers
const padZero = (n) => String(n).padStart(2, '0');

const formatToDDMMYYYY = (isoStr) => {
  if (!isoStr) return '';
  // Handles YYYY-MM-DD or YYYY-MM-DD HH:mm:ss or Date
  const parts = String(isoStr).split('T')[0].split(' ')[0].split('-');
  if (parts.length === 3) {
    const [y, m, d] = parts;
    return `${padZero(d)}/${padZero(m)}/${y}`;
  }
  return isoStr;
};

const formatToYYYYMMDD = (ddmmyyyy) => {
  if (!ddmmyyyy) return '';
  const parts = ddmmyyyy.trim().split('/');
  if (parts.length === 3) {
    const [d, m, y] = parts;
    if (d && m && y && y.length === 4) {
      return `${y}-${padZero(m)}-${padZero(d)}`;
    }
  }
  return '';
};

const getTodayISO = () => {
  const now = new Date();
  return `${now.getFullYear()}-${padZero(now.getMonth() + 1)}-${padZero(now.getDate())}`;
};

// Initialize value
const syncFromModel = () => {
  if (props.modelValue) {
    inputText.value = formatToDDMMYYYY(props.modelValue);
    const iso = formatToYYYYMMDD(inputText.value) || props.modelValue;
    const d = new Date(iso);
    if (!isNaN(d.getTime())) {
      currentYear.value = d.getFullYear();
      currentMonth.value = d.getMonth();
    }
  } else if (props.defaultToday) {
    const todayIso = getTodayISO();
    inputText.value = formatToDDMMYYYY(todayIso);
    const now = new Date();
    currentYear.value = now.getFullYear();
    currentMonth.value = now.getMonth();
    emit('update:modelValue', todayIso);
    emit('change', todayIso);
  } else {
    inputText.value = '';
  }
};

watch(() => props.modelValue, (newVal) => {
  if (newVal) {
    inputText.value = formatToDDMMYYYY(newVal);
  } else if (!props.defaultToday) {
    inputText.value = '';
  }
});

// Calendar grid calculations
const calendarDays = computed(() => {
  const year = currentYear.value;
  const month = currentMonth.value;

  // First day of month (0 = Sun, 1 = Mon, ..., 6 = Sat)
  const firstDay = new Date(year, month, 1);
  let startingDayOfWeek = firstDay.getDay() - 1; // 0 = Mon, 6 = Sun
  if (startingDayOfWeek < 0) startingDayOfWeek = 6;

  // Days in current month
  const daysInMonth = new Date(year, month + 1, 0).getDate();

  // Days in previous month
  const prevMonthDays = new Date(year, month, 0).getDate();

  const days = [];

  // Previous month padding
  for (let i = startingDayOfWeek - 1; i >= 0; i--) {
    days.push({
      day: prevMonthDays - i,
      month: month - 1,
      year: month === 0 ? year - 1 : year,
      isCurrentMonth: false,
    });
  }

  // Current month days
  for (let i = 1; i <= daysInMonth; i++) {
    days.push({
      day: i,
      month: month,
      year: year,
      isCurrentMonth: true,
    });
  }

  // Next month padding to complete 42 cells (6 rows) or 35 cells
  const remaining = 42 - days.length;
  for (let i = 1; i <= remaining; i++) {
    days.push({
      day: i,
      month: month + 1,
      year: month === 11 ? year + 1 : year,
      isCurrentMonth: false,
    });
  }

  return days;
});

const isSelectedDate = (cell) => {
  if (!inputText.value) return false;
  const parts = inputText.value.split('/');
  if (parts.length !== 3) return false;
  const d = parseInt(parts[0], 10);
  const m = parseInt(parts[1], 10) - 1;
  const y = parseInt(parts[2], 10);
  return cell.day === d && cell.month === m && cell.year === y;
};

const isToday = (cell) => {
  const now = new Date();
  return cell.day === now.getDate() && cell.month === now.getMonth() && cell.year === now.getFullYear();
};

const prevMonth = () => {
  if (currentMonth.value === 0) {
    currentMonth.value = 11;
    currentYear.value--;
  } else {
    currentMonth.value--;
  }
};

const nextMonth = () => {
  if (currentMonth.value === 11) {
    currentMonth.value = 0;
    currentYear.value++;
  } else {
    currentMonth.value++;
  }
};

const selectDay = (cell) => {
  const d = padZero(cell.day);
  const m = padZero(cell.month + 1);
  const y = cell.year;
  inputText.value = `${d}/${m}/${y}`;
  const iso = `${y}-${m}-${d}`;
  emit('update:modelValue', iso);
  emit('change', iso);
  isOpen.value = false;
};

const selectToday = () => {
  const now = new Date();
  currentYear.value = now.getFullYear();
  currentMonth.value = now.getMonth();
  selectDay({
    day: now.getDate(),
    month: now.getMonth(),
    year: now.getFullYear(),
  });
};

const clearDate = () => {
  inputText.value = '';
  emit('update:modelValue', '');
  emit('change', '');
  isOpen.value = false;
};

// Handle manual text input with automatic formatting
const handleInput = (e) => {
  let val = e.target.value.replace(/[^0-9]/g, '');
  if (val.length > 8) val = val.substring(0, 8);

  let formatted = '';
  if (val.length > 0) {
    formatted = val.substring(0, 2);
    if (val.length >= 3) {
      formatted += '/' + val.substring(2, 4);
    }
    if (val.length >= 5) {
      formatted += '/' + val.substring(4, 8);
    }
  }

  inputText.value = formatted;

  if (formatted.length === 10) {
    const iso = formatToYYYYMMDD(formatted);
    if (iso) {
      emit('update:modelValue', iso);
      emit('change', iso);
      const parts = formatted.split('/');
      currentMonth.value = parseInt(parts[1], 10) - 1;
      currentYear.value = parseInt(parts[2], 10);
    }
  } else if (formatted.length === 0) {
    emit('update:modelValue', '');
    emit('change', '');
  }
};

const togglePopup = () => {
  if (props.disabled) return;
  isOpen.value = !isOpen.value;
};

const handleClickOutside = (e) => {
  if (containerRef.value && !containerRef.value.contains(e.target)) {
    isOpen.value = false;
  }
};

onMounted(() => {
  syncFromModel();
  document.addEventListener('click', handleClickOutside);
});

onBeforeUnmount(() => {
  document.removeEventListener('click', handleClickOutside);
});
</script>

<template>
  <div ref="containerRef" class="relative w-full text-left select-none">
    <!-- Input Box -->
    <div
      :class="[
        'relative flex items-center rounded-xl border transition-all',
        isOpen
          ? 'border-indigo-500 bg-white ring-2 ring-indigo-500/20'
          : 'border-slate-200 bg-slate-50/60 hover:bg-white hover:border-slate-300',
        disabled ? 'opacity-50 cursor-not-allowed bg-slate-100' : ''
      ]"
    >
      <input
        type="text"
        :value="inputText"
        @input="handleInput"
        @focus="isOpen = true"
        :placeholder="placeholder"
        :disabled="disabled"
        :required="required"
        class="w-full bg-transparent px-3.5 py-2.5 pr-10 text-xs sm:text-sm font-medium text-slate-800 placeholder-slate-400 focus:outline-none"
      />

      <button
        type="button"
        @click="togglePopup"
        :disabled="disabled"
        class="absolute right-3 p-1 text-slate-400 hover:text-indigo-600 transition-colors"
      >
        <CalendarIcon class="h-4 w-4" />
      </button>
    </div>

    <!-- Calendar Popup -->
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
        class="absolute z-50 mt-1.5 w-72 rounded-2xl border border-slate-200 bg-white p-4 shadow-xl shadow-slate-900/10"
      >
        <!-- Month Navigation Header -->
        <div class="flex items-center justify-between pb-3 border-b border-slate-100">
          <button
            type="button"
            @click="prevMonth"
            class="flex h-7 w-7 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-900 transition-colors"
          >
            <ChevronLeft class="h-4 w-4" />
          </button>

          <span class="text-xs sm:text-sm font-bold text-slate-800">
            {{ monthNames[currentMonth] }}, {{ currentYear }}
          </span>

          <button
            type="button"
            @click="nextMonth"
            class="flex h-7 w-7 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-900 transition-colors"
          >
            <ChevronRight class="h-4 w-4" />
          </button>
        </div>

        <!-- Days of Week Header (Vietnamese) -->
        <div class="grid grid-cols-7 gap-1 pt-3 text-center">
          <span
            v-for="day in dayNames"
            :key="day"
            class="text-[11px] font-bold text-slate-400"
          >
            {{ day }}
          </span>
        </div>

        <!-- Days Grid -->
        <div class="grid grid-cols-7 gap-1 pt-1.5 text-center">
          <button
            v-for="(cell, idx) in calendarDays"
            :key="idx"
            type="button"
            @click="selectDay(cell)"
            :class="[
              'flex h-8 w-8 items-center justify-center rounded-xl text-xs font-medium transition-all mx-auto',
              isSelectedDate(cell)
                ? 'bg-indigo-600 text-white font-bold shadow-xs shadow-indigo-600/30'
                : cell.isCurrentMonth
                  ? isToday(cell)
                    ? 'border border-indigo-400 text-indigo-600 font-bold bg-indigo-50/50'
                    : 'text-slate-700 hover:bg-slate-100 hover:text-slate-900'
                  : 'text-slate-300 hover:bg-slate-50 hover:text-slate-400'
            ]"
          >
            {{ cell.day }}
          </button>
        </div>

        <!-- Bottom Action Bar -->
        <div class="flex items-center justify-between border-t border-slate-100 pt-3 mt-3 text-xs">
          <button
            type="button"
            @click="clearDate"
            class="text-slate-400 hover:text-slate-600 font-medium"
          >
            Xóa
          </button>
          <button
            type="button"
            @click="selectToday"
            class="text-indigo-600 hover:text-indigo-800 font-bold"
          >
            Hôm nay
          </button>
        </div>
      </div>
    </transition>
  </div>
</template>
