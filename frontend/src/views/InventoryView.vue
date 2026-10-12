<script setup>
import { ref, onMounted, computed } from 'vue';
import { inventoryApi } from '../services/api';
import {
  Download,
  Upload,
  Plus,
  Search,
  Package,
  Edit2,
  Trash2,
  AlertTriangle,
  FileSpreadsheet,
  X,
  CheckCircle2,
  Layers,
  ArrowUpDown,
} from 'lucide-vue-next';

const loading = ref(true);
const items = ref([]);
const centers = ref([]);
const searchQuery = ref('');
const selectedCenter = ref('all');
const totalStockValue = ref(0);

// Modal Thêm / Sửa
const showModal = ref(false);
const isEditing = ref(false);
const editId = ref(null);
const form = ref({
  name: '',
  code: '',
  category: 'Giáo trình',
  unit: 'Cuốn',
  center: '',
  cost_price: 0,
  sell_price: 0,
  quantity: 0,
  min_threshold: 3,
  note: '',
});

// Modal Nhập CSV
const showImportModal = ref(false);
const csvFileInput = ref(null);
const selectedFile = ref(null);
const importLoading = ref(false);
const importMessage = ref('');
const importError = ref('');

// Modal Điều chỉnh tồn kho nhanh
const showAdjustModal = ref(false);
const adjustItem = ref(null);
const adjustDelta = ref(1);
const adjustType = ref('add'); // 'add' or 'sub'
const adjustReason = ref('Nhập thêm hàng');

const fetchData = async () => {
  try {
    loading.value = true;
    const res = await inventoryApi.getAll({
      search: searchQuery.value,
      center: selectedCenter.value,
    });
    items.value = res.data.items || [];
    centers.value = res.data.centers || [];
    totalStockValue.value = res.data.total_stock_value || 0;
    if (!form.value.center && centers.value.length > 0) {
      form.value.center = centers.value[0];
    }
  } catch (err) {
    console.error('Lỗi khi tải kho học cụ:', err);
  } finally {
    loading.value = false;
  }
};

onMounted(() => {
  fetchData();
});

const handleSearch = () => {
  fetchData();
};

const handleCenterChange = () => {
  fetchData();
};

const formatCurrency = (val) => {
  return new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(val || 0);
};

// Mở modal thêm
const openAddModal = () => {
  isEditing.value = false;
  editId.value = null;
  form.value = {
    name: '',
    code: '',
    category: 'Giáo trình',
    unit: 'Cuốn',
    center: selectedCenter.value !== 'all' ? selectedCenter.value : (centers.value[0] || 'Khoa Anh Music Hóc Môn'),
    cost_price: 0,
    sell_price: 0,
    quantity: 0,
    min_threshold: 3,
    note: '',
  };
  showModal.value = true;
};

// Mở modal sửa
const openEditModal = (it) => {
  isEditing.value = true;
  editId.value = it.id;
  form.value = {
    name: it.name,
    code: it.code,
    category: it.category || 'Học cụ',
    unit: it.unit || 'Cái',
    center: it.center || (centers.value[0] || 'Khoa Anh Music Hóc Môn'),
    cost_price: it.cost_price || 0,
    sell_price: it.sell_price || 0,
    quantity: it.quantity || 0,
    min_threshold: it.min_threshold || 3,
    note: it.note || '',
  };
  showModal.value = true;
};

// Lưu Thêm/Sửa
const submitForm = async () => {
  if (!form.value.name.trim()) {
    alert('Vui lòng nhập tên học cụ / vật phẩm!');
    return;
  }
  try {
    if (isEditing.value) {
      await inventoryApi.update(editId.value, form.value);
    } else {
      await inventoryApi.create(form.value);
    }
    showModal.value = false;
    await fetchData();
  } catch (err) {
    console.error('Lỗi lưu học cụ:', err);
    alert('Có lỗi xảy ra: ' + (err.response?.data?.message || err.message));
  }
};

// Xóa học cụ
const deleteItem = async (it) => {
  if (!confirm(`Bạn có chắc muốn xóa "${it.name}" khỏi danh mục học cụ?`)) return;
  try {
    await inventoryApi.delete(it.id);
    await fetchData();
  } catch (err) {
    console.error('Lỗi xóa học cụ:', err);
    alert('Có lỗi xảy ra: ' + (err.response?.data?.message || err.message));
  }
};

// Tải CSV
const downloadCsv = () => {
  const url = inventoryApi.getExportUrl(selectedCenter.value);
  window.open(url, '_blank');
};

// Tải mẫu CSV
const downloadTemplate = () => {
  const url = inventoryApi.getTemplateUrl();
  window.open(url, '_blank');
};

// Mở modal Nhập CSV
const openImportModal = () => {
  selectedFile.value = null;
  importMessage.value = '';
  importError.value = '';
  if (csvFileInput.value) csvFileInput.value.value = '';
  showImportModal.value = true;
};

const handleFileChange = (e) => {
  const file = e.target.files[0];
  selectedFile.value = file || null;
  importMessage.value = '';
  importError.value = '';
};

const submitImportCsv = async () => {
  if (!selectedFile.value) {
    importError.value = 'Vui lòng chọn file CSV để tải lên!';
    return;
  }
  try {
    importLoading.value = true;
    importError.value = '';
    const formData = new FormData();
    formData.append('csv_file', selectedFile.value);
    const res = await inventoryApi.importCsv(formData);
    importMessage.value = res.data.message || 'Nhập file thành công!';
    setTimeout(() => {
      showImportModal.value = false;
      fetchData();
    }, 1500);
  } catch (err) {
    importError.value = err.response?.data?.message || 'Có lỗi xảy ra khi nhập file CSV!';
  } finally {
    importLoading.value = false;
  }
};

// Điều chỉnh kho
const openAdjustModal = (it) => {
  adjustItem.value = it;
  adjustDelta.value = 1;
  adjustType.value = 'add';
  adjustReason.value = 'Nhập thêm hàng';
  showAdjustModal.value = true;
};

const submitAdjust = async () => {
  if (!adjustItem.value) return;
  const delta = adjustType.value === 'add' ? Math.abs(adjustDelta.value) : -Math.abs(adjustDelta.value);
  try {
    await inventoryApi.adjust(adjustItem.value.id, {
      delta,
      reason: adjustReason.value,
    });
    showAdjustModal.value = false;
    await fetchData();
  } catch (err) {
    alert('Lỗi điều chỉnh tồn kho: ' + (err.response?.data?.message || err.message));
  }
};
</script>

<template>
  <div class="space-y-6 pb-12 max-w-7xl">
    <!-- Header -->
    <div>
      <h1 class="text-xl sm:text-2xl font-bold tracking-tight text-slate-900">Học cụ</h1>
    </div>

    <!-- Main Card: Kho học cụ (Khớp chính xác hình tham khảo) -->
    <div class="rounded-2xl border border-slate-200/90 bg-white p-5 shadow-2xs space-y-4">
      <!-- Title & Subtitle -->
      <div>
        <h2 class="text-sm sm:text-base font-bold text-slate-900">Kho học cụ</h2>
        <p class="text-xs text-slate-500 mt-0.5">Quản lý tồn kho, giá nhập và giá bán theo từng trung tâm</p>
      </div>

      <!-- Action & Filter Bar (Khớp 100% bố cục hàng nút và tìm kiếm) -->
      <div class="flex flex-wrap items-center gap-2.5 pt-1">
        <!-- Input tìm vật phẩm -->
        <div class="relative w-full sm:w-56">
          <input
            v-model="searchQuery"
            @keyup.enter="handleSearch"
            type="text"
            placeholder="Tìm vật phẩm..."
            class="w-full rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm text-slate-800 placeholder:text-slate-400 focus:border-rose-500 focus:outline-none"
          />
        </div>

        <!-- Dropdown chọn trung tâm -->
        <select
          v-model="selectedCenter"
          @change="handleCenterChange"
          class="rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none"
        >
          <option value="all">Tất cả trung tâm</option>
          <option v-for="c in centers" :key="c" :value="c">{{ c }}</option>
        </select>

        <!-- Nút Tải CSV -->
        <button
          type="button"
          @click="downloadCsv"
          class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm font-medium text-slate-700 hover:bg-slate-50 transition-all cursor-pointer"
        >
          <Download class="h-3.5 w-3.5 text-slate-500" />
          <span>Tải CSV</span>
        </button>

        <!-- Nút Mẫu CSV -->
        <button
          type="button"
          @click="downloadTemplate"
          class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm font-medium text-slate-700 hover:bg-slate-50 transition-all cursor-pointer"
        >
          <Download class="h-3.5 w-3.5 text-slate-500" />
          <span>Mẫu CSV</span>
        </button>

        <!-- Nút Nhập CSV -->
        <button
          type="button"
          @click="openImportModal"
          class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm font-medium text-slate-700 hover:bg-slate-50 transition-all cursor-pointer"
        >
          <Upload class="h-3.5 w-3.5 text-slate-500" />
          <span>Nhập CSV</span>
        </button>

        <!-- Nút + Thêm học cụ (Đỏ / Rose) -->
        <button
          type="button"
          @click="openAddModal"
          class="inline-flex items-center gap-1.5 rounded-xl bg-rose-600 hover:bg-rose-700 px-3.5 py-1.5 text-xs sm:text-sm font-semibold text-white shadow-2xs transition-all active:scale-[0.98] cursor-pointer"
        >
          <Plus class="h-4 w-4" />
          <span>Thêm học cụ</span>
        </button>
      </div>

      <!-- State: Chưa có dữ liệu (Khi không có items khớp hình mẫu) -->
      <div
        v-if="items.length === 0"
        class="py-8 text-xs sm:text-sm text-slate-500"
      >
        Chưa có dữ liệu
      </div>

      <!-- State: Bảng dữ liệu học cụ hoàn chỉnh -->
      <div v-else class="pt-2 overflow-x-auto">
        <table class="w-full text-left text-xs sm:text-sm">
          <thead>
            <tr class="border-b border-slate-200 text-slate-500 text-[11px] sm:text-xs uppercase font-semibold">
              <th class="py-2.5 pr-3">Vật phẩm</th>
              <th class="py-2.5 px-3">Phân loại / ĐVT</th>
              <th class="py-2.5 px-3">Cơ sở</th>
              <th class="py-2.5 px-3 text-right">Giá nhập</th>
              <th class="py-2.5 px-3 text-right">Giá bán</th>
              <th class="py-2.5 px-3 text-center">Tồn kho</th>
              <th class="py-2.5 pl-3 text-right">Thao tác</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr
              v-for="it in items"
              :key="it.id"
              class="hover:bg-slate-50/70 transition-colors"
            >
              <!-- Tên & Mã -->
              <td class="py-3 pr-3">
                <div class="font-bold text-slate-900">{{ it.name }}</div>
                <div class="text-[11px] font-mono text-slate-400 mt-0.5">{{ it.code }}</div>
                <div v-if="it.note" class="text-[11px] text-slate-400 italic line-clamp-1 mt-0.5">{{ it.note }}</div>
              </td>

              <!-- Phân loại & ĐVT -->
              <td class="py-3 px-3">
                <span class="inline-flex items-center rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-700">
                  {{ it.category }}
                </span>
                <span class="text-slate-400 text-xs ml-1.5">({{ it.unit }})</span>
              </td>

              <!-- Cơ sở -->
              <td class="py-3 px-3 text-xs text-slate-600">
                {{ it.center }}
              </td>

              <!-- Giá nhập -->
              <td class="py-3 px-3 text-right font-medium text-slate-600">
                {{ formatCurrency(it.cost_price) }}
              </td>

              <!-- Giá bán -->
              <td class="py-3 px-3 text-right font-bold text-slate-900">
                {{ formatCurrency(it.sell_price) }}
              </td>

              <!-- Tồn kho -->
              <td class="py-3 px-3 text-center">
                <div class="inline-flex items-center gap-1.5">
                  <span
                    :class="[
                      'rounded-full px-2.5 py-0.5 text-xs font-bold',
                      it.quantity <= (it.min_threshold || 3)
                        ? 'bg-rose-100 text-rose-700'
                        : 'bg-emerald-100 text-emerald-700',
                    ]"
                  >
                    {{ it.quantity }} {{ it.unit }}
                  </span>
                  <span
                    v-if="it.quantity <= (it.min_threshold || 3)"
                    title="Sắp hết hàng trong kho!"
                    class="text-rose-500"
                  >
                    <AlertTriangle class="h-3.5 w-3.5" />
                  </span>
                </div>
              </td>

              <!-- Thao tác -->
              <td class="py-3 pl-3 text-right">
                <div class="inline-flex items-center gap-1">
                  <!-- Điều chỉnh tồn nhanh (+/-) -->
                  <button
                    type="button"
                    @click="openAdjustModal(it)"
                    title="Nhập / Xuất kho nhanh"
                    class="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100 hover:text-blue-600 transition-colors"
                  >
                    <ArrowUpDown class="h-3.5 w-3.5" />
                  </button>

                  <!-- Chỉnh sửa -->
                  <button
                    type="button"
                    @click="openEditModal(it)"
                    title="Chỉnh sửa thông tin"
                    class="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100 hover:text-amber-600 transition-colors"
                  >
                    <Edit2 class="h-3.5 w-3.5" />
                  </button>

                  <!-- Xóa -->
                  <button
                    type="button"
                    @click="deleteItem(it)"
                    title="Xóa học cụ"
                    class="rounded-lg p-1.5 text-slate-500 hover:bg-rose-50 hover:text-rose-600 transition-colors"
                  >
                    <Trash2 class="h-3.5 w-3.5" />
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>

        <!-- Footer tổng kết nhanh -->
        <div class="pt-4 mt-2 border-t border-slate-100 flex flex-wrap items-center justify-between text-xs text-slate-500 gap-2">
          <span>Tổng số vật phẩm: <strong>{{ items.length }}</strong></span>
          <span>Ước tính giá trị tồn kho (theo giá nhập): <strong class="text-slate-800">{{ formatCurrency(totalStockValue) }}</strong></span>
        </div>
      </div>
    </div>

    <!-- MODAL: THÊM / CHỈNH SỬA HỌC CỤ -->
    <div
      v-if="showModal"
      class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-xs"
    >
      <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl space-y-4">
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
          <h3 class="text-base font-bold text-slate-900">
            {{ isEditing ? 'Chỉnh sửa học cụ' : 'Thêm học cụ mới' }}
          </h3>
          <button
            type="button"
            @click="showModal = false"
            class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
          >
            <X class="h-5 w-5" />
          </button>
        </div>

        <form @submit.prevent="submitForm" class="space-y-3.5 text-xs sm:text-sm">
          <!-- Tên vật phẩm -->
          <div>
            <label class="block font-medium text-slate-700 mb-1">Tên vật phẩm / Học cụ (*)</label>
            <input
              v-model="form.name"
              type="text"
              required
              placeholder="VD: Giáo trình Piano Alfred Tập 1"
              class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none"
            />
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <!-- Mã SKU -->
            <div>
              <label class="block font-medium text-slate-700 mb-1">Mã học cụ (SKU)</label>
              <input
                v-model="form.code"
                type="text"
                placeholder="VD: HC-ALFRED-01"
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none font-mono"
              />
            </div>

            <!-- Phân loại -->
            <div>
              <label class="block font-medium text-slate-700 mb-1">Phân loại</label>
              <select
                v-model="form.category"
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none"
              >
                <option value="Giáo trình">Giáo trình / Sách</option>
                <option value="Phụ kiện">Phụ kiện nhạc cụ</option>
                <option value="Nhạc cụ">Nhạc cụ</option>
                <option value="Đồng phục & Quà">Đồng phục & Quà tặng</option>
                <option value="Khác">Khác</option>
              </select>
            </div>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <!-- Đơn vị tính -->
            <div>
              <label class="block font-medium text-slate-700 mb-1">Đơn vị tính (*)</label>
              <input
                v-model="form.unit"
                type="text"
                required
                placeholder="VD: Cuốn, Cái, Bộ, Cây..."
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none"
              />
            </div>

            <!-- Cơ sở áp dụng -->
            <div>
              <label class="block font-medium text-slate-700 mb-1">Cơ sở / Trung tâm (*)</label>
              <select
                v-model="form.center"
                required
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none"
              >
                <option v-for="c in centers" :key="c" :value="c">{{ c }}</option>
              </select>
            </div>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <!-- Giá nhập -->
            <div>
              <label class="block font-medium text-slate-700 mb-1">Giá nhập (VNĐ)</label>
              <input
                v-model.number="form.cost_price"
                type="number"
                min="0"
                step="1000"
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none"
              />
            </div>

            <!-- Giá bán -->
            <div>
              <label class="block font-medium text-slate-700 mb-1">Giá bán ra (VNĐ)</label>
              <input
                v-model.number="form.sell_price"
                type="number"
                min="0"
                step="1000"
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none font-bold text-rose-600"
              />
            </div>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <!-- Số lượng tồn kho -->
            <div>
              <label class="block font-medium text-slate-700 mb-1">Số lượng tồn ban đầu</label>
              <input
                v-model.number="form.quantity"
                type="number"
                min="0"
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none font-bold"
              />
            </div>

            <!-- Ngưỡng cảnh báo tối thiểu -->
            <div>
              <label class="block font-medium text-slate-700 mb-1">Báo động sắp hết khi &le;</label>
              <input
                v-model.number="form.min_threshold"
                type="number"
                min="0"
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none"
              />
            </div>
          </div>

          <!-- Ghi chú -->
          <div>
            <label class="block font-medium text-slate-700 mb-1">Ghi chú</label>
            <textarea
              v-model="form.note"
              rows="2"
              placeholder="VD: Nhà cung cấp, tình trạng phụ kiện..."
              class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none"
            ></textarea>
          </div>

          <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-100">
            <button
              type="button"
              @click="showModal = false"
              class="rounded-xl border border-slate-200 px-4 py-2 font-medium text-slate-600 hover:bg-slate-50 transition-colors"
            >
              Hủy
            </button>
            <button
              type="submit"
              class="rounded-xl bg-rose-600 hover:bg-rose-700 px-5 py-2 font-semibold text-white shadow-2xs transition-all active:scale-[0.98]"
            >
              {{ isEditing ? 'Cập nhật' : 'Thêm học cụ' }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- MODAL: NHẬP CSV -->
    <div
      v-if="showImportModal"
      class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-xs"
    >
      <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl space-y-4">
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
          <div class="flex items-center gap-2">
            <FileSpreadsheet class="h-5 w-5 text-emerald-600" />
            <h3 class="text-base font-bold text-slate-900">Nhập danh sách học cụ từ CSV</h3>
          </div>
          <button
            type="button"
            @click="showImportModal = false"
            class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
          >
            <X class="h-5 w-5" />
          </button>
        </div>

        <div class="space-y-3 text-xs sm:text-sm">
          <p class="text-slate-600">
            Chọn tệp file định dạng CSV có cấu trúc theo mẫu của hệ thống. Bạn có thể tải file mẫu bên dưới nếu chưa có.
          </p>

          <div class="rounded-xl border-2 border-dashed border-slate-200 p-4 text-center hover:border-rose-300 transition-colors bg-slate-50/50">
            <input
              ref="csvFileInput"
              type="file"
              accept=".csv,.txt"
              @change="handleFileChange"
              class="w-full text-xs text-slate-500 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-rose-50 file:text-rose-700 hover:file:bg-rose-100 cursor-pointer"
            />
          </div>

          <div class="flex items-center justify-between text-xs">
            <span class="text-slate-500">Chưa có file mẫu?</span>
            <button
              type="button"
              @click="downloadTemplate"
              class="font-semibold text-rose-600 hover:underline cursor-pointer"
            >
              Tải file mẫu CSV tại đây
            </button>
          </div>

          <!-- Thông báo -->
          <div v-if="importMessage" class="flex items-center gap-1.5 rounded-lg bg-emerald-50 p-2 text-emerald-700 text-xs">
            <CheckCircle2 class="h-4 w-4 shrink-0" />
            <span>{{ importMessage }}</span>
          </div>

          <div v-if="importError" class="flex items-center gap-1.5 rounded-lg bg-rose-50 p-2 text-rose-700 text-xs">
            <AlertTriangle class="h-4 w-4 shrink-0" />
            <span>{{ importError }}</span>
          </div>

          <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-100">
            <button
              type="button"
              @click="showImportModal = false"
              class="rounded-xl border border-slate-200 px-4 py-2 font-medium text-slate-600 hover:bg-slate-50 transition-colors"
            >
              Đóng
            </button>
            <button
              type="button"
              :disabled="importLoading || !selectedFile"
              @click="submitImportCsv"
              class="rounded-xl bg-rose-600 hover:bg-rose-700 disabled:opacity-50 px-5 py-2 font-semibold text-white shadow-2xs transition-all active:scale-[0.98]"
            >
              {{ importLoading ? 'Đang nhập...' : 'Bắt đầu nhập' }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- MODAL: ĐIỀU CHỈNH TỒN KHO NHANH -->
    <div
      v-if="showAdjustModal && adjustItem"
      class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-xs"
    >
      <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-xl space-y-4">
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
          <h3 class="text-base font-bold text-slate-900">Điều chỉnh tồn kho</h3>
          <button
            type="button"
            @click="showAdjustModal = false"
            class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
          >
            <X class="h-5 w-5" />
          </button>
        </div>

        <div class="space-y-3 text-xs sm:text-sm">
          <div>
            <div class="font-bold text-slate-900">{{ adjustItem.name }}</div>
            <div class="text-xs text-slate-500 mt-0.5">Tồn hiện tại: <strong class="text-slate-800">{{ adjustItem.quantity }} {{ adjustItem.unit }}</strong></div>
          </div>

          <!-- Loại điều chỉnh -->
          <div class="grid grid-cols-2 gap-2">
            <button
              type="button"
              @click="adjustType = 'add'"
              :class="[
                'rounded-xl py-2 font-semibold transition-all border text-center',
                adjustType === 'add' ? 'bg-emerald-50 border-emerald-300 text-emerald-700' : 'border-slate-200 text-slate-600 hover:bg-slate-50'
              ]"
            >
              + Nhập kho thêm
            </button>
            <button
              type="button"
              @click="adjustType = 'sub'"
              :class="[
                'rounded-xl py-2 font-semibold transition-all border text-center',
                adjustType === 'sub' ? 'bg-rose-50 border-rose-300 text-rose-700' : 'border-slate-200 text-slate-600 hover:bg-slate-50'
              ]"
            >
              - Xuất kho / Bán
            </button>
          </div>

          <!-- Số lượng điều chỉnh -->
          <div>
            <label class="block font-medium text-slate-700 mb-1">Số lượng thay đổi ({{ adjustItem.unit }})</label>
            <input
              v-model.number="adjustDelta"
              type="number"
              min="1"
              class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none font-bold"
            />
          </div>

          <!-- Lý do -->
          <div>
            <label class="block font-medium text-slate-700 mb-1">Lý do điều chỉnh</label>
            <select
              v-model="adjustReason"
              class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none"
            >
              <option value="Nhập thêm hàng">Nhập thêm hàng từ nhà cung cấp</option>
              <option value="Bán trực tiếp cho học viên">Bán trực tiếp cho học viên</option>
              <option value="Học cụ hỏng / Hao hụt">Học cụ bị hỏng / Hao hụt</option>
              <option value="Kiểm kê điều chỉnh thực tế">Kiểm kê định kỳ cân đối thực tế</option>
            </select>
          </div>

          <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-100">
            <button
              type="button"
              @click="showAdjustModal = false"
              class="rounded-xl border border-slate-200 px-4 py-2 font-medium text-slate-600 hover:bg-slate-50 transition-colors"
            >
              Hủy
            </button>
            <button
              type="button"
              @click="submitAdjust"
              class="rounded-xl bg-rose-600 hover:bg-rose-700 px-5 py-2 font-semibold text-white shadow-2xs transition-all active:scale-[0.98]"
            >
              Xác nhận
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
