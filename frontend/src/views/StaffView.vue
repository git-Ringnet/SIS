<script setup>
import { notify } from '../services/notify';
import { confirmDialog } from '../services/useConfirm';
import { ref, computed, onMounted } from 'vue';
import { useAuth } from '../services/useAuth';
import { ALL_PAGES } from '../services/permissions';
import { staffApi } from '../services/api';
import MoneyInput from '../components/MoneyInput.vue';
import {
  Shield,
  Building2,
  Trash2,
  Copy,
  X,
  UserCheck,
  Lock,
  KeyRound,
  Wallet,
} from 'lucide-vue-next';

const loading = ref(true);

const members = ref([]);
const centers = ref([]);
const roles = ref(['Giáo viên', 'Giáo vụ', 'Quản lý']);
const ownerRole = ref('Chủ trung tâm');
const { user } = useAuth();
const isOwner = computed(() => !!user.value?.is_owner);
// Chỉ chủ trung tâm mới thấy (và gán được) vai trò có toàn quyền như chủ trung tâm
const roleOptions = computed(() => (isOwner.value ? [...roles.value, ownerRole.value] : roles.value));
// Chủ trung tâm gốc và chính mình không đổi vai trò / xoá được (tránh tự khoá quyền)
const canEditMember = (m) => isOwner.value && !m.is_protected;

// Lương ca: chỉ chủ trung tâm / quản lý xem và đặt; giáo viên, giáo vụ có đứng lớp được tính lương ca
const seesPay = ref(false);
// Giáo viên chỉ có tên trong lịch học, chưa có tài khoản: vẫn đặt lương ca được
const unlinkedTeachers = ref([]);
const teaches = (role) => /Giáo viên|Giáo vụ/.test(role || '');
const formatMoney = (v) => new Intl.NumberFormat('vi-VN').format(Math.round(Number(v) || 0));

// Form invite / tạo tài khoản
const newInvite = ref({
  name: '',
  email: '',
  password: 'password',
  role: 'Giáo viên',
  center: 'Tất cả trung tâm',
  pay_group: null,
  pay_one_on_one: null,
});
const creatingInvite = ref(false);

// Permissions modal
const showPermissionModal = ref(false);
const selectedMember = ref(null);
// Đủ các trang trong menu, theo thứ tự menu (khớp backend PagePermissions)
const allPages = ALL_PAGES;

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
    const res = await staffApi.getStaff();
    members.value = res.data.members || [];
    centers.value = res.data.centers || [];
    if (res.data.owner_role) ownerRole.value = res.data.owner_role;
    if (res.data.roles) roles.value = res.data.roles;
    seesPay.value = !!res.data.sees_pay;
    unlinkedTeachers.value = res.data.unlinked_teachers || [];
  } catch (err) {
    console.error('Error fetching staff data:', err);
  } finally {
    loading.value = false;
  }
};

onMounted(fetchData);

// 1. Tạo tài khoản & Mời nhân sự
const handleCreateInvite = async () => {
  if (!newInvite.value.email.trim()) {
    showToast('Vui lòng nhập email nhân sự', 'error');
    return;
  }

  try {
    creatingInvite.value = true;
    const res = await staffApi.createInvite(newInvite.value);

    const createdMsg = res.data.message || 'Đã tạo tài khoản nhân sự thành công!';
    newInvite.value.name = '';
    newInvite.value.email = '';
    newInvite.value.password = 'password';
    newInvite.value.pay_group = null;
    newInvite.value.pay_one_on_one = null;
    showToast(createdMsg);
    fetchData();
  } catch (err) {
    showToast(err.response?.data?.message || 'Có lỗi xảy ra khi tạo tài khoản nhân sự', 'error');
  } finally {
    creatingInvite.value = false;
  }
};

// Sửa lương ca của giáo viên / giáo vụ
const payTarget = ref(null);
const payForm = ref({ pay_group: null, pay_one_on_one: null });
const savingPay = ref(false);

const openPay = (member) => {
  payTarget.value = member;
  payForm.value = { pay_group: member.pay?.group ?? 0, pay_one_on_one: member.pay?.one_on_one ?? 0 };
};

const handleSavePay = async () => {
  try {
    savingPay.value = true;
    const data = { pay_group: payForm.value.pay_group ?? 0, pay_one_on_one: payForm.value.pay_one_on_one ?? 0 };
    // Có id = thành viên có tài khoản; không có = giáo viên chỉ có tên trong lịch học
    const res = payTarget.value.id
      ? await staffApi.updatePay(payTarget.value.id, data)
      : await staffApi.updateTeacherPay({ name: payTarget.value.name, ...data });
    showToast(res.data.message || 'Đã lưu lương ca!');
    payTarget.value = null;
    fetchData();
  } catch (err) {
    showToast(err.response?.data?.message || 'Có lỗi xảy ra khi lưu lương ca', 'error');
  } finally {
    savingPay.value = false;
  }
};

// 2. Đổi vai trò
// Đặt lại mật khẩu khi nhân sự quên
const resetTarget = ref(null);
const resetPasswordValue = ref('');
const resettingPassword = ref(false);

const openResetPassword = (member) => {
  resetTarget.value = member;
  resetPasswordValue.value = '';
};

const handleResetPassword = async () => {
  if (resetPasswordValue.value.length < 6) {
    showToast('Mật khẩu phải có ít nhất 6 ký tự', 'error');
    return;
  }
  try {
    resettingPassword.value = true;
    const res = await staffApi.resetPassword(resetTarget.value.id, { password: resetPasswordValue.value });
    showToast(res.data.message || 'Đã đặt lại mật khẩu!');
    resetTarget.value = null;
  } catch (err) {
    showToast(err.response?.data?.message || 'Có lỗi xảy ra khi đặt lại mật khẩu', 'error');
  } finally {
    resettingPassword.value = false;
  }
};

// Đổi cơ sở làm việc
const handleCenterChange = async (member, newCenter) => {
  try {
    await staffApi.updateCenter(member.id, { center: newCenter });
    showToast(`Đã chuyển ${member.name || member.email} sang ${newCenter}`);
  } catch (err) {
    showToast(err.response?.data?.message || 'Có lỗi xảy ra khi đổi cơ sở', 'error');
  } finally {
    fetchData();
  }
};

const handleRoleChange = async (member, newRole) => {
  if (newRole === ownerRole.value && !(await confirmDialog({
    title: 'Cấp quyền chủ trung tâm?',
    message: `${member.name || member.email} sẽ có toàn quyền như chủ trung tâm: xem doanh thu, phân quyền nhân sự, sao lưu / khôi phục dữ liệu.`,
    confirmText: 'Cấp toàn quyền',
  }))) {
    fetchData(); // trả dropdown về vai trò cũ
    return;
  }
  try {
    await staffApi.updateRole(member.id, { role: newRole });
    showToast(`Đã đổi vai trò của ${member.name || member.email} thành ${newRole}`);
  } catch (err) {
    showToast(err.response?.data?.message || 'Có lỗi xảy ra khi đổi vai trò', 'error');
  } finally {
    // Đổi vai trò kéo theo đổi quyền mặc định, tải lại để cập nhật cột Quyền
    fetchData();
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
    showToast(err.response?.data?.message || 'Có lỗi xảy ra khi lưu phân quyền', 'error');
  }
};

// 4. Xóa thành viên
const handleDeleteMember = async (member) => {
  if (!(await confirmDialog({
    title: 'Xoá nhân sự khỏi trung tâm?',
    message: `${member.name || member.email} sẽ không còn quyền truy cập trung tâm.`,
    confirmText: 'Xoá nhân sự',
  }))) return;
  try {
    await staffApi.deleteMember(member.id);
    showToast('Đã xóa thành viên thành công!');
    fetchData();
  } catch (err) {
    showToast(err.response?.data?.message || 'Có lỗi xảy ra khi xóa thành viên', 'error');
  }
};
</script>

<template>
  <div class="space-y-6 pb-12">
    <!-- Header -->
    <div>
      <h1 class="text-xl sm:text-2xl font-bold tracking-tight text-slate-900">Nhân sự</h1>
    </div>


    <!-- 1. TẠO TÀI KHOẢN & MỜI NHÂN SỰ -->
    <div class="rounded-2xl border border-slate-200/90 bg-white p-5 shadow-2xs space-y-4">
      <div>
        <h2 class="text-sm sm:text-base font-bold text-slate-900">Tạo tài khoản & Phân quyền nhân sự</h2>
        <p class="text-xs text-slate-500 mt-0.5">Tạo tài khoản cho giáo viên, quản lý, lễ tân đăng nhập vào hệ thống và
          phân quyền truy cập theo vai trò.</p>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
        <!-- Họ tên -->
        <div>
          <label class="block text-xs font-semibold text-slate-700 mb-1.5">Họ và tên</label>
          <input v-model="newInvite.name" type="text" placeholder="Cô Mai Hương"
            class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs sm:text-sm text-slate-800 placeholder-slate-400 focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-500" />
        </div>

        <!-- Email -->
        <div>
          <label class="block text-xs font-semibold text-slate-700 mb-1.5">Email đăng nhập</label>
          <input v-model="newInvite.email" type="email" placeholder="giaovien@khoaanhmusic.com"
            class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs sm:text-sm text-slate-800 placeholder-slate-400 focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-500" />
        </div>

        <!-- Mật khẩu khởi tạo -->
        <div>
          <label class="block text-xs font-semibold text-slate-700 mb-1.5">Mật khẩu ban đầu</label>
          <input v-model="newInvite.password" type="text" placeholder="123456"
            class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs sm:text-sm text-slate-800 placeholder-slate-400 focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-500 font-mono" />
        </div>

        <!-- Vai trò -->
        <div>
          <label class="block text-xs font-semibold text-slate-700 mb-1.5">Vai trò</label>
          <select v-model="newInvite.role"
            class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-500">
            <option v-for="r in roleOptions" :key="r" :value="r">{{ r }}</option>
          </select>
        </div>

        <!-- Trung tâm -->
        <div>
          <label class="block text-xs font-semibold text-slate-700 mb-1.5">Cơ sở làm việc</label>
          <select v-model="newInvite.center"
            class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-500">
            <option v-for="c in centers" :key="c" :value="c">{{ c }}</option>
          </select>
        </div>
      </div>

      <!-- Lương mỗi ca của giáo viên / giáo vụ, đặt ngay khi tạo (sửa lại ở cột Lương ca bên dưới) -->
      <div v-if="seesPay && teaches(newInvite.role)" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
        <div>
          <label class="block text-xs font-semibold text-slate-700 mb-1.5">Lương ca nhóm (VNĐ / ca)</label>
          <MoneyInput v-model="newInvite.pay_group" placeholder="VD: 200.000"
            class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-500" />
        </div>
        <div>
          <label class="block text-xs font-semibold text-slate-700 mb-1.5">Lương ca 1:1 (VNĐ / ca)</label>
          <MoneyInput v-model="newInvite.pay_one_on_one" placeholder="VD: 150.000"
            class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-500" />
        </div>
        <p class="sm:col-span-2 lg:col-span-3 self-end text-[11px] text-slate-500">
          Mỗi ca check-in được cộng tiền theo mức này. Ca nhóm có số HV đi học ≤ một nửa sĩ số lớp nhóm (đặt ở màn
          Trung tâm, mặc định 4) nhận 50%. Ca 1:1 học viên vắng không phép nhận 50%, vắng có phép không tính lương.
        </p>
      </div>

      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pt-1">
        <button type="button" :disabled="creatingInvite" @click="handleCreateInvite"
          class="rounded-xl bg-rose-600 hover:bg-rose-700 disabled:opacity-60 px-5 py-2.5 text-xs sm:text-sm font-bold text-white shadow-xs transition-all active:scale-[0.98] inline-flex items-center gap-2 justify-center">
          <span>{{ creatingInvite ? 'Đang tạo...' : '+ Tạo tài khoản & Cấp quyền' }}</span>
        </button>
        <p class="text-[11px] sm:text-xs text-slate-400">
          * Tài khoản sẽ được kích hoạt ngay. Nhân sự có thể dùng Email và Mật khẩu vừa cấp để đăng nhập vào hệ thống.
        </p>
      </div>
    </div>

    <!-- 2. THÀNH VIÊN -->
    <div class="rounded-2xl border border-slate-200/90 bg-white p-5 shadow-2xs space-y-4">
      <div>
        <h2 class="text-sm sm:text-base font-bold text-slate-900">Thành viên</h2>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse text-xs sm:text-sm">
          <thead>
            <tr class="border-b border-slate-100 text-[11px] sm:text-xs font-semibold text-slate-500">
              <th class="py-2.5 px-3">Email</th>
              <th class="py-2.5 px-3">Vai trò</th>
              <th class="py-2.5 px-3">Quyền</th>
              <th class="py-2.5 px-3">Trung tâm</th>
              <th v-if="seesPay" class="py-2.5 px-3">Lương ca</th>
              <th class="py-2.5 px-3">Tham gia</th>
              <th class="py-2.5 px-3 text-right w-12"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="m in members" :key="m.id" class="hover:bg-slate-50/60 transition-colors">
              <!-- Email -->
              <td class="py-3.5 px-3">
                <div class="font-medium text-slate-900">{{ m.email }}</div>
                <div v-if="m.name" class="text-xs text-slate-400">{{ m.name }}</div>
              </td>

              <!-- Vai trò -->
              <td class="py-3.5 px-3">
                <span v-if="!canEditMember(m)" class="text-slate-800 font-semibold">
                  {{ m.is_owner ? ownerRole : m.role }}
                </span>
                <select v-else :value="m.is_owner ? ownerRole : m.role"
                  @change="handleRoleChange(m, $event.target.value)"
                  class="rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs text-slate-800 focus:border-rose-500 focus:outline-none">
                  <option v-for="r in roleOptions" :key="r" :value="r">{{ r }}</option>
                </select>
              </td>

              <!-- Quyền -->
              <td class="py-3.5 px-3">
                <span v-if="m.is_owner" class="text-slate-700 font-medium">
                  Toàn quyền
                </span>
                <span v-else-if="!isOwner" class="text-slate-600 text-xs">
                  Chặn {{ (m.blocked_pages || []).length }} trang
                </span>
                <button v-else type="button" @click="openPermissions(m)"
                  class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 px-2.5 py-1 text-xs font-medium text-slate-700 shadow-2xs transition-all active:scale-[0.98]">
                  <Shield class="h-3 w-3 text-slate-500" />
                  <span>Chặn {{ (m.blocked_pages || []).length }} trang</span>
                </button>
              </td>

              <!-- Trung tâm -->
              <td class="py-3.5 px-3 text-slate-600">
                <select v-if="isOwner && !m.is_owner" :value="m.center === 'Tất cả' ? 'Tất cả trung tâm' : m.center"
                  @change="handleCenterChange(m, $event.target.value)"
                  class="rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs text-slate-800 focus:border-rose-500 focus:outline-none">
                  <option v-for="c in centers" :key="c" :value="c">{{ c }}</option>
                </select>
                <div v-else class="flex items-center gap-1.5">
                  <Building2 v-if="!m.is_owner" class="h-3.5 w-3.5 text-slate-400 shrink-0" />
                  <span>{{ m.center }}</span>
                </div>
              </td>

              <!-- Lương ca (giáo viên / giáo vụ) -->
              <td v-if="seesPay" class="py-3.5 px-3">
                <button v-if="m.pay" type="button" @click="openPay(m)" :disabled="!m.name"
                  :title="m.name ? 'Sửa lương ca' : 'Cần có họ tên để đặt lương'"
                  class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 disabled:opacity-50 px-2.5 py-1 text-xs text-slate-700 shadow-2xs whitespace-nowrap">
                  <Wallet class="h-3 w-3 text-slate-500" />
                  <span>Nhóm {{ formatMoney(m.pay.group) }} · 1:1 {{ formatMoney(m.pay.one_on_one) }}</span>
                </button>
                <span v-else class="text-slate-300">—</span>
              </td>

              <!-- Tham gia -->
              <td class="py-3.5 px-3 text-slate-500 font-mono text-xs">
                {{ m.joined_at }}
              </td>

              <!-- Đặt lại mật khẩu / Xóa -->
              <td class="py-3.5 px-3 text-right whitespace-nowrap">
                <button v-if="canEditMember(m)" type="button" @click="openResetPassword(m)"
                  class="p-1 rounded-lg text-slate-400 hover:text-amber-600 hover:bg-amber-50 transition-colors"
                  title="Đặt lại mật khẩu">
                  <KeyRound class="h-4 w-4" />
                </button>
                <button v-if="canEditMember(m)" type="button" @click="handleDeleteMember(m)"
                  class="p-1 rounded-lg text-rose-500 hover:text-rose-700 hover:bg-rose-50 transition-colors"
                  title="Xóa thành viên">
                  <Trash2 class="h-4 w-4" />
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- 3. GIÁO VIÊN CHƯA CÓ TÀI KHOẢN: tên gõ trong lịch học, vẫn cần đặt lương ca -->
    <div v-if="seesPay && unlinkedTeachers.length"
      class="rounded-2xl border border-slate-200/90 bg-white p-5 shadow-2xs space-y-3">
      <div>
        <h2 class="text-sm sm:text-base font-bold text-slate-900">Giáo viên chưa có tài khoản</h2>
        <p class="text-xs text-slate-500 mt-0.5">Tên giáo viên có trong lịch học của học viên nhưng chưa tạo tài khoản ở trên.
          Vẫn đặt lương ca được; khi tạo tài khoản đúng họ tên này thì lương giữ nguyên.</p>
      </div>
      <div class="divide-y divide-slate-100">
        <div v-for="t in unlinkedTeachers" :key="t.name" class="flex items-center justify-between gap-3 py-2.5 text-xs sm:text-sm">
          <span class="font-medium text-slate-900">{{ t.name }}</span>
          <button type="button" @click="openPay(t)"
            class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 px-2.5 py-1 text-xs text-slate-700 shadow-2xs whitespace-nowrap">
            <Wallet class="h-3 w-3 text-slate-500" />
            <span>Nhóm {{ formatMoney(t.pay.group) }} · 1:1 {{ formatMoney(t.pay.one_on_one) }}</span>
          </button>
        </div>
      </div>
    </div>

    <!-- MODAL LƯƠNG CA -->
    <div v-if="payTarget"
      class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
      <form @submit.prevent="handleSavePay"
        class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl overflow-hidden border border-slate-200">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100 bg-slate-50/80">
          <div class="flex items-center gap-2 text-slate-900 font-bold text-sm">
            <Wallet class="h-4.5 w-4.5 text-rose-600" />
            <span>Lương ca · {{ payTarget.name }}</span>
          </div>
          <button type="button" @click="payTarget = null"
            class="rounded-xl p-1.5 text-slate-400 hover:text-slate-600 hover:bg-slate-100">
            <X class="h-5 w-5" />
          </button>
        </div>

        <div class="p-6 space-y-3">
          <div class="grid grid-cols-2 gap-3">
            <label class="text-xs font-semibold text-slate-700">
              Ca nhóm (VNĐ / ca)
              <MoneyInput v-model="payForm.pay_group" class="mt-1.5 w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-500" />
            </label>
            <label class="text-xs font-semibold text-slate-700">
              Ca 1:1 (VNĐ / ca)
              <MoneyInput v-model="payForm.pay_one_on_one" class="mt-1.5 w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-500" />
            </label>
          </div>
          <p class="text-[11px] text-slate-500">
            Áp dụng cho các ca check-in từ bây giờ, ca đã check-in giữ mức cũ. Ca nhóm có số HV đi học ≤ một nửa sĩ số
            lớp nhóm (màn Trung tâm) nhận 50%; ca 1:1 học viên vắng không phép nhận 50%, vắng có phép không tính lương.
          </p>
        </div>

        <div class="px-6 py-3 border-t border-slate-100 bg-slate-50 flex items-center justify-end gap-2">
          <button type="button" @click="payTarget = null"
            class="rounded-xl border border-slate-200 bg-white hover:bg-slate-50 px-4 py-2 text-xs font-semibold text-slate-700">
            Hủy
          </button>
          <button type="submit" :disabled="savingPay"
            class="rounded-xl bg-rose-600 hover:bg-rose-700 disabled:opacity-50 px-4 py-2 text-xs font-bold text-white shadow-xs">
            {{ savingPay ? 'Đang lưu...' : 'Lưu lương ca' }}
          </button>
        </div>
      </form>
    </div>

    <!-- MODAL ĐẶT LẠI MẬT KHẨU -->
    <div v-if="resetTarget"
      class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
      <form @submit.prevent="handleResetPassword"
        class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl overflow-hidden border border-slate-200">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100 bg-slate-50/80">
          <div class="flex items-center gap-2 text-slate-900 font-bold text-sm">
            <KeyRound class="h-4.5 w-4.5 text-amber-600" />
            <span>Đặt lại mật khẩu</span>
          </div>
          <button type="button" @click="resetTarget = null"
            class="rounded-xl p-1.5 text-slate-400 hover:text-slate-600 hover:bg-slate-100">
            <X class="h-5 w-5" />
          </button>
        </div>

        <div class="p-6 space-y-3">
          <p class="text-xs text-slate-500">
            Đặt mật khẩu mới cho <strong class="text-slate-800">{{ resetTarget.email }}</strong>.
            Nhân sự sẽ bị đăng xuất khỏi mọi thiết bị và đăng nhập lại bằng mật khẩu này.
          </p>
          <input v-model="resetPasswordValue" type="text" minlength="6" required autocomplete="off"
            placeholder="Mật khẩu mới (ít nhất 6 ký tự)"
            class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-sm text-slate-800 font-mono focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-500" />
        </div>

        <div class="px-6 py-3 border-t border-slate-100 bg-slate-50 flex items-center justify-end gap-2">
          <button type="button" @click="resetTarget = null"
            class="rounded-xl border border-slate-200 bg-white hover:bg-slate-50 px-4 py-2 text-xs font-semibold text-slate-700">
            Hủy
          </button>
          <button type="submit" :disabled="resettingPassword"
            class="rounded-xl bg-rose-600 hover:bg-rose-700 disabled:opacity-50 px-4 py-2 text-xs font-bold text-white shadow-xs">
            {{ resettingPassword ? 'Đang lưu...' : 'Đặt lại mật khẩu' }}
          </button>
        </div>
      </form>
    </div>

    <!-- MODAL PHÂN QUYỀN TRANG -->
    <div v-if="showPermissionModal"
      class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
      <div class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl overflow-hidden border border-slate-200">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100 bg-slate-50/80">
          <div class="flex items-center gap-2 text-slate-900 font-bold text-sm">
            <Lock class="h-4.5 w-4.5 text-rose-600" />
            <span>Phân Quyền Truy Cập (Chặn trang)</span>
          </div>
          <button type="button" @click="showPermissionModal = false"
            class="rounded-xl p-1.5 text-slate-400 hover:text-slate-600 hover:bg-slate-100">
            <X class="h-5 w-5" />
          </button>
        </div>

        <div class="p-6 space-y-4">
          <p class="text-xs text-slate-500">
            Tích chọn các trang mà bạn muốn <strong class="text-rose-600">CHẶN</strong> nhân sự này truy cập:
          </p>

          <div class="grid grid-cols-2 gap-2.5 max-h-72 overflow-y-auto pr-1">
            <label v-for="p in allPages" :key="p"
              class="flex items-center gap-2 p-2 rounded-xl border border-slate-200 hover:bg-slate-50 cursor-pointer text-xs font-medium text-slate-700">
              <input type="checkbox" :checked="selectedMember?.blocked_pages?.includes(p)" @change="togglePageBlock(p)"
                class="rounded border-slate-300 text-rose-600 focus:ring-rose-500" />
              <span>{{ p }}</span>
            </label>
          </div>
        </div>

        <div class="px-6 py-3 border-t border-slate-100 bg-slate-50 flex items-center justify-end gap-2">
          <button type="button" @click="showPermissionModal = false"
            class="rounded-xl border border-slate-200 bg-white hover:bg-slate-50 px-4 py-2 text-xs font-semibold text-slate-700">
            Hủy
          </button>
          <button type="button" @click="handleSavePermissions"
            class="rounded-xl bg-rose-600 hover:bg-rose-700 px-4 py-2 text-xs font-bold text-white shadow-xs">
            Lưu phân quyền
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
