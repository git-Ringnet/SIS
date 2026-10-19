<script setup>
import { notify } from '../services/notify';
import { confirmDialog } from '../services/useConfirm';
import { ref, onMounted } from 'vue';
import { centerApi } from '../services/api';
import { useCenters } from '../services/useCenters';
import {
  Plus,
  Building2,
  BarChart3,
  Edit2,
  Trash2,
  X,
  DoorOpen,
  Users,
} from 'lucide-vue-next';

const { loadCenters } = useCenters();

const loading = ref(true);
const centers = ref([]);

// Center Create/Edit Modal
const showCenterModal = ref(false);
const isEditing = ref(false);
const centerForm = ref({
  id: null,
  name: '',
  address: '',
  phone: '',
});
const savingCenter = ref(false);

// Rooms Management Modal
const showRoomsModal = ref(false);
const selectedCenterForRooms = ref(null);
const newRoomForm = ref({
  name: '',
  capacity: 4,
  description: '',
});
const savingRoom = ref(false);

const showToast = (msg, type = 'success') => {
  if (type === 'success') {
    notify.success(msg);
  } else if (msg.startsWith('Vui lòng')) {
    notify.warning(msg);
  } else {
    notify.error(msg);
  }
};

const fetchData = async () => {
  try {
    loading.value = true;
    const res = await centerApi.getCenters();
    centers.value = res.data.centers || [];
    // Cập nhật danh sách trung tâm dùng chung cho các màn khác
    loadCenters(true);
  } catch (err) {
    console.error('Error fetching centers:', err);
  } finally {
    loading.value = false;
  }
};

onMounted(fetchData);

// 1. Mở modal thêm trung tâm mới
const openCreateCenter = () => {
  isEditing.value = false;
  centerForm.value = {
    id: null,
    name: '',
    address: '',
    phone: '',
  };
  showCenterModal.value = true;
};

// 2. Mở modal sửa trung tâm
const openEditCenter = (c) => {
  isEditing.value = true;
  centerForm.value = {
    id: c.id,
    name: c.name,
    address: c.address || '',
    phone: c.phone || '',
  };
  showCenterModal.value = true;
};

// 3. Lưu trung tâm (Tạo / Sửa)
const handleSaveCenter = async () => {
  if (!centerForm.value.name.trim()) {
    showToast('Vui lòng nhập tên trung tâm', 'error');
    return;
  }

  try {
    savingCenter.value = true;
    if (isEditing.value) {
      await centerApi.updateCenter(centerForm.value.id, centerForm.value);
      showToast('Cập nhật trung tâm thành công!');
    } else {
      await centerApi.createCenter(centerForm.value);
      showToast('Tạo trung tâm mới thành công!');
    }
    showCenterModal.value = false;
    fetchData();
  } catch (err) {
    showToast(err.response?.data?.message || 'Có lỗi xảy ra', 'error');
  } finally {
    savingCenter.value = false;
  }
};

// 4. Xóa trung tâm
const handleDeleteCenter = async (c) => {
  if (!(await confirmDialog({
    title: 'Xoá cơ sở?',
    message: `Cơ sở "${c.name}" cùng các phòng học sẽ bị xoá.`,
    confirmText: 'Xoá cơ sở',
  }))) return;
  try {
    await centerApi.deleteCenter(c.id);
    showToast(`Đã xóa trung tâm "${c.name}" thành công!`);
    fetchData();
  } catch (err) {
    showToast('Có lỗi xảy ra khi xóa trung tâm', 'error');
  }
};

// 5. Mở modal quản lý phòng học
const openRoomsModal = (c) => {
  selectedCenterForRooms.value = c;
  newRoomForm.value = {
    name: '',
    capacity: 4,
    description: '',
  };
  showRoomsModal.value = true;
};

// 6. Thêm phòng học
const handleAddRoom = async () => {
  if (!newRoomForm.value.name.trim()) {
    showToast('Vui lòng nhập tên phòng học', 'error');
    return;
  }

  try {
    savingRoom.value = true;
    const res = await centerApi.addRoom(selectedCenterForRooms.value.id, newRoomForm.value);
    centers.value = res.data.centers || [];
    // Cập nhật lại đối tượng đang xem
    const updated = centers.value.find((c) => c.id === selectedCenterForRooms.value.id);
    if (updated) selectedCenterForRooms.value = updated;

    newRoomForm.value = { name: '', capacity: 4, description: '' };
    showToast('Thêm phòng học thành công!');
  } catch (err) {
    showToast('Có lỗi xảy ra khi thêm phòng học', 'error');
  } finally {
    savingRoom.value = false;
  }
};

// 7. Xóa phòng học
const handleDeleteRoom = async (roomId) => {
  try {
    const res = await centerApi.deleteRoom(selectedCenterForRooms.value.id, roomId);
    centers.value = res.data.centers || [];
    const updated = centers.value.find((c) => c.id === selectedCenterForRooms.value.id);
    if (updated) selectedCenterForRooms.value = updated;
    showToast('Đã xóa phòng học thành công!');
  } catch (err) {
    showToast('Có lỗi xảy ra khi xóa phòng học', 'error');
  }
};
</script>

<template>
  <div class="space-y-6 pb-12 max-w-6xl">
    <!-- Header -->
    <div>
      <h1 class="text-xl sm:text-2xl font-bold tracking-tight text-slate-900">Trung tâm</h1>
    </div>


    <!-- Card: BẢNG TRUNG TÂM -->
    <div class="rounded-2xl border border-slate-200/90 bg-white p-5 shadow-2xs space-y-4">
      <div class="flex items-center justify-between">
        <div>
          <h2 class="text-sm sm:text-base font-bold text-slate-900">Trung tâm</h2>
        </div>

        <button
          type="button"
          @click="openCreateCenter"
          class="inline-flex items-center gap-1.5 rounded-xl bg-rose-600 hover:bg-rose-700 px-4 py-2 text-xs sm:text-sm font-bold text-white shadow-xs transition-all active:scale-[0.98]"
        >
          <Plus class="h-4 w-4" />
          <span>Trung tâm mới</span>
        </button>
      </div>

      <!-- Table -->
      <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse text-xs sm:text-sm">
          <thead>
            <tr class="border-b border-slate-100 text-[11px] sm:text-xs font-semibold text-slate-500 pb-2">
              <th class="py-2.5 px-3">Tên</th>
              <th class="py-2.5 px-3">Địa chỉ</th>
              <th class="py-2.5 px-3">Điện thoại</th>
              <th class="py-2.5 px-3 text-right">Thao tác</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="c in centers" :key="c.id" class="hover:bg-slate-50/60 transition-colors">
              <!-- Tên -->
              <td class="py-3.5 px-3">
                <div class="flex items-center gap-2.5">
                  <div class="flex h-7 w-7 items-center justify-center rounded-lg border border-slate-200 bg-slate-50 text-slate-500">
                    <Building2 class="h-4 w-4 text-slate-600" />
                  </div>
                  <span class="font-bold text-slate-900">{{ c.name }}</span>
                </div>
              </td>

              <!-- Địa chỉ -->
              <td class="py-3.5 px-3 text-slate-600 max-w-xs truncate">
                {{ c.address || '—' }}
              </td>

              <!-- Điện thoại -->
              <td class="py-3.5 px-3 text-slate-600 font-mono text-xs">
                {{ c.phone || '—' }}
              </td>

              <!-- Thao tác -->
              <td class="py-3.5 px-3 text-right">
                <div class="inline-flex items-center gap-1.5 justify-end">
                  <!-- [ Phòng học ] -->
                  <button
                    type="button"
                    @click="openRoomsModal(c)"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 px-2.5 py-1 text-xs font-semibold text-slate-700 shadow-2xs transition-all active:scale-[0.98]"
                    title="Quản lý phòng học"
                  >
                    <DoorOpen class="h-3.5 w-3.5 text-slate-500" />
                    <span>Phòng học ({{ (c.rooms || []).length }})</span>
                  </button>

                  <!-- Báo cáo -->
                  <router-link
                    to="/reports"
                    class="p-1.5 rounded-lg text-slate-500 hover:text-blue-600 hover:bg-blue-50 transition-colors"
                    title="Xem báo cáo cơ sở"
                  >
                    <BarChart3 class="h-4 w-4" />
                  </router-link>

                  <!-- Sửa -->
                  <button
                    type="button"
                    @click="openEditCenter(c)"
                    class="p-1.5 rounded-lg text-slate-500 hover:text-slate-800 hover:bg-slate-100 transition-colors"
                    title="Chỉnh sửa thông tin"
                  >
                    <Edit2 class="h-4 w-4" />
                  </button>

                  <!-- Xóa -->
                  <button
                    type="button"
                    @click="handleDeleteCenter(c)"
                    class="p-1.5 rounded-lg text-rose-500 hover:text-rose-700 hover:bg-rose-50 transition-colors"
                    title="Xóa trung tâm"
                  >
                    <Trash2 class="h-4 w-4" />
                  </button>
                </div>
              </td>
            </tr>

            <tr v-if="centers.length === 0">
              <td colspan="4" class="py-8 text-center text-xs text-slate-400">
                Chưa có trung tâm nào. Bấm "+ Trung tâm mới" để tạo cơ sở đầu tiên.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- MODAL 1: THÊM / SỬA TRUNG TÂM -->
    <div
      v-if="showCenterModal"
      class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4"
    >
      <div class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl overflow-hidden border border-slate-200">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100 bg-slate-50/80">
          <div class="flex items-center gap-2 text-slate-900 font-bold text-sm">
            <Building2 class="h-4.5 w-4.5 text-rose-600" />
            <span>{{ isEditing ? 'Chỉnh Sửa Trung Tâm' : 'Thêm Trung Tâm Mới' }}</span>
          </div>
          <button
            type="button"
            @click="showCenterModal = false"
            class="rounded-xl p-1.5 text-slate-400 hover:text-slate-600 hover:bg-slate-100"
          >
            <X class="h-5 w-5" />
          </button>
        </div>

        <div class="p-6 space-y-4">
          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1.5">Tên trung tâm / Cơ sở *</label>
            <input
              v-model="centerForm.name"
              type="text"
              placeholder="ví dụ: Cơ sở Quận 1"
              class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-500"
            />
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1.5">Địa chỉ</label>
            <input
              v-model="centerForm.address"
              type="text"
              placeholder="ví dụ: 12 Lê Lợi, Quận 1, TP.HCM"
              class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-500"
            />
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1.5">Số điện thoại liên hệ</label>
            <input
              v-model="centerForm.phone"
              type="text"
              placeholder="ví dụ: 0792324514"
              class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-500"
            />
          </div>
        </div>

        <div class="px-6 py-3 border-t border-slate-100 bg-slate-50 flex items-center justify-end gap-2">
          <button
            type="button"
            @click="showCenterModal = false"
            class="rounded-xl border border-slate-200 bg-white hover:bg-slate-50 px-4 py-2 text-xs font-semibold text-slate-700"
          >
            Hủy
          </button>
          <button
            type="button"
            :disabled="savingCenter"
            @click="handleSaveCenter"
            class="rounded-xl bg-rose-600 hover:bg-rose-700 disabled:opacity-60 px-4 py-2 text-xs font-bold text-white shadow-xs"
          >
            {{ savingCenter ? 'Đang lưu...' : (isEditing ? 'Lưu thay đổi' : 'Tạo trung tâm') }}
          </button>
        </div>
      </div>
    </div>

    <!-- MODAL 2: QUẢN LÝ PHÒNG HỌC -->
    <div
      v-if="showRoomsModal"
      class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4"
    >
      <div class="relative w-full max-w-xl bg-white rounded-2xl shadow-2xl overflow-hidden border border-slate-200">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100 bg-slate-50/80">
          <div class="flex items-center gap-2 text-slate-900 font-bold text-sm">
            <DoorOpen class="h-4.5 w-4.5 text-blue-600" />
            <span>Phòng Học &mdash; {{ selectedCenterForRooms?.name }}</span>
          </div>
          <button
            type="button"
            @click="showRoomsModal = false"
            class="rounded-xl p-1.5 text-slate-400 hover:text-slate-600 hover:bg-slate-100"
          >
            <X class="h-5 w-5" />
          </button>
        </div>

        <div class="p-6 space-y-5">
          <!-- Form thêm phòng mới -->
          <div class="rounded-xl border border-slate-200 bg-slate-50/60 p-3.5 space-y-3">
            <h4 class="text-xs font-bold text-slate-800 flex items-center gap-1.5">
              <Plus class="h-3.5 w-3.5 text-blue-600" />
              <span>Thêm phòng học mới</span>
            </h4>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5">
              <div>
                <label class="block text-[11px] font-semibold text-slate-600 mb-1">Tên phòng *</label>
                <input
                  v-model="newRoomForm.name"
                  type="text"
                  placeholder="ví dụ: PIANO 03"
                  class="w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                />
              </div>

              <div>
                <label class="block text-[11px] font-semibold text-slate-600 mb-1">Sức chứa (HV)</label>
                <input
                  v-model.number="newRoomForm.capacity"
                  type="number"
                  min="1"
                  class="w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                />
              </div>

              <div>
                <label class="block text-[11px] font-semibold text-slate-600 mb-1">Ghi chú / Loại</label>
                <input
                  v-model="newRoomForm.description"
                  type="text"
                  placeholder="ví dụ: Phòng 1:1"
                  class="w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                />
              </div>
            </div>

            <div class="flex justify-end pt-1">
              <button
                type="button"
                :disabled="savingRoom || !newRoomForm.name.trim()"
                @click="handleAddRoom"
                class="rounded-lg bg-blue-600 hover:bg-blue-700 disabled:opacity-50 px-3.5 py-1.5 text-xs font-semibold text-white shadow-2xs transition-all active:scale-[0.98]"
              >
                {{ savingRoom ? 'Đang thêm...' : 'Thêm phòng' }}
              </button>
            </div>
          </div>

          <!-- Danh sách phòng học hiện tại -->
          <div>
            <h4 class="text-xs font-bold text-slate-800 mb-2">Danh sách phòng học hiện tại:</h4>
            <div class="divide-y divide-slate-100 max-h-64 overflow-y-auto pr-1">
              <div
                v-for="r in (selectedCenterForRooms?.rooms || [])"
                :key="r.id"
                class="py-2.5 flex items-center justify-between gap-3 text-xs"
              >
                <div>
                  <span class="font-bold text-slate-900">{{ r.name }}</span>
                  <span class="ml-2 text-slate-500">({{ r.description || 'Phòng học' }})</span>
                </div>
                <div class="flex items-center gap-3">
                  <span class="rounded-md bg-blue-50 border border-blue-200 px-2 py-0.5 font-bold text-blue-700 text-[11px]">
                    Sức chứa: {{ r.capacity }} học viên
                  </span>
                  <button
                    type="button"
                    @click="handleDeleteRoom(r.id)"
                    class="p-1 rounded text-rose-500 hover:text-rose-700 hover:bg-rose-50 transition-colors"
                    title="Xóa phòng này"
                  >
                    <Trash2 class="h-3.5 w-3.5" />
                  </button>
                </div>
              </div>

              <div
                v-if="!(selectedCenterForRooms?.rooms?.length)"
                class="py-6 text-center text-xs text-slate-400"
              >
                Chưa có phòng học nào được tạo cho cơ sở này.
              </div>
            </div>
          </div>
        </div>

        <div class="px-6 py-3 border-t border-slate-100 bg-slate-50 flex justify-end">
          <button
            type="button"
            @click="showRoomsModal = false"
            class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50"
          >
            Đóng
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
