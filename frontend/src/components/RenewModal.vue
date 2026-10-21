<script setup>
import { notify } from '../services/notify';
import { confirmDialog } from '../services/useConfirm';
import { computed, ref, watch } from 'vue';
import { invoiceApi, retentionApi } from '../services/api';
import Modal from './Modal.vue';
import { CheckCircle2, DollarSign, PlusCircle, X } from 'lucide-vue-next';
import MoneyInput from './MoneyInput.vue';
import { latestPaidInvoice, pendingInvoice, tuitionPaid } from '../services/tuition';

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
      note.value = `Thu học phí khóa học ${s.course?.name || ''} cho học viên ${s.name}`;
    }
  },
  { immediate: true }
);

// Đã đóng học phí gần đây: cảnh báo để tránh thu trùng
const lastPaid = computed(() => latestPaidInvoice(props.student));
const alreadyPaid = computed(() => tuitionPaid(props.student));
// Phiếu chờ thu: phụ huynh đóng tiền thì xác nhận phiếu này (không cộng thêm buổi)
const pending = computed(() => pendingInvoice(props.student));
const confirmingPaid = ref(false);
const confirmPaid = async () => {
  try {
    confirmingPaid.value = true;
    const res = await invoiceApi.markPaid(pending.value.id, {
      payment_method: paymentMethod.value,
      paid_at: new Date().toISOString().slice(0, 10),
    });
    notify.success(res.data.message || 'Đã xác nhận thu tiền');
    emit('renewed');
    emit('close');
  } catch (err) {
    notify.apiError(err, 'Không xác nhận được');
  } finally {
    confirmingPaid.value = false;
  }
};

// Hủy lần thu gần nhất (thu / gia hạn nhầm): trừ lại số buổi, khôi phục được ở trang Hóa đơn
const cancellingPaid = ref(false);
const cancelLastPaid = async () => {
  if (!(await confirmDialog({
    title: 'Hủy lần thu này?',
    message: `Phiếu ${lastPaid.value.invoice_code} (${Number(lastPaid.value.amount || 0).toLocaleString('vi-VN')}đ) sẽ bị hủy, học viên bị trừ lại ${lastPaid.value.sessions_added || 0} buổi đã cộng. Có thể khôi phục ở trang Hóa đơn.`,
    confirmText: 'Hủy phiếu',
  }))) return;
  try {
    cancellingPaid.value = true;
    const res = await invoiceApi.cancel(lastPaid.value.id, { reason: 'Thu / gia hạn nhầm' });
    notify.success(res.data.message);
    emit('renewed');
    emit('close');
  } catch (err) {
    notify.apiError(err, 'Không hủy được phiếu thu');
  } finally {
    cancellingPaid.value = false;
  }
};

const formatDay = (value) => (value ? new Date(value).toLocaleDateString('vi-VN') : '');

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
    notify.apiError(err, 'Có lỗi xảy ra khi thu học phí');
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
              Thu Học Phí & Cấp Buổi Học
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

      <!-- Phụ huynh chưa đóng học phí lúc đăng ký: xác nhận thu phiếu chờ -->
      <div v-if="pending" class="mt-4 rounded-xl bg-amber-50 p-3 text-xs text-amber-900 border border-amber-300 space-y-2">
        <p>
          <span class="font-bold">Phiếu chờ thu {{ pending.invoice_code }}:</span>
          {{ Number(pending.amount || 0).toLocaleString('vi-VN') }}đ<template v-if="pending.sessions_added"> ({{ pending.sessions_added }} buổi đã cấp)</template>.
          Phụ huynh đóng tiền thì xác nhận phiếu này, không cộng thêm buổi.
        </p>
        <div class="flex flex-wrap items-center gap-2">
          <select v-model="paymentMethod" class="rounded-lg border border-amber-300 bg-white px-2 py-1.5 text-xs">
            <option value="bank_transfer">Chuyển khoản</option>
            <option value="cash">Tiền mặt</option>
            <option value="pos">Quẹt POS</option>
            <option value="momo">MoMo / QR</option>
          </select>
          <button type="button" @click="confirmPaid" :disabled="confirmingPaid"
            class="rounded-lg bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 px-3 py-1.5 font-bold text-white">
            {{ confirmingPaid ? 'Đang lưu...' : 'Xác nhận đã thu' }}
          </button>
        </div>
        <p class="text-[11px] text-amber-700">Hoặc dùng form bên dưới để thu thêm / gia hạn khóa mới (cộng thêm buổi).</p>
      </div>

      <form @submit.prevent="submit" class="mt-4 space-y-4">
        <div v-if="alreadyPaid" class="rounded-xl bg-amber-50 p-3 text-xs text-amber-900 border border-amber-300">
          <p class="font-bold">⚠️ Học viên này đã đóng học phí<template v-if="lastPaid?.paid_at || lastPaid?.created_at"> ngày {{ formatDay(lastPaid.paid_at || lastPaid.created_at) }}</template>
            ({{ Number(lastPaid?.amount || 0).toLocaleString('vi-VN') }}đ<template v-if="lastPaid?.sessions_added">, +{{ lastPaid.sessions_added }} buổi</template>)
            và còn {{ student.remaining_sessions }} buổi.</p>
          <p class="mt-0.5">Chỉ thu tiếp khi phụ huynh đóng thêm / gia hạn khóa mới, tránh cộng trùng buổi.</p>
          <button type="button" @click="cancelLastPaid" :disabled="cancellingPaid"
            class="mt-2 rounded-lg border border-rose-300 bg-white hover:bg-rose-50 disabled:opacity-50 px-2.5 py-1 font-semibold text-rose-700">
            {{ cancellingPaid ? 'Đang hủy...' : 'Thu / gia hạn nhầm? Hủy lần thu này' }}
          </button>
        </div>
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
            <MoneyInput
              v-model="renewAmount"
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
