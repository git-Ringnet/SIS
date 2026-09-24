<script setup>
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { FileText, MessageSquare, Check, PhoneCall, Receipt, Sparkles } from 'lucide-vue-next';

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

const emit = defineEmits(['open-template', 'open-note', 'open-renew', 'updated']);

// Optimistic UI state
const localStatus = ref(props.student.retention_status || 'normal');
const isUpdating = ref(false);

const updateStatus = (newStatus) => {
    if (newStatus === 'renewed') {
        // Open the renewal modal to confirm sessions & amount
        emit('open-renew', props.student);
        return;
    }

    const previousStatus = localStatus.value;
    // Optimistic UI update immediately
    localStatus.value = newStatus;
    isUpdating.value = true;

    router.post(
        `/retention/${props.student.id}/status`,
        { status: newStatus },
        {
            preserveScroll: true,
            onSuccess: () => {
                isUpdating.value = false;
                emit('updated', newStatus);
            },
            onError: () => {
                // Revert on error
                localStatus.value = previousStatus;
                isUpdating.value = false;
            },
        }
    );
};
</script>

<template>
    <div class="inline-flex items-center gap-1.5 flex-wrap">
        <!-- 1. [Hóa đơn] (Màu xanh dương) -->
        <button
            type="button"
            @click="updateStatus('invoice_sent')"
            :title="'Đánh dấu đã gửi hóa đơn/thông báo học phí'"
            class="inline-flex items-center gap-1 rounded-md font-medium transition-all shadow-xs"
            :class="[
                compact ? 'px-2 py-1 text-[11px]' : 'px-2.5 py-1.5 text-xs',
                localStatus === 'invoice_sent'
                    ? 'bg-blue-600 text-white ring-2 ring-blue-500/30 font-semibold'
                    : 'bg-blue-50 text-blue-700 border border-blue-200/80 hover:bg-blue-100/80 hover:text-blue-800'
            ]"
        >
            <Receipt class="h-3.5 w-3.5" :class="localStatus === 'invoice_sent' ? 'text-white' : 'text-blue-500'" />
            <span>Hóa đơn</span>
            <Check v-if="localStatus === 'invoice_sent'" class="h-3 w-3 ml-0.5" />
        </button>

        <!-- 2. [Chăm sóc] (Màu cam) -->
        <button
            type="button"
            @click="updateStatus('following_up')"
            :title="'Đang trong quá trình chăm sóc, tư vấn phụ huynh'"
            class="inline-flex items-center gap-1 rounded-md font-medium transition-all shadow-xs"
            :class="[
                compact ? 'px-2 py-1 text-[11px]' : 'px-2.5 py-1.5 text-xs',
                localStatus === 'following_up'
                    ? 'bg-amber-500 text-white ring-2 ring-amber-500/30 font-semibold'
                    : 'bg-amber-50 text-amber-800 border border-amber-200/80 hover:bg-amber-100 hover:text-amber-900'
            ]"
        >
            <PhoneCall class="h-3.5 w-3.5" :class="localStatus === 'following_up' ? 'text-white' : 'text-amber-600'" />
            <span>Chăm sóc</span>
            <Check v-if="localStatus === 'following_up'" class="h-3 w-3 ml-0.5" />
        </button>

        <!-- 3. [Đã đóng] (Màu xanh lá) -->
        <button
            type="button"
            @click="updateStatus('renewed')"
            :title="'Xác nhận phụ huynh đã đóng tiền, gia hạn thêm số buổi'"
            class="inline-flex items-center gap-1 rounded-md font-medium transition-all shadow-xs"
            :class="[
                compact ? 'px-2 py-1 text-[11px]' : 'px-2.5 py-1.5 text-xs',
                localStatus === 'renewed'
                    ? 'bg-emerald-600 text-white ring-2 ring-emerald-500/30 font-semibold'
                    : 'bg-emerald-50 text-emerald-700 border border-emerald-200/80 hover:bg-emerald-100 hover:text-emerald-800'
            ]"
        >
            <Check class="h-3.5 w-3.5" :class="localStatus === 'renewed' ? 'text-white' : 'text-emerald-600'" />
            <span>Đã đóng</span>
            <Sparkles v-if="localStatus === 'renewed'" class="h-3 w-3 ml-0.5 text-amber-300" />
        </button>

        <!-- Separator -->
        <div class="h-4 w-px bg-slate-200 mx-0.5 hidden sm:block"></div>

        <!-- 4. [Mẫu tin nhắn] Popup Quick Copy -->
        <button
            type="button"
            @click="emit('open-template', student)"
            title="Mở mẫu tin nhắn Zalo/SMS soạn sẵn để copy"
            class="inline-flex items-center gap-1 rounded-md border border-slate-200 bg-white font-medium text-slate-700 hover:bg-slate-50 hover:text-indigo-600 shadow-xs transition-colors"
            :class="compact ? 'px-2 py-1 text-[11px]' : 'px-2.5 py-1.5 text-xs'"
        >
            <MessageSquare class="h-3.5 w-3.5 text-indigo-500" />
            <span class="hidden md:inline">Mẫu tin nhắn</span>
        </button>

        <!-- 5. [Ghi chú] Add Call / Interaction Log -->
        <button
            type="button"
            @click="emit('open-note', student)"
            title="Ghi nhật ký cuộc gọi và hẹn lịch"
            class="inline-flex items-center gap-1 rounded-md border border-slate-200 bg-white font-medium text-slate-700 hover:bg-slate-50 hover:text-amber-600 shadow-xs transition-colors"
            :class="compact ? 'px-2 py-1 text-[11px]' : 'px-2.5 py-1.5 text-xs'"
        >
            <FileText class="h-3.5 w-3.5 text-amber-500" />
            <span class="hidden md:inline">Ghi chú</span>
        </button>
    </div>
</template>
