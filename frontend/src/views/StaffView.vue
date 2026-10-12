<script setup>
import { ref, onMounted } from 'vue';
import { staffApi } from '../services/api';
import {
  Camera,
  Shield,
  Building2,
  Trash2,
  CheckCircle2,
  AlertCircle,
  Copy,
  X,
  UserCheck,
  Lock,
} from 'lucide-vue-next';

const loading = ref(true);
const toastMessage = ref('');
const toastType = ref('success');

const invites = ref([]);
const members = ref([]);
const centers = ref([]);
const roles = ref(['Giáo viên', 'Quản lý / Lễ tân', 'Tư vấn viên']);

// Form invite
const newInvite = ref({
  email: '',
  role: 'Giáo viên',
  center: 'Tất cả trung tâm',
});
const creatingInvite = ref(false);

// Permissions modal
const showPermissionModal = ref(false);
const selectedMember = ref(null);
const allPages = [
  'Doanh thu',
  'Hóa đơn & Thu phí',
  'Khách hàng tiềm năng',
  'Cài đặt',
  'Dữ liệu',
  'Báo cáo',
  'Học cụ',
  'Khóa học',
  'Nhân sự',
  'Trung tâm',
];

const showToast = (msg, type = 'success') => {
  toastMessage.value = msg;
  toastType.value = type;
  setTimeout(() => {
    toastMessage.value = '';
  }, 4000);
};

const fetchData = async () => {
  try {
    loading.value = true;
    const res = await staffApi.getStaff();
    invites.value = res.data.invites || [];
    members.value = res.data.members || [];
    centers.value = res.data.centers || [];
  } catch (err) {
    console.error('Error fetching staff data:', err);
  } finally {
    loading.value = false;
  }
};

onMounted(fetchData);

// 1. Tạo lời mời
const handleCreateInvite = async () => {
  if (!newInvite.value.email.trim()) {
    showToast('Vui lòng nhập email nhân sự', 'error');
    return;
  }

  try {
    creatingInvite.value = true;
    const res = await staffApi.createInvite(newInvite.value);
    const link = res.data.invite_link || window.location.origin;

    // Copy to clipboard
    try {
      await navigator.clipboard.writeText(link);
    } catch {
      // Fallback
    }

    newInvite.value.email = '';
    showToast('Đã tạo lời mời! Link mời đã được tự động copy vào bộ nhớ tạm.');
    fetchData();
  } catch (err) {
    showToast(err.response?.data?.message || 'Có lỗi xảy ra khi tạo lời mời', 'error');
  } finally {
    creatingInvite.value = false;
  }
};

// 2. Đổi vai trò
const handleRoleChange = async (member, newRole) => {
  try {
    await staffApi.updateRole(member.id, { role: newRole });
    showToast(`Đã đổi vai trò của ${member.name || member.email} thành ${newRole}`);
  } catch (err) {
    showToast('Có lỗi xảy ra khi đổi vai trò', 'error');
  }
};

// 3. Mở modal phân quyền
const openPermissions = (member) => {
  selectedMember.value = { ...member, blocked_pages: [...(member.blocked_pages || [])] };
  showPermissionModal.value = true;
};

const togglePageBlock = (page) => {
  if (!selectedMember.value) return;
  const idx = selectedMember.value.blocked_pages.indexOf(page);
  if (idx > -1) {
    selectedMember.value.blocked_pages.splice(idx, 1);
  } else {
    selectedMember.value.blocked_pages.push(page);
  }
};

const handleSavePermissions = async () => {
  if (!selectedMember.value) return;
  try {
    await staffApi.updatePermissions(selectedMember.value.id, {
      blocked_pages: selectedMember.value.blocked_pages,
    });
    showToast('Đã lưu phân quyền nhân sự thành công!');
    showPermissionModal.value = false;
    fetchData();
  } catch (err) {
    showToast('Có lỗi xảy ra khi lưu phân quyền', 'error');
  }
};

// 4. Xóa thành viên
const handleDeleteMember = async (member) => {
  if (!confirm(`Bạn có chắc muốn xóa nhân sự ${member.name || member.email} khỏi trung tâm?`)) return;
  try {
    await staffApi.deleteMember(member.id);
    showToast('Đã xóa thành viên thành công!');
    fetchData();
  } catch (err) {
    showToast('Có lỗi xảy ra khi xóa thành viên', 'error');
  }
};
</script>

<template>
  <div class="space-y-6 pb-12 max-w-6xl">
    <!-- Header -->
    <div>
      <h1 class="text-xl sm:text-2xl font-bold tracking-tight text-slate-900">Nhân sự</h1>
    </div>

    <!-- Toast Notification -->
    <div
      v-if="toastMessage"
      :class="[
        toastType === 'success' ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-rose-50 border-rose-200 text-rose-800',
        'rounded-xl border p-3.5 flex items-center gap-2.5 text-xs sm:text-sm font-medium shadow-xs transition-all'
      ]"
    >
      <CheckCircle2 v-if="toastType === 'success'" class="h-4.5 w-4.5 text-emerald-600 shrink-0" />
      <AlertCircle v-else class="h-4.5 w-4.5 text-rose-600 shrink-0" />
      <span>{{ toastMessage }}</span>
    </div>

    <!-- 1. MỜI NHÂN SỰ -->
    <div class="rounded-2xl border border-slate-200/90 bg-white p-5 shadow-2xs space-y-4">
      <div>
        <h2 class="text-sm sm:text-base font-bold text-slate-900">Mời nhân sự</h2>
        <p class="text-xs text-slate-500 mt-0.5">Gửi link mời để thành viên tham gia thương hiệu.</p>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <!-- Email -->
        <div>
          <label class="block text-xs font-semibold text-slate-700 mb-1.5">Email</label>
          <input
            v-model="newInvite.email"
            type="email"
            placeholder="email@vidu.com"
            class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs sm:text-sm text-slate-800 placeholder-slate-400 focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-500"
          />
        </div>

        <!-- Vai trò -->
        <div>
          <label class="block text-xs font-semibold text-slate-700 mb-1.5">Vai trò</label>
          <select
            v-model="newInvite.role"
            class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-500"
          >
            <option v-for="r in roles" :key="r" :value="r">{{ r }}</option>
          </select>
        </div>

        <!-- Trung tâm -->
        <div>
          <label class="block text-xs font-semibold text-slate-700 mb-1.5">Trung tâm</label>
          <select
            v-model="newInvite.center"
            class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-500"
          >
            <option v-for="c in centers" :key="c" :value="c">{{ c }}</option>
          </select>
        </div>
      </div>

      <div class="space-y-1.5 pt-1">
        <button
          type="button"
          :disabled="creatingInvite"
          @click="handleCreateInvite"
          class="rounded-xl bg-rose-600 hover:bg-rose-700 disabled:opacity-60 px-4 py-2 text-xs sm:text-sm font-bold text-white shadow-xs transition-all active:scale-[0.98]"
        >
          {{ creatingInvite ? 'Đang tạo...' : 'Tạo lời mời' }}
        </button>
        <p class="text-[11px] sm:text-xs text-slate-400">
          Link mời sẽ được copy vào clipboard. Gửi cho người được mời qua bất cứ kênh nào.
        </p>
      </div>
    </div>

    <!-- 2. LỜI MỜI ĐANG CHỜ -->
    <div class="rounded-2xl border border-slate-200/90 bg-white p-5 shadow-2xs space-y-4">
      <div>
        <h2 class="text-sm sm:text-base font-bold text-slate-900">Lời mời đang chờ</h2>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse text-xs sm:text-sm">
          <thead>
            <tr class="border-b border-slate-100 text-[11px] sm:text-xs font-semibold text-slate-500 pb-2">
              <th class="py-2.5 px-3">Email</th>
              <th class="py-2.5 px-3">Vai trò</th>
              <th class="py-2.5 px-3">Trung tâm</th>
              <th class="py-2.5 px-3 text-right">Trạng thái</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="inv in invites" :key="inv.id" class="hover:bg-slate-50/60 transition-colors">
              <td class="py-3 px-3 text-slate-800 font-medium">{{ inv.email }}</td>
              <td class="py-3 px-3 text-slate-600">{{ inv.role }}</td>
              <td class="py-3 px-3 text-slate-600">{{ inv.center }}</td>
              <td class="py-3 px-3 text-right">
                <span
                  :class="[
                    inv.status === 'accepted' ? 'bg-blue-600 text-white' : 'bg-amber-100 text-amber-800',
                    'inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold'
                  ]"
                >
                  {{ inv.status === 'accepted' ? 'Đã tham gia' : 'Đang chờ' }}
                </span>
              </td>
            </tr>

            <tr v-if="invites.length === 0">
              <td colspan="4" class="py-6 text-center text-xs text-slate-400">
                Hiện không có lời mời nào đang chờ.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- 3. THÀNH VIÊN -->
    <div class="rounded-2xl border border-slate-200/90 bg-white p-5 shadow-2xs space-y-4">
      <div>
        <h2 class="text-sm sm:text-base font-bold text-slate-900">Thành viên</h2>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse text-xs sm:text-sm">
          <thead>
            <tr class="border-b border-slate-100 text-[11px] sm:text-xs font-semibold text-slate-500">
              <th class="py-2.5 px-3 w-12 text-center">Ảnh</th>
              <th class="py-2.5 px-3">Email</th>
              <th class="py-2.5 px-3">Vai trò</th>
              <th class="py-2.5 px-3">Quyền</th>
              <th class="py-2.5 px-3">Trung tâm</th>
              <th class="py-2.5 px-3">Tham gia</th>
              <th class="py-2.5 px-3 text-right w-12"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="m in members" :key="m.id" class="hover:bg-slate-50/60 transition-colors">
              <!-- Ảnh -->
              <td class="py-3.5 px-3 text-center">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 bg-slate-50 text-slate-400 mx-auto">
                  <Camera class="h-4 w-4" />
                </div>
              </td>

              <!-- Email -->
              <td class="py-3.5 px-3 font-medium text-slate-900">
                {{ m.email }}
              </td>

              <!-- Vai trò -->
              <td class="py-3.5 px-3">
                <span v-if="m.is_owner" class="text-slate-800 font-semibold">
                  Chủ trung tâm
                </span>
                <select
                  v-else
                  :value="m.role"
                  @change="handleRoleChange(m, $event.target.value)"
                  class="rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs text-slate-800 focus:border-rose-500 focus:outline-none"
                >
                  <option v-for="r in roles" :key="r" :value="r">{{ r }}</option>
                </select>
              </td>

              <!-- Quyền -->
              <td class="py-3.5 px-3">
                <span v-if="m.is_owner" class="text-slate-700 font-medium">
                  Toàn quyền
                </span>
                <button
                  v-else
                  type="button"
                  @click="openPermissions(m)"
                  class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 px-2.5 py-1 text-xs font-medium text-slate-700 shadow-2xs transition-all active:scale-[0.98]"
                >
                  <Shield class="h-3 w-3 text-slate-500" />
                  <span>Chặn {{ (m.blocked_pages || []).length }} trang</span>
                </button>
              </td>

              <!-- Trung tâm -->
              <td class="py-3.5 px-3 text-slate-600">
                <div class="flex items-center gap-1.5">
                  <Building2 v-if="!m.is_owner" class="h-3.5 w-3.5 text-slate-400 shrink-0" />
                  <span>{{ m.center }}</span>
                </div>
              </td>

              <!-- Tham gia -->
              <td class="py-3.5 px-3 text-slate-500 font-mono text-xs">
                {{ m.joined_at }}
              </td>

              <!-- Xóa -->
              <td class="py-3.5 px-3 text-right">
                <button
                  v-if="!m.is_owner"
                  type="button"
                  @click="handleDeleteMember(m)"
                  class="p-1 rounded-lg text-rose-500 hover:text-rose-700 hover:bg-rose-50 transition-colors"
                  title="Xóa thành viên"
                >
                  <Trash2 class="h-4 w-4" />
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- MODAL PHÂN QUYỀN TRANG -->
    <div
      v-if="showPermissionModal"
      class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4"
    >
      <div class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl overflow-hidden border border-slate-200">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100 bg-slate-50/80">
          <div class="flex items-center gap-2 text-slate-900 font-bold text-sm">
            <Lock class="h-4.5 w-4.5 text-rose-600" />
            <span>Phân Quyền Truy Cập (Chặn trang)</span>
          </div>
          <button
            type="button"
            @click="showPermissionModal = false"
            class="rounded-xl p-1.5 text-slate-400 hover:text-slate-600 hover:bg-slate-100"
          >
            <X class="h-5 w-5" />
          </button>
        </div>

        <div class="p-6 space-y-4">
          <p class="text-xs text-slate-500">
            Tích chọn các trang mà bạn muốn <strong class="text-rose-600">CHẶN</strong> nhân sự này truy cập:
          </p>

          <div class="grid grid-cols-2 gap-2.5 max-h-72 overflow-y-auto pr-1">
            <label
              v-for="p in allPages"
              :key="p"
              class="flex items-center gap-2 p-2 rounded-xl border border-slate-200 hover:bg-slate-50 cursor-pointer text-xs font-medium text-slate-700"
            >
              <input
                type="checkbox"
                :checked="selectedMember?.blocked_pages?.includes(p)"
                @change="togglePageBlock(p)"
                class="rounded border-slate-300 text-rose-600 focus:ring-rose-500"
              />
              <span>{{ p }}</span>
            </label>
          </div>
        </div>

        <div class="px-6 py-3 border-t border-slate-100 bg-slate-50 flex items-center justify-end gap-2">
          <button
            type="button"
            @click="showPermissionModal = false"
            class="rounded-xl border border-slate-200 bg-white hover:bg-slate-50 px-4 py-2 text-xs font-semibold text-slate-700"
          >
            Hủy
          </button>
          <button
            type="button"
            @click="handleSavePermissions"
            class="rounded-xl bg-rose-600 hover:bg-rose-700 px-4 py-2 text-xs font-bold text-white shadow-xs"
          >
            Lưu phân quyền
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
