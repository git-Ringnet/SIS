<script setup>
import { notify } from '../services/notify';
import { ref } from 'vue';
import { retentionApi } from '../services/api';
import Modal from './Modal.vue';
import DatePickerVN from './DatePickerVN.vue';
import { FileText, Calendar, Send, X } from 'lucide-vue-next';

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

const actionType = ref('call_care');
const status = ref('following_up');
const content = ref('');
const nextFollowupDate = ref('');
const isSubmitting = ref(false);

const actionTypes = [
  { value: 'call_care', label: 'Gọi điện chăm sóc' },
  { value: 'sms_zalo_sent', label: 'Nhắn tin Zalo / SMS' },
  { value: 'invoice_sent', label: 'Gửi hóa đơn nhắc phí' },
  { value: 'note_added', label: 'Ghi chú chung' },
];

const submit = async () => {
  if (!content.value.trim() || !props.student) return;

  try {
    isSubmitting.value = true;
    const res = await retentionApi.addLog(props.student.id, {
      content: content.value,
      action_type: actionType.value,
      status: status.value,
      next_followup_date: nextFollowupDate.value || null,
    });
    content.value = '';
    nextFollowupDate.value = '';
    emit('saved', res.data.student);
    emit('close');
  } catch (err) {
    notify.apiError(err, 'Có lỗi xảy ra khi lưu ghi chú');
  } finally {
    isSubmitting.value = false;
  }
};
</script>

<template>
  <Modal :show="show" max-width="lg" @close="emit('close')">
    <div class="p-6">
      <div class="flex items-center justify-between border-b border-slate-100 pb-4">
        <div class="flex items-center gap-3">
          <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-50 text-amber-600">
            <FileText class="h-5 w-5" />
          </div>
          <div>
            <h3 class="text-base font-bold text-slate-900">
              Ghi Chú Trao Đổi Phụ Huynh
            </h3>
            <p v-if="student" class="text-xs text-slate-500">
              Học viên: <span class="font-semibold text-slate-800">{{ student.name }}</span> - PH: {{ student.parent_name }} ({{ student.parent_phone }})
            </p>
          </div>
        </div>
        <button
          @click="emit('close')"
          class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
        >
          <X class="h-5 w-5" />
        </button>
      </div>

      <form @submit.prevent="submit" class="mt-4 space-y-4">
        <div>
          <label class="block text-xs font-semibold text-slate-700">Hình thức tương tác</label>
          <div class="mt-1.5 grid grid-cols-2 gap-2">
            <label
              v-for="act in actionTypes"
              :key="act.value"
              class="flex cursor-pointer items-center gap-2 rounded-lg border p-2 text-xs font-medium transition-all"
              :class="actionType === act.value ? 'border-amber-500 bg-amber-50/50 text-amber-900 ring-1 ring-amber-500' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'"
            >
              <input
                type="radio"
                name="actionType"
                :value="act.value"
                v-model="actionType"
                class="sr-only"
              />
              <span>{{ act.label }}</span>
            </label>
          </div>
        </div>

        <div>
          <label class="block text-xs font-semibold text-slate-700">Cập nhật trạng thái tiến độ</label>
          <select
            v-model="status"
            class="mt-1.5 block w-full rounded-lg border border-slate-200 px-3 py-2 text-xs focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
          >
            <option value="normal">Bình thường (Chưa chăm sóc)</option>
            <option value="invoice_sent">Đã gửi hóa đơn (Màu xanh dương)</option>
            <option value="following_up">Đang chăm sóc / Follow-up (Màu cam)</option>
            <option value="renewed">Đã đóng phí gia hạn (Màu xanh lá)</option>
          </select>
        </div>

        <div>
          <label class="block text-xs font-semibold text-slate-700">
            Nội dung trao đổi / phản hồi của phụ huynh <span class="text-rose-500">*</span>
          </label>
          <textarea
            v-model="content"
            rows="4"
            required
            placeholder="Ví dụ: Đã gọi trao đổi về tiến độ học tập, mẹ hẹn thứ 6 sẽ chuyển khoản học phí khóa mới..."
            class="mt-1.5 block w-full rounded-lg border border-slate-200 p-3 text-xs leading-relaxed focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
          ></textarea>
        </div>

        <div>
          <label class="flex items-center gap-1.5 text-xs font-semibold text-slate-700 mb-1">
            <Calendar class="h-3.5 w-3.5 text-slate-400" />
            <span>Hẹn ngày gọi lại / Follow-up tiếp (Tùy chọn)</span>
          </label>
          <DatePickerVN
            v-model="nextFollowupDate"
            :default-today="false"
            placeholder="Chọn ngày hẹn gọi lại..."
          />
        </div>

        <div class="flex items-center justify-end gap-2 border-t border-slate-100 pt-4">
          <button
            type="button"
            @click="emit('close')"
            class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50"
          >
            Hủy
          </button>
          <button
            type="submit"
            :disabled="isSubmitting || !content.trim()"
            class="inline-flex items-center gap-1.5 rounded-lg bg-amber-600 px-4 py-2 text-xs font-semibold text-white shadow-xs hover:bg-amber-700 disabled:opacity-50 transition-colors"
          >
            <Send class="h-3.5 w-3.5" />
            <span>{{ isSubmitting ? 'Đang lưu...' : 'Lưu Ghi Chú' }}</span>
          </button>
        </div>
      </form>
    </div>
  </Modal>
</template>
