<script setup>
import { notify } from '../services/notify';
import { ref, watch } from 'vue';
import { studentApi } from '../services/api';
import { CheckCircle2, RotateCcw, UserCheck } from 'lucide-vue-next';

const props = defineProps({
  student: {
    type: Object,
    required: true,
  },
  compact: {
    type: Boolean,
    default: false,
  },
});

const emit = defineEmits(['attended', 'undone']);

const remainingSessions = ref(props.student.remaining_sessions);
const isMarking = ref(false);
const justAttended = ref(false);

watch(
  () => props.student.remaining_sessions,
  (val) => {
    remainingSessions.value = val;
  }
);

const markAttendance = async () => {
  if (remainingSessions.value <= 0) {
    notify.warning('Học viên đã hết số buổi học! Vui lòng gia hạn thêm khóa học.');
    return;
  }

  const prev = remainingSessions.value;
  remainingSessions.value -= 1;
  justAttended.value = true;
  isMarking.value = true;

  try {
    const res = await studentApi.recordAttendance(props.student.id, {});
    emit('attended', res.data.student);
    setTimeout(() => {
      justAttended.value = false;
    }, 4000);
  } catch (err) {
    remainingSessions.value = prev;
    justAttended.value = false;
    notify.apiError(err, 'Lỗi khi điểm danh');
  } finally {
    isMarking.value = false;
  }
};

const undoAttendance = async () => {
  try {
    isMarking.value = true;
    const res = await studentApi.undoAttendance(props.student.id);
    remainingSessions.value += 1;
    justAttended.value = false;
    emit('undone', res.data.student);
  } catch (err) {
    notify.apiError(err, 'Không thể hoàn tác');
  } finally {
    isMarking.value = false;
  }
};
</script>

<template>
  <div class="inline-flex items-center gap-1.5 flex-nowrap whitespace-nowrap">
    <button
      type="button"
      @click="markAttendance"
      :disabled="remainingSessions <= 0 || isMarking"
      class="group relative inline-flex items-center gap-1.5 rounded-lg font-semibold shadow-2xs transition-all active:scale-95 disabled:cursor-not-allowed disabled:opacity-50"
      :class="[
        compact ? 'px-2.5 py-1.5 text-xs' : 'px-3 py-1.5 text-xs',
        justAttended
          ? 'bg-emerald-600 text-white shadow-emerald-600/20 ring-1 ring-emerald-600'
          : remainingSessions <= 2
            ? 'bg-rose-50 text-rose-700 border border-rose-200/90 hover:bg-rose-600 hover:text-white hover:border-rose-600'
            : 'bg-indigo-50 text-indigo-700 border border-indigo-200/90 hover:bg-indigo-600 hover:text-white hover:border-indigo-600'
      ]"
    >
      <UserCheck v-if="!justAttended" class="h-3.5 w-3.5 shrink-0 transition-transform group-hover:scale-110" />
      <CheckCircle2 v-else class="h-3.5 w-3.5 shrink-0 animate-bounce" />
      <span>{{ justAttended ? 'Đã điểm danh!' : 'Điểm danh' }}</span>
    </button>

    <button
      v-if="justAttended"
      type="button"
      @click="undoAttendance"
      title="Hoàn tác điểm danh nếu bấm nhầm"
      class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-xs font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900 transition-colors shadow-2xs"
    >
      <RotateCcw class="h-3 w-3 text-slate-500" />
      <span class="text-[11px]">Hoàn tác</span>
    </button>
  </div>
</template>
