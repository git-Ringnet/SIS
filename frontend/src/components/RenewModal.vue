<script setup>
import { ref, watch } from 'vue';
import { retentionApi } from '../services/api';
import Modal from './Modal.vue';
import { CheckCircle2, DollarSign, PlusCircle, X } from 'lucide-vue-next';

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

const emit = defineEmits(['close', 'renewed']);

const renewSessions = ref(12);
const renewAmount = ref(3600000);
const paymentMethod = ref('bank_transfer');
const note = ref('');
const isSubmitting = ref(false);

watch(
  () => props.student,
  (s) => {
    if (s) {
      renewSessions.value = s.course ? s.course.default_sessions : 12;
      renewAmount.value = s.course ? Number(s.course.price) : 3600000;
      note.value = `Gia hạn khóa học ${s.course?.name || ''} cho học viên ${s.name}`;
    }
  },
  { immediate: true }
);

const submit = async () => {
  if (!props.student) return;

  try {
    isSubmitting.value = true;
    const res = await retentionApi.updateStatus(props.student.id, {
      status: 'renewed',
      renew_sessions: renewSessions.value,
      renew_amount: renewAmount.value,
      payment_method: paymentMethod.value,
      note: note.value,
    });
    emit('renewed', res.data.student);
    emit('close');
  } catch (err) {
    alert(err.response?.data?.message || 'Có lỗi xảy ra khi gia hạn');
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
          <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600">
            <CheckCircle2 class="h-5 w-5" />
          </div>
          <div>
            <h3 class="text-base font-bold text-slate-900">
              Xác Nhận Đã Đóng Phí & Tái Ký Khóa Học
            </h3>
            <p v-if="student" class="text-xs text-slate-500">
              Học viên: <span class="font-semibold text-slate-800">{{ student.name }}</span> (Hiện còn {{ student.remaining_sessions }} buổi)
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
        <div class="rounded-xl bg-emerald-50/70 p-3 text-xs text-emerald-900 border border-emerald-200/60">
          <p class="font-medium">🎉 Khi xác nhận, hệ thống sẽ tự động:</p>
          <ul class="mt-1 list-disc list-inside space-y-0.5 text-emerald-800">
            <li>Cộng thêm <strong class="font-bold">+{{ renewSessions }} buổi</strong> vào tài khoản học viên.</li>
            <li>Chuyển trạng thái sang <strong class="font-bold">[Đã Đóng] (Màu xanh lá)</strong>.</li>
            <li>Tạo 1 hóa đơn thu tiền vào báo cáo doanh thu tài chính.</li>
          </ul>
        </div>

        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-xs font-semibold text-slate-700">Số buổi cộng thêm</label>
            <input
              type="number"
              min="1"
              v-model.number="renewSessions"
              required
              class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-xs font-bold text-slate-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500"
            />
          </div>
          <div>
            <label class="block text-xs font-semibold text-slate-700">Số tiền thu (VNĐ)</label>
            <input
              type="number"
              min="0"
              step="50000"
              v-model.number="renewAmount"
              required
              class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-xs font-bold text-emerald-700 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500"
            />
          </div>
        </div>

        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-xs font-semibold text-slate-700">Hình thức thanh toán</label>
            <select
              v-model="paymentMethod"
              class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-xs focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500"
            >
              <option value="bank_transfer">Chuyển khoản Ngân hàng</option>
              <option value="cash">Tiền mặt</option>
              <option value="pos">Quẹt thẻ POS</option>
              <option value="momo">Ví MoMo / QR</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-semibold text-slate-700">Khóa học áp dụng</label>
            <input
              type="text"
              readonly
              :value="student?.course?.name || 'Khóa học hiện tại'"
              class="mt-1 block w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600"
            />
          </div>
        </div>

        <div>
          <label class="block text-xs font-semibold text-slate-700">Ghi chú phiếu thu</label>
          <input
            type="text"
            v-model="note"
            placeholder="Ghi chú xác nhận thu tiền..."
            class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-xs focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500"
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
            :disabled="isSubmitting"
            class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-4 py-2 text-xs font-semibold text-white shadow-xs hover:bg-emerald-700 disabled:opacity-50 transition-colors"
          >
            <CheckCircle2 class="h-3.5 w-3.5" />
            <span>{{ isSubmitting ? 'Đang xử lý...' : 'Xác Nhận Tiền Về & Cấp Buổi' }}</span>
          </button>
        </div>
      </form>
    </div>
  </Modal>
</template>
