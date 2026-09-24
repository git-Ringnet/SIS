<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { CheckCircle2, RotateCcw, UserCheck, AlertCircle } from 'lucide-vue-next';

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

const markAttendance = () => {
    if (remainingSessions.value <= 0) {
        alert('Học viên đã hết số buổi học! Vui lòng gia hạn thêm khóa học.');
        return;
    }

    // Optimistic UI update: instantly subtract 1 session
    const prev = remainingSessions.value;
    remainingSessions.value -= 1;
    justAttended.value = true;
    isMarking.value = true;

    router.post(
        `/students/${props.student.id}/attendance`,
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                isMarking.value = false;
                emit('attended', remainingSessions.value);
                setTimeout(() => {
                    justAttended.value = false;
                }, 4000);
            },
            onError: () => {
                // Revert if error
                remainingSessions.value = prev;
                isMarking.value = false;
                justAttended.value = false;
            },
        }
    );
};

const undoAttendance = () => {
    isMarking.value = true;
    router.post(
        `/students/${props.student.id}/attendance/undo`,
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                remainingSessions.value += 1;
                isMarking.value = false;
                justAttended.value = false;
                emit('undone', remainingSessions.value);
            },
            onError: () => {
                isMarking.value = false;
            },
        }
    );
};
</script>

<template>
    <div class="inline-flex items-center gap-1.5">
        <!-- 1-Click Attendance Button -->
        <button
            type="button"
            @click="markAttendance"
            :disabled="remainingSessions <= 0 || isMarking"
            class="group relative inline-flex items-center gap-1.5 rounded-lg font-medium shadow-xs transition-all active:scale-95 disabled:cursor-not-allowed disabled:opacity-50"
            :class="[
                compact ? 'px-2.5 py-1 text-xs' : 'px-3.5 py-1.5 text-xs',
                justAttended
                    ? 'bg-emerald-600 text-white shadow-emerald-600/20'
                    : remainingSessions <= 2
                        ? 'bg-rose-600 text-white hover:bg-rose-700 shadow-rose-600/20'
                        : 'bg-indigo-600 text-white hover:bg-indigo-700 shadow-indigo-600/20'
            ]"
        >
            <UserCheck v-if="!justAttended" class="h-3.5 w-3.5 transition-transform group-hover:scale-110" />
            <CheckCircle2 v-else class="h-3.5 w-3.5 animate-bounce" />
            <span>{{ justAttended ? 'Đã điểm danh!' : 'Điểm danh' }}</span>
        </button>

        <!-- Undo Button (appears right after attendance for quick correction) -->
        <button
            v-if="justAttended"
            type="button"
            @click="undoAttendance"
            title="Hoàn tác điểm danh nếu bấm nhầm"
            class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-2 py-1 text-xs font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900 transition-colors shadow-xs animate-fade-in"
        >
            <RotateCcw class="h-3 w-3 text-slate-500" />
            <span class="text-[11px]">Hoàn tác</span>
        </button>
    </div>
</template>
