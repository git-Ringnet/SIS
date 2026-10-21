<script setup>
import { ref, watch } from 'vue';
import { X, Undo2 } from 'lucide-vue-next';
import Modal from './Modal.vue';
import { studentApi } from '../services/api';
import { notify } from '../services/notify';

// Thu hồi học cụ giao nhầm: bỏ khỏi học viên, nhập lại kho, bớt doanh thu học cụ
const props = defineProps({
  show: { type: Boolean, default: false },
  student: { type: Object, default: null },
  supply: { type: Object, default: null },
});
const emit = defineEmits(['close', 'returned']);

const quantity = ref(1);
const reason = ref('');
const saving = ref(false);

watch(
  () => props.supply,
  (s) => {
    quantity.value = Number(s?.quantity || 1);
    reason.value = 'Giao nhầm';
  },
  { immediate: true },
);

const submit = async () => {
  try {
    saving.value = true;
    const res = await studentApi.returnSupply(props.student.id, props.supply.id, {
      quantity: quantity.value,
      reason: reason.value,
    });
    notify.success(res.data.message);
    emit('returned', res.data.student);
    emit('close');
  } catch (err) {
    notify.apiError(err, 'Không thu hồi được học cụ');
  } finally {
    saving.value = false;
  }
};
</script>

<template>
  <Modal :show="show" max-width="md" @close="emit('close')">
    <form v-if="supply" @submit.prevent="submit" class="p-5 space-y-4 text-xs sm:text-sm">
      <div class="flex items-center justify-between border-b border-slate-100 pb-3">
        <h3 class="flex items-center gap-2 text-sm font-bold text-slate-900">
          <Undo2 class="h-4 w-4 text-rose-600" /> Thu hồi học cụ
        </h3>
        <button type="button" @click="emit('close')" class="text-slate-400 hover:text-slate-600">
          <X class="h-4 w-4" />
        </button>
      </div>

      <p class="text-slate-600">
        <strong class="text-slate-900">{{ supply.name }}</strong> đã giao cho {{ student?.name }}
        ({{ supply.quantity }} cái<template v-if="Number(supply.price) > 0">, {{ Number(supply.price).toLocaleString('vi-VN') }}đ/cái</template>).
      </p>

      <div class="grid grid-cols-2 gap-3">
        <label class="text-xs font-semibold text-slate-700">
          Số lượng thu hồi
          <input v-model.number="quantity" type="number" min="1" :max="supply.quantity" required
            class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 font-bold text-slate-900 focus:border-rose-500 focus:outline-none" />
        </label>
        <label class="text-xs font-semibold text-slate-700">
          Lý do
          <input v-model="reason" maxlength="255"
            class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none" />
        </label>
      </div>

      <ul class="rounded-xl bg-slate-50 border border-slate-200 p-3 text-[11px] text-slate-600 list-disc pl-6 space-y-0.5">
        <li>Bỏ {{ quantity }} {{ supply.name }} khỏi học cụ của học viên (cổng phụ huynh không còn hiện).</li>
        <li>Nhập lại {{ quantity }} vào kho của {{ supply.center || 'trung tâm' }}.</li>
        <li v-if="Number(supply.price) > 0">Bớt {{ (Number(supply.price) * quantity).toLocaleString('vi-VN') }}đ doanh thu học cụ.</li>
      </ul>

      <div class="flex justify-end gap-2">
        <button type="button" @click="emit('close')" class="rounded-xl bg-slate-100 hover:bg-slate-200 px-4 py-2 font-semibold text-slate-700">Hủy</button>
        <button type="submit" :disabled="saving" class="rounded-xl bg-rose-600 hover:bg-rose-700 disabled:opacity-50 px-4 py-2 font-bold text-white">
          {{ saving ? 'Đang thu hồi...' : 'Thu hồi' }}
        </button>
      </div>
    </form>
  </Modal>
</template>
