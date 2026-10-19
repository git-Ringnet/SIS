<script setup>
import { notify } from '../services/notify';
import { ref, watch } from 'vue';
import { retentionApi } from '../services/api';
import { Receipt, PhoneCall, Check, MessageSquare, FileText, Sparkles } from 'lucide-vue-next';

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

const localStatus = ref(props.student.retention_status || 'normal');
const isUpdating = ref(false);

watch(
  () => props.student.retention_status,
  (val) => {
    localStatus.value = val || 'normal';
  }
);

const updateStatus = async (newStatus) => {
  if (newStatus === 'renewed') {
    emit('open-renew', props.student);
    return;
  }

  const previousStatus = localStatus.value;
  localStatus.value = newStatus;
  isUpdating.value = true;

  try {
    const res = await retentionApi.updateStatus(props.student.id, { status: newStatus });
    emit('updated', res.data.student);
  } catch (err) {
    localStatus.value = previousStatus;
    notify.apiError(err, 'Có lỗi xảy ra');
  } finally {
    isUpdating.value = false;
  }
};
</script>

<template>
  <div class="inline-flex items-center gap-1 flex-nowrap whitespace-nowrap">
    <!-- Segmented Status Group -->
    <div class="inline-flex items-center rounded-lg border border-slate-200/90 bg-slate-50/80 p-0.5 shadow-2xs">
      <!-- 1. [Hóa đơn] (Xanh dương) -->
      <button
        type="button"
        @click="updateStatus('invoice_sent')"
        title="Đã gửi hóa đơn / thông báo học phí"
        class="inline-flex items-center gap-1 rounded-md font-semibold transition-all"
        :class="[
          compact ? 'px-2 py-1 text-[11px]' : 'px-2.5 py-1 text-xs',
          localStatus === 'invoice_sent'
            ? 'bg-blue-600 text-white shadow-xs font-bold'
            : 'text-blue-700 hover:bg-blue-100/70'
        ]"
      >
        <Receipt class="h-3.5 w-3.5 shrink-0" :class="localStatus === 'invoice_sent' ? 'text-white' : 'text-blue-500'" />
        <span>Hóa đơn</span>
        <Check v-if="localStatus === 'invoice_sent'" class="h-3 w-3" />
      </button>

      <!-- 2. [Chăm sóc] (Màu cam) -->
      <button
        type="button"
        @click="updateStatus('following_up')"
        title="Đang tư vấn, chăm sóc phụ huynh"
        class="inline-flex items-center gap-1 rounded-md font-semibold transition-all"
        :class="[
          compact ? 'px-2 py-1 text-[11px]' : 'px-2.5 py-1 text-xs',
          localStatus === 'following_up'
            ? 'bg-amber-500 text-white shadow-xs font-bold'
            : 'text-amber-800 hover:bg-amber-100/70'
        ]"
      >
        <PhoneCall class="h-3.5 w-3.5 shrink-0" :class="localStatus === 'following_up' ? 'text-white' : 'text-amber-600'" />
        <span>Chăm sóc</span>
        <Check v-if="localStatus === 'following_up'" class="h-3 w-3" />
      </button>

      <!-- 3. [Đã đóng] (Màu xanh lá) -->
      <button
        type="button"
        @click="updateStatus('renewed')"
        title="Xác nhận tiền về & cộng số buổi mới"
        class="inline-flex items-center gap-1 rounded-md font-semibold transition-all"
        :class="[
          compact ? 'px-2 py-1 text-[11px]' : 'px-2.5 py-1 text-xs',
          localStatus === 'renewed'
            ? 'bg-emerald-600 text-white shadow-xs font-bold'
            : 'text-emerald-700 hover:bg-emerald-100/70'
        ]"
      >
        <Check class="h-3.5 w-3.5 shrink-0" :class="localStatus === 'renewed' ? 'text-white' : 'text-emerald-600'" />
        <span>Đã đóng</span>
        <Sparkles v-if="localStatus === 'renewed'" class="h-3 w-3 text-amber-300" />
      </button>
    </div>

    <!-- Quick Action Tool Buttons -->
    <div class="inline-flex items-center gap-1 pl-0.5">
      <!-- 4. [Mẫu tin nhắn] -->
      <button
        type="button"
        @click="emit('open-template', student)"
        title="Mở mẫu tin nhắn soạn sẵn để copy"
        class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white font-medium text-slate-700 hover:bg-slate-50 hover:text-indigo-600 hover:border-indigo-200 shadow-2xs transition-colors"
        :class="compact ? 'px-2 py-1 text-[11px]' : 'px-2.5 py-1 text-xs'"
      >
        <MessageSquare class="h-3.5 w-3.5 text-indigo-500 shrink-0" />
        <span class="hidden sm:inline">Mẫu tin</span>
      </button>

      <!-- 5. [Ghi chú] -->
      <button
        type="button"
        @click="emit('open-note', student)"
        title="Ghi nhật ký cuộc gọi và hẹn lịch"
        class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white font-medium text-slate-700 hover:bg-slate-50 hover:text-amber-600 hover:border-amber-200 shadow-2xs transition-colors"
        :class="compact ? 'px-2 py-1 text-[11px]' : 'px-2.5 py-1 text-xs'"
      >
        <FileText class="h-3.5 w-3.5 text-amber-500 shrink-0" />
        <span class="hidden sm:inline">Ghi chú</span>
      </button>
    </div>
  </div>
</template>
