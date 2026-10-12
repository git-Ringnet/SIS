<script setup>
import { ref, computed } from 'vue';
import { FileText, Clock, X, CheckCircle, PhoneCall, Receipt, Sparkles, Send, Plus } from 'lucide-vue-next';
import { retentionApi } from '../services/api';

const props = defineProps({
  show: {
    type: Boolean,
    default: false,
  },
  student: {
    type: Object,
    default: null,
  },
});

const emit = defineEmits(['close', 'saved']);

const newNote = ref('');
const isSubmitting = ref(false);

const logs = computed(() => {
  return props.student?.retention_logs || [];
});

const formatDate = (val) => {
  if (!val) return '—';
  const d = new Date(val);
  return d.toLocaleDateString('vi-VN', { hour: '2-digit', minute: '2-digit', day: '2-digit', month: '2-digit', year: 'numeric' });
};

const getActionBadge = (type) => {
  switch (type) {
    case 'invoice_sent':
      return { label: 'Đã gửi hóa đơn', bg: 'bg-blue-50 text-blue-700 border-blue-200' };
    case 'call_care':
      return { label: 'Chăm sóc / Gọi điện', bg: 'bg-amber-50 text-amber-700 border-amber-200' };
    case 'renewed_payment':
      return { label: 'Đã đóng / Tái đăng ký', bg: 'bg-emerald-50 text-emerald-700 border-emerald-200' };
    default:
      return { label: 'Ghi chú', bg: 'bg-slate-50 text-slate-700 border-slate-200' };
  }
};

const handleAddNote = async () => {
  if (!newNote.value.trim() || !props.student) return;
  try {
    isSubmitting.value = true;
    const res = await retentionApi.addLog(props.student.id, {
      content: newNote.value.trim(),
      action_type: 'call_care',
      status: 'following_up',
    });
    newNote.value = '';
    emit('saved', res.data.student);
  } catch (err) {
    console.error('Error adding retention log:', err);
  } finally {
    isSubmitting.value = false;
  }
};
</script>

<template>
  <div v-if="show" class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="relative w-full max-w-lg bg-white rounded-2xl shadow-2xl overflow-hidden border border-slate-200">
      <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100 bg-slate-50/80">
        <div class="flex items-center gap-2 text-slate-900 font-bold text-sm">
          <FileText class="h-4.5 w-4.5 text-blue-600" />
          <span>Lịch Sử Chăm Sóc & Ghi Chú Học Viên</span>
        </div>
        <button
          type="button"
          @click="emit('close')"
          class="rounded-xl p-1.5 text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition-colors"
        >
          <X class="h-5 w-5" />
        </button>
      </div>

      <div class="p-6 space-y-4">
        <!-- Student Header -->
        <div class="bg-blue-50/70 rounded-xl p-3 border border-blue-100 flex items-center justify-between">
          <div>
            <h4 class="font-bold text-slate-900 text-sm uppercase">{{ student?.name }}</h4>
            <p class="text-xs text-slate-500">PH: {{ student?.parent_name || 'N/A' }} &bull; SĐT: {{ student?.parent_phone || 'Chưa có' }}</p>
          </div>
          <span class="rounded-lg bg-blue-600 text-white px-2.5 py-1 text-xs font-bold shadow-2xs">
            {{ student?.remaining_sessions }} buổi còn lại
          </span>
        </div>

        <!-- Add Note Section -->
        <div class="rounded-xl border border-amber-200 bg-amber-50/30 p-3 space-y-2">
          <label class="block text-xs font-bold text-amber-900 flex items-center gap-1.5">
            <Plus class="h-3.5 w-3.5 text-amber-600" />
            <span>Thêm ghi chú chăm sóc mới</span>
          </label>
          <textarea
            v-model="newNote"
            rows="2"
            placeholder="Nhập nội dung đã trao đổi với phụ huynh (ví dụ: mẹ hẹn tuần sau chuyển khoản, bé sắp đi du lịch...)"
            class="w-full rounded-lg border border-slate-200 bg-white p-2 text-xs text-slate-800 placeholder-slate-400 focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500"
          ></textarea>
          <div class="flex justify-end">
            <button
              type="button"
              :disabled="!newNote.trim() || isSubmitting"
              @click="handleAddNote"
              class="inline-flex items-center gap-1 rounded-lg bg-amber-500 hover:bg-amber-600 disabled:opacity-50 px-3 py-1.5 text-xs font-semibold text-white shadow-2xs transition-all active:scale-[0.98]"
            >
              <Send class="h-3 w-3" />
              <span>{{ isSubmitting ? 'Đang lưu...' : 'Lưu ghi chú' }}</span>
            </button>
          </div>
        </div>

        <!-- Logs Timeline -->
        <div>
          <div class="text-xs font-bold text-slate-700 mb-2">Danh sách trao đổi & ghi chú trước đây:</div>
          <div class="space-y-2.5 max-h-60 overflow-y-auto pr-1">
            <div
              v-for="(log, idx) in logs"
              :key="log.id || idx"
              class="rounded-xl border border-slate-200/80 p-3 bg-white space-y-1 shadow-2xs"
            >
              <div class="flex items-center justify-between text-xs">
                <span
                  :class="[
                    getActionBadge(log.action_type).bg,
                    'px-2 py-0.5 rounded border font-bold text-[11px]'
                  ]"
                >
                  {{ getActionBadge(log.action_type).label }}
                </span>
                <span class="text-slate-400 font-mono text-[11px] flex items-center gap-1">
                  <Clock class="h-3 w-3" />
                  {{ formatDate(log.contact_date || log.created_at) }}
                </span>
              </div>
              <p class="text-xs text-slate-700 leading-relaxed font-medium">
                {{ log.content || 'Không có nội dung' }}
              </p>
            </div>

            <div v-if="logs.length === 0" class="py-6 text-center text-xs text-slate-400">
              Chưa có lịch sử chăm sóc nào cho học viên này
            </div>
          </div>
        </div>
      </div>

      <div class="px-6 py-3 border-t border-slate-100 bg-slate-50 flex justify-end">
        <button
          type="button"
          @click="emit('close')"
          class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50"
        >
          Đóng
        </button>
      </div>
    </div>
  </div>
</template>

