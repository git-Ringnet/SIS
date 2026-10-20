<script setup>
import { useCenters } from '../services/useCenters';
import { toLocalDateString } from '../services/date';
import { notify } from '../services/notify';
import { ref, watch, computed } from 'vue';
import Modal from './Modal.vue';
import { studentApi, inventoryApi } from '../services/api';
import { Package, X, Calendar, Building2, Search, Check, Plus, Minus, Trash2 } from 'lucide-vue-next';

const props = defineProps({
  show: Boolean,
  student: Object,
});

const emit = defineEmits(['close', 'added']);

const deliveryDate = ref(toLocalDateString());
const { centerNames, defaultCenter, loadCenters } = useCenters();
const selectedCenter = ref('');
const searchQuery = ref('');
const isSubmitting = ref(false);

// Danh mục học cụ lấy từ màn Học cụ, theo trung tâm đang chọn
const inventoryItems = ref([]);
const availableSupplies = computed(() =>
  inventoryItems.value
    .filter((it) => !selectedCenter.value || it.center === selectedCenter.value)
    .map((it) => ({ id: it.id, name: it.name, price: it.sell_price ?? 0, category: it.category, stock: Number(it.quantity) || 0, unit: it.unit || '' })),
);

const loadInventory = async () => {
  try {
    const res = await inventoryApi.getAll();
    inventoryItems.value = res.data?.items || [];
  } catch (err) {
    console.error('Lỗi tải danh mục học cụ:', err);
  }
};

const selectedItems = ref([]);

watch(
  () => props.show,
  (val) => {
    if (val) {
      loadInventory();
      loadCenters().then(() => {
        selectedCenter.value = props.student?.center || defaultCenter.value;
      });
      deliveryDate.value = toLocalDateString();
      searchQuery.value = '';
      selectedItems.value = [];
    }
  }
);

const filteredCatalog = computed(() => {
  if (!searchQuery.value.trim()) return availableSupplies.value;
  return availableSupplies.value.filter((item) =>
    item.name.toLowerCase().includes(searchQuery.value.toLowerCase().trim())
  );
});

// Đổi trung tâm thì bỏ các món đang chọn của trung tâm cũ (tồn kho tính theo từng trung tâm)
watch(selectedCenter, (center, old) => {
  if (old) selectedItems.value = [];
});

const toggleSelectSupply = (item) => {
  if (item.stock <= 0 && !isItemSelected(item)) {
    notify.warning(`${item.name} đã hết hàng trong kho`);
    return;
  }
  const existing = selectedItems.value.find((i) => i.name === item.name);
  if (existing) {
    selectedItems.value = selectedItems.value.filter((i) => i.name !== item.name);
  } else {
    selectedItems.value.push({
      inventory_id: item.id,
      stock: item.stock,
      unit: item.unit,
      name: item.name,
      quantity: 1,
      price: item.price,
    });
  }
};

const isItemSelected = (item) => {
  return selectedItems.value.some((i) => i.name === item.name);
};

const updateQuantity = (item, delta) => {
  const target = selectedItems.value.find((i) => i.name === item.name);
  if (target) {
    if (delta > 0 && target.quantity >= target.stock) {
      notify.warning(`Trong kho chỉ còn ${target.stock} ${target.unit || ''} ${target.name}`.replace(/\s+/g, ' '));
      return;
    }
    target.quantity = Math.max(1, Math.min(target.stock, target.quantity + delta));
  }
};

const formatVND = (val) => {
  return new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(val || 0);
};

const submitAddSupplies = async () => {
  if (!props.student || selectedItems.value.length === 0) {
    notify.warning('Vui lòng chọn ít nhất 1 học cụ để giao');
    return;
  }

  try {
    isSubmitting.value = true;
    await studentApi.addSupplies(props.student.id, {
      delivery_date: deliveryDate.value,
      center: selectedCenter.value,
      items: selectedItems.value.map(({ inventory_id, name, quantity, price }) => ({ inventory_id, name, quantity, price })),
    });
    emit('added');
    emit('close');
  } catch (err) {
    notify.apiError(err, 'Có lỗi xảy ra khi thêm học cụ');
  } finally {
    isSubmitting.value = false;
  }
};
</script>

<template>
  <Modal :show="show" max-width="lg" @close="emit('close')">
    <div class="p-6">
      <!-- Modal Header matching Screenshot 2 -->
      <div class="flex items-center justify-between border-b border-slate-100 pb-4">
        <h3 class="text-base sm:text-lg font-black text-slate-900">
          Thêm học cụ &mdash; {{ student?.name }}
        </h3>
        <button
          @click="emit('close')"
          class="rounded-lg p-1 text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition-colors"
        >
          <X class="h-5 w-5" />
        </button>
      </div>

      <div class="mt-4 space-y-4">
        <!-- Ngày giao -->
        <div>
          <label class="block text-xs font-bold text-slate-700 mb-1">Ngày giao</label>
          <div class="relative">
            <input
              type="date"
              v-model="deliveryDate"
              class="w-full rounded-xl border border-red-300 bg-white px-3.5 py-2.5 text-xs sm:text-sm font-semibold text-slate-900 focus:border-red-500 focus:outline-none focus:ring-1 focus:ring-red-500 transition-all"
            />
          </div>
        </div>

        <!-- Center & Search Filters matching Screenshot 2 -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
          <div>
            <select
              v-model="selectedCenter"
              class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3 py-2 text-xs font-medium focus:border-red-500 focus:bg-white focus:outline-none focus:ring-1 focus:ring-red-500 transition-all"
            >
              <option v-for="c in centerNames" :key="c" :value="c">{{ c }}</option>
            </select>
          </div>

          <div class="relative">
            <Search class="absolute left-3 top-2.5 h-3.5 w-3.5 text-slate-400" />
            <input
              type="text"
              v-model="searchQuery"
              placeholder="Tìm vật phẩm..."
              class="w-full rounded-xl border border-slate-200 bg-slate-50/50 pl-8 pr-3 py-2 text-xs font-medium focus:border-red-500 focus:bg-white focus:outline-none focus:ring-1 focus:ring-red-500 transition-all"
            />
          </div>
        </div>

        <!-- Catalog items selector -->
        <div class="rounded-xl border border-slate-200 p-2 max-h-48 overflow-y-auto space-y-1.5 bg-slate-50/30">
          <div
            v-for="item in filteredCatalog"
            :key="item.id"
            @click="toggleSelectSupply(item)"
            class="flex items-center justify-between p-2 rounded-lg cursor-pointer transition-all border text-xs"
            :class="[
              isItemSelected(item)
                ? 'bg-red-50/70 border-red-200 text-red-950 font-semibold'
                : item.stock <= 0
                  ? 'bg-slate-50 border-slate-100 text-slate-400 cursor-not-allowed'
                  : 'bg-white border-slate-100 hover:bg-slate-50 text-slate-700'
            ]"
          >
            <div class="flex items-center gap-2 min-w-0">
              <div
                class="flex h-4 w-4 items-center justify-center rounded border transition-colors shrink-0"
                :class="isItemSelected(item) ? 'bg-red-600 border-red-600 text-white' : 'border-slate-300 bg-white'"
              >
                <Check v-if="isItemSelected(item)" class="h-3 w-3" />
              </div>
              <span class="truncate">{{ item.name }}</span>
            </div>
            <span class="flex items-center gap-2 shrink-0 ml-2">
              <span :class="['text-[11px] font-semibold', item.stock <= 0 ? 'text-rose-600' : 'text-slate-400']">
                {{ item.stock <= 0 ? 'Hết hàng' : `Còn ${item.stock}` }}
              </span>
              <span class="text-[11px] font-mono font-bold text-slate-500">{{ formatVND(item.price) }}</span>
            </span>
          </div>

          <div v-if="filteredCatalog.length === 0" class="py-4 text-center text-xs text-slate-400">
            Chưa có học cụ phù hợp.
          </div>
        </div>

        <!-- Selected items summary -->
        <div v-if="selectedItems.length > 0" class="rounded-xl bg-red-50/50 border border-red-100 p-3 space-y-2">
          <p class="text-[11px] font-bold text-red-700 uppercase tracking-wider">
            Đã chọn ({{ selectedItems.length }} món)
          </p>
          <div v-for="sel in selectedItems" :key="sel.name" class="flex items-center justify-between text-xs">
            <span class="font-medium text-slate-800 truncate max-w-[200px]">{{ sel.name }}</span>
            <div class="flex items-center gap-2 shrink-0">
              <button
                type="button"
                @click="updateQuantity(sel, -1)"
                class="h-5 w-5 rounded bg-white border border-slate-200 flex items-center justify-center text-slate-600 hover:bg-slate-100"
              >
                <Minus class="h-3 w-3" />
              </button>
              <span class="font-bold font-mono px-1" :title="`Tồn kho: ${sel.stock}`">{{ sel.quantity }}<span class="font-normal text-slate-400">/{{ sel.stock }}</span></span>
              <button
                type="button"
                :disabled="sel.quantity >= sel.stock"
                :title="sel.quantity >= sel.stock ? `Kho chỉ còn ${sel.stock}` : 'Tăng số lượng'"
                @click="updateQuantity(sel, 1)"
                class="h-5 w-5 rounded bg-white border border-slate-200 flex items-center justify-center text-slate-600 hover:bg-slate-100 disabled:opacity-40 disabled:cursor-not-allowed"
              >
                <Plus class="h-3 w-3" />
              </button>
            </div>
          </div>
        </div>

        <!-- Submit Button matching Screenshot 2 -->
        <button
          type="button"
          @click="submitAddSupplies"
          :disabled="isSubmitting || selectedItems.length === 0"
          class="w-full rounded-xl bg-red-400 hover:bg-red-500 text-white font-bold py-3 text-xs sm:text-sm shadow-xs transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
        >
          {{ isSubmitting ? 'Đang cập nhật...' : 'Thêm các mục đã chọn' }}
        </button>
      </div>
    </div>
  </Modal>
</template>
