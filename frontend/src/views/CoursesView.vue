<script setup>
import { confirmDialog } from '../services/useConfirm';
import { notify } from '../services/notify';
import { ref, computed, onMounted, watch } from 'vue';
import { courseApi } from '../services/api';
import Badge from '../components/Badge.vue';
import Modal from '../components/Modal.vue';
import {
  Plus,
  Users,
  Edit3,
  Trash2,
  X,
  Search,
  BookOpen,
  CheckCircle2,
  AlertCircle,
  Layers,
  GraduationCap,
} from 'lucide-vue-next';
import MoneyInput from '../components/MoneyInput.vue';

const courses = ref([]);
const loading = ref(true);
const searchQuery = ref('');

const showModal = ref(false);
const isEditing = ref(false);
const editingId = ref(null);
const isSubmitting = ref(false);

const form = ref({
  name: '',
  default_sessions: 12,
  allowed_absences: '',
  class_type: '',
  price: 3600000,
  description: '',
});

// Hình thức học: quyết định ca dạy là ca nhóm hay ca 1:1 (lương giáo viên)
const CLASS_TYPES = { group: 'Lớp nhóm', individual: 'Cá nhân 1:1' };
// Đoán theo tên khóa như backend: "NHÓM" → nhóm; "CÁ NHÂN", "1:1", "KÈM RIÊNG" → cá nhân
const guessClassType = (name) => {
  const n = String(name || '').toUpperCase();
  if (n.includes('NHÓM')) return 'group';
  if (n.includes('CÁ NHÂN') || /\b1\s*[:-]\s*1\b/.test(n) || n.includes('KÈM RIÊNG')) return 'individual';
  return '';
};
// Khóa mới: tự chọn hình thức theo tên vừa gõ cho tới khi người dùng tự chọn
const classTypeTouched = ref(false);
watch(() => form.value.name, (name) => {
  if (!isEditing.value && !classTypeTouched.value) form.value.class_type = guessClassType(name);
});

const fetchData = async () => {
  try {
    loading.value = true;
    const res = await courseApi.getAll();
    courses.value = res.data.courses || [];
  } catch (err) {
    console.error('Error fetching courses: ', err);
    notify.apiError(err, 'Không thể tải danh sách khóa học');
  } finally {
    loading.value = false;
  }
};

onMounted(fetchData);

// Thống kê nhanh
const stats = computed(() => {
  const total = courses.value.length;
  const classes = courses.value.reduce((acc, c) => acc + (c.classes?.length || 0), 0);
  const totalStudents = courses.value.reduce((acc, c) => acc + (c.active_students_count || c.students_count || 0), 0);
  return { total, classes, totalStudents };
});

// Lọc khóa học
const filteredCourses = computed(() => {
  return courses.value.filter(c => {
    // Tìm kiếm theo tên / mô tả / tên lớp
    if (searchQuery.value.trim()) {
      const q = searchQuery.value.toLowerCase().trim();
      const haystack = [c.name, c.description, ...(c.classes || []).map((k) => k.name)].join(' ').toLowerCase();
      if (!haystack.includes(q)) return false;
    }

    return true;
  });
});

const openCreate = () => {
  isEditing.value = false;
  editingId.value = null;
  form.value = {
    name: '',
    default_sessions: 12,
    allowed_absences: '',
    class_type: '',
    price: 3600000,
    description: '',
  };
  classTypeTouched.value = false;
  showModal.value = true;
};

const openEdit = (c) => {
  isEditing.value = true;
  editingId.value = c.id;
  form.value = {
    name: c.name,
    default_sessions: c.default_sessions,
    allowed_absences: c.allowed_absences ?? '',
    class_type: c.class_type || '',
    price: Number(c.price),
    description: c.description || '',
  };
  showModal.value = true;
};

const submit = async () => {
  try {
    isSubmitting.value = true;
    if (isEditing.value) {
      await courseApi.update(editingId.value, form.value);
      notify.success('Cập nhật khóa học thành công!');
    } else {
      await courseApi.create(form.value);
      notify.success('Thêm khóa học mới thành công!');
    }
    showModal.value = false;
    fetchData();
  } catch (err) {
    notify.apiError(err, 'Có lỗi xảy ra khi lưu khóa học');
  } finally {
    isSubmitting.value = false;
  }
};

const deleteCourse = async (c) => {
  if (await confirmDialog({
    title: 'Xoá khoá học?',
    message: `Khoá học "${c.name}" sẽ bị xoá khỏi danh mục. Bạn có chắc chắn muốn xóa?`,
    confirmText: 'Xoá khoá học',
  })) {
    try {
      await courseApi.delete(c.id);
      notify.success(`Đã xóa khóa học "${c.name}" thành công!`);
      fetchData();
    } catch (err) {
      notify.apiError(err, 'Không thể xóa khóa học');
    }
  }
};

const formatVND = (val) => {
  return new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(val || 0);
};

// ---- Lớp học trong khóa ----
// Mỗi lớp là một thẻ sửa trực tiếp: tên, phòng, các khung giờ (thứ + giờ + giáo viên), sĩ số. Đổi gì lưu ngay.
const DAYS = [
  { value: 1, label: 'Thứ 2' }, { value: 2, label: 'Thứ 3' }, { value: 3, label: 'Thứ 4' }, { value: 4, label: 'Thứ 5' },
  { value: 5, label: 'Thứ 6' }, { value: 6, label: 'Thứ 7' }, { value: 0, label: 'CN' },
];
const dayLabel = (d) => DAYS.find((x) => x.value === Number(d))?.label || '';

const classCourse = ref(null);
const classRooms = ref([]);
const classTeachers = ref([]);
const newClass = ref({ name: '', roomKey: '' });
const classCards = ref([]); // bản sửa của từng lớp
const savingClass = ref(false);

const roomKey = (center, room) => (room ? `${center || ''}|${room}` : '');
const emptySlot = () => ({ day: '', start: '', end: '', teacher: '' });

const toCard = (k) => ({
  id: k.id,
  name: k.name,
  roomKey: roomKey(k.center, k.room),
  capacity: k.capacity,
  students_count: k.students_count || 0,
  schedules: (k.schedules || []).map((x) => ({ day: x.day, start: x.start, end: x.end || '', teacher: x.teacher || '' })),
  draft: emptySlot(),
});

const loadCards = () => {
  classCards.value = (classCourse.value?.classes || []).map(toCard);
};

const openClasses = async (c) => {
  classCourse.value = c;
  newClass.value = { name: '', roomKey: '' };
  loadCards();
  try {
    const res = await courseApi.classRooms();
    classRooms.value = res.data.rooms || [];
    classTeachers.value = res.data.teachers || [];
  } catch {
    classRooms.value = [];
    classTeachers.value = [];
  }
};

const refreshClassCourse = async () => {
  await fetchData();
  classCourse.value = courses.value.find((c) => c.id === classCourse.value?.id) || null;
  loadCards();
};

const splitRoom = (key) => {
  const [center, room] = key ? key.split('|') : ['', ''];
  return { center: center || null, room: room || null };
};

const addClass = async () => {
  if (!newClass.value.name.trim()) {
    notify.warning('Vui lòng nhập tên lớp học');
    return;
  }
  try {
    savingClass.value = true;
    const res = await courseApi.createClass(classCourse.value.id, { name: newClass.value.name.trim(), ...splitRoom(newClass.value.roomKey) });
    notify.success(res.data.message);
    newClass.value = { name: '', roomKey: '' };
    await refreshClassCourse();
  } catch (err) {
    notify.apiError(err, 'Không tạo được lớp học');
  } finally {
    savingClass.value = false;
  }
};

// Lưu toàn bộ thẻ lớp (tên, phòng, sĩ số, khung giờ)
const saveCard = async (card) => {
  if (!card.name.trim()) {
    notify.warning('Tên lớp không được để trống');
    return;
  }
  try {
    const res = await courseApi.updateClass(card.id, {
      name: card.name.trim(),
      ...splitRoom(card.roomKey),
      capacity: card.capacity || null,
      schedules: card.schedules.map((x) => ({ day: Number(x.day), start: x.start, end: x.end || null, teacher: x.teacher || null })),
    });
    notify.success(res.data.message);
    await refreshClassCourse();
  } catch (err) {
    notify.apiError(err, 'Không lưu được lớp học');
    await refreshClassCourse();
  }
};

// Chọn phòng thì gợi ý sĩ số = sức chứa phòng nếu chưa đặt
const onCardRoomChange = (card) => {
  const r = classRooms.value.find((x) => roomKey(x.center, x.room) === card.roomKey);
  if (r && !card.capacity) card.capacity = r.capacity || null;
  saveCard(card);
};

const addSlot = (card) => {
  const d = card.draft;
  if (d.day === '' || !d.start) {
    notify.warning('Chọn thứ và giờ bắt đầu cho khung giờ');
    return;
  }
  card.schedules.push({ ...d });
  card.draft = { ...emptySlot(), teacher: d.teacher };
  saveCard(card);
};

const removeSlot = (card, i) => {
  card.schedules.splice(i, 1);
  saveCard(card);
};

const removeClass = async (card) => {
  if (!(await confirmDialog({
    title: 'Xoá lớp học?',
    message: `Lớp "${card.name}" sẽ bị xoá. ${card.students_count} học viên trong lớp sẽ chuyển về "chưa gán lớp".`,
    confirmText: 'Xoá lớp',
  }))) return;
  try {
    await courseApi.deleteClass(card.id);
    notify.success('Đã xoá lớp học');
    await refreshClassCourse();
  } catch (err) {
    notify.apiError(err, 'Không xoá được lớp học');
  }
};
</script>

<template>
  <div class="space-y-5">
    <!-- Header: Tiêu đề & Nút Thêm mới -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
      <div>
        <div class="flex items-center gap-2.5">
          <h1 class="text-xl sm:text-2xl font-black tracking-tight text-slate-900">
            Khóa học
          </h1>
          <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-slate-100 text-slate-700">
            {{ courses.length }}
          </span>
        </div>
        <p class="text-xs text-slate-500 mt-1">
          Quản lý danh sách chương trình đào tạo, số buổi tiêu chuẩn và định mức học phí.
        </p>
      </div>

      <button
        type="button"
        @click="openCreate"
        class="inline-flex items-center gap-2 rounded-xl bg-rose-600 hover:bg-rose-700 px-4 py-2.5 text-xs sm:text-sm font-bold text-white shadow-xs shadow-rose-600/20 transition-all active:scale-[0.98] shrink-0"
      >
        <Plus class="h-4 w-4" />
        <span>+ Thêm Khóa Học</span>
      </button>
    </div>

    <!-- Quick Stats Cards Strip -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
      <div class="flex items-center gap-3.5 p-3.5 rounded-2xl bg-white border border-slate-200/80 shadow-2xs">
        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600">
          <BookOpen class="h-5 w-5" />
        </div>
        <div>
          <p class="text-[11px] font-semibold text-slate-500">Tổng số khóa học</p>
          <p class="text-lg font-black text-slate-900">{{ stats.total }} <span class="text-xs font-normal text-slate-400">chương trình</span></p>
        </div>
      </div>

      <div class="flex items-center gap-3.5 p-3.5 rounded-2xl bg-white border border-slate-200/80 shadow-2xs">
        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600">
          <Layers class="h-5 w-5" />
        </div>
        <div>
          <p class="text-[11px] font-semibold text-slate-500">Lớp học</p>
          <p class="text-lg font-black text-emerald-600">{{ stats.classes }} <span class="text-xs font-normal text-slate-400">lớp</span></p>
        </div>
      </div>

      <div class="flex items-center gap-3.5 p-3.5 rounded-2xl bg-white border border-slate-200/80 shadow-2xs">
        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-rose-50 text-rose-600">
          <GraduationCap class="h-5 w-5" />
        </div>
        <div>
          <p class="text-[11px] font-semibold text-slate-500">Học viên đang theo học</p>
          <p class="text-lg font-black text-rose-600">{{ stats.totalStudents }} <span class="text-xs font-normal text-slate-400">học viên</span></p>
        </div>
      </div>
    </div>

    <!-- Card: BẢNG DANH SÁCH KHÓA HỌC -->
    <div class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5 shadow-2xs space-y-4">
      <!-- Bộ lọc và tìm kiếm -->
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <!-- Tìm kiếm -->
        <div class="relative">
          <Search class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-400" />
          <input
            v-model="searchQuery"
            type="text"
            placeholder="Tìm theo tên khóa học, tên lớp..."
            class="w-full rounded-xl border border-slate-200 bg-slate-50/50 pl-9 pr-8 py-2 text-xs sm:text-sm text-slate-800 placeholder-slate-400 focus:border-indigo-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20 transition-all"
          />
          <button
            v-if="searchQuery"
            @click="searchQuery = ''"
            type="button"
            class="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600"
          >
            <X class="h-4 w-4" />
          </button>
        </div>

      </div>

      <!-- Bảng danh sách khóa học -->
      <div class="overflow-x-auto rounded-xl border border-slate-100">
        <table class="w-full text-left border-collapse text-xs sm:text-sm">
          <thead>
            <tr class="border-b border-slate-100 bg-slate-50/80 text-[11px] sm:text-xs font-semibold text-slate-500 uppercase tracking-wider">
              <th class="py-3 px-3.5">Khóa học</th>
              <th class="py-3 px-3.5 text-center">Số buổi</th>
              <th class="py-3 px-3.5 text-right">Học phí</th>
              <th class="py-3 px-3.5 text-right">Đơn giá / buổi</th>
              <th class="py-3 px-3.5 text-center">Học viên</th>
              <th class="py-3 px-3.5 text-center">Lớp học</th>
              <th class="py-3 px-3.5 text-right">Thao tác</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr
              v-for="c in filteredCourses"
              :key="c.id"
              class="hover:bg-slate-50/70 transition-colors"
            >
              <!-- Tên khóa học & Mô tả -->
              <td class="py-3.5 px-3.5 max-w-xs">
                <div class="font-bold text-slate-900 hover:text-indigo-600 transition-colors">
                  {{ c.name }}
                </div>
                <p v-if="c.description" class="text-[11px] text-slate-400 line-clamp-1 mt-0.5">
                  {{ c.description }}
                </p>
              </td>

              <!-- Số buổi -->
              <td class="py-3.5 px-3.5 text-center whitespace-nowrap font-medium text-slate-700">
                <span class="inline-block px-2 py-0.5 rounded-md bg-slate-100 font-bold text-xs">
                  {{ c.default_sessions }} buổi
                </span>
                <p class="text-[11px] text-slate-400 mt-0.5">Nghỉ phép: {{ c.allowed_absences ?? 3 }} buổi</p>
                <p class="text-[11px] mt-0.5" :class="c.class_type ? 'font-semibold text-indigo-600' : 'text-amber-600'">
                  {{ CLASS_TYPES[c.class_type] || 'Chưa chọn hình thức' }}
                </p>
              </td>

              <!-- Học phí -->
              <td class="py-3.5 px-3.5 text-right whitespace-nowrap font-bold text-emerald-600">
                {{ formatVND(c.price) }}
              </td>

              <!-- Đơn giá / buổi -->
              <td class="py-3.5 px-3.5 text-right whitespace-nowrap text-xs text-slate-500 font-medium">
                {{ c.default_sessions > 0 ? formatVND(Math.round(c.price / c.default_sessions)) : '—' }}
              </td>

              <!-- Học viên đang học -->
              <td class="py-3.5 px-3.5 text-center whitespace-nowrap">
                <router-link
                  :to="`/students?course_id=${c.id}`"
                  class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-indigo-50 text-indigo-700 hover:bg-indigo-100 transition-colors"
                  title="Xem danh sách học viên theo khóa này"
                >
                  <Users class="h-3 w-3" />
                  <span>{{ c.active_students_count ?? c.students_count ?? 0 }}</span>
                </router-link>
              </td>

              <!-- Lớp học -->
              <td class="py-3.5 px-3.5 text-center whitespace-nowrap">
                <button
                  type="button"
                  @click="openClasses(c)"
                  class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 hover:bg-emerald-100 transition-colors"
                  title="Quản lý lớp học của khóa này"
                >
                  <Layers class="h-3 w-3" />
                  <span>{{ c.classes?.length || 0 }} lớp</span>
                </button>
              </td>

              <!-- Thao tác -->
              <td class="py-3.5 px-3.5 text-right whitespace-nowrap">
                <div class="inline-flex items-center gap-1 justify-end">
                  <button
                    type="button"
                    @click="openEdit(c)"
                    class="rounded-lg p-1.5 text-slate-400 hover:bg-indigo-50 hover:text-indigo-600 transition-colors"
                    title="Chỉnh sửa khóa học"
                  >
                    <Edit3 class="h-4 w-4" />
                  </button>
                  <button
                    type="button"
                    @click="deleteCourse(c)"
                    class="rounded-lg p-1.5 text-slate-400 hover:bg-rose-50 hover:text-rose-600 transition-colors"
                    title="Xóa khóa học"
                  >
                    <Trash2 class="h-4 w-4" />
                  </button>
                </div>
              </td>
            </tr>

            <!-- Empty state -->
            <tr v-if="filteredCourses.length === 0">
              <td colspan="7" class="py-12 text-center">
                <div class="flex flex-col items-center justify-center text-slate-400">
                  <BookOpen class="h-10 w-10 text-slate-300 stroke-1 mb-2" />
                  <p class="text-sm font-semibold text-slate-600">Không tìm thấy khóa học phù hợp</p>
                  <p class="text-xs text-slate-400 mt-1">Thử thay đổi từ khóa tìm kiếm hoặc bấm Thêm Khóa Học mới</p>
                  <button
                    type="button"
                    @click="openCreate"
                    class="mt-3 inline-flex items-center gap-1.5 rounded-xl bg-indigo-50 px-3.5 py-1.5 text-xs font-bold text-indigo-600 hover:bg-indigo-100 transition-colors"
                  >
                    <Plus class="h-3.5 w-3.5" />
                    <span>Thêm Khóa Học Mới</span>
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Modal Tạo / Sửa Khóa Học -->
    <Modal :show="showModal" max-width="md" @close="showModal = false">
      <div class="p-6">
        <div class="flex items-center justify-between border-b border-slate-100 pb-4">
          <div class="flex items-center gap-2">
            <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-indigo-50 text-indigo-600">
              <BookOpen class="h-4 w-4" />
            </div>
            <h3 class="text-base font-bold text-slate-900">
              {{ isEditing ? 'Chỉnh Sửa Khóa Học' : 'Thêm Khóa Học Mới' }}
            </h3>
          </div>
          <button @click="showModal = false" class="text-slate-400 hover:text-slate-600 p-1 rounded-lg">
            <X class="h-5 w-5" />
          </button>
        </div>

        <form @submit.prevent="submit" class="mt-4 space-y-4">
          <!-- Tên khóa học -->
          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1">
              Tên khóa học <span class="text-rose-500">*</span>
            </label>
            <input
              type="text"
              v-model="form.name"
              required
              placeholder="VD: Piano Căn Bản & Cổ Điển"
              class="w-full rounded-xl border border-slate-200 px-3.5 py-2 text-xs sm:text-sm text-slate-800 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20"
            />
          </div>

          <div class="grid grid-cols-2 gap-3">
            <!-- Số buổi tiêu chuẩn -->
            <div>
              <label class="block text-xs font-semibold text-slate-700 mb-1">
                Số buổi / khóa <span class="text-rose-500">*</span>
              </label>
              <input
                type="number"
                min="1"
                v-model.number="form.default_sessions"
                required
                class="w-full rounded-xl border border-slate-200 px-3.5 py-2 text-xs sm:text-sm font-bold text-slate-800 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20"
              />
            </div>

            <!-- Học phí tiêu chuẩn -->
            <div>
              <label class="block text-xs font-semibold text-slate-700 mb-1">
                Học phí (VNĐ) <span class="text-rose-500">*</span>
              </label>
              <MoneyInput
                v-model="form.price"
                required
                class="w-full rounded-xl border border-slate-200 px-3.5 py-2 text-xs sm:text-sm font-bold text-emerald-700 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20"
              />
            </div>
          </div>

          <!-- Hình thức học: ca nhóm / ca 1:1 khi tính lương giáo viên -->
          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1">Hình thức học</label>
            <select v-model="form.class_type" @change="classTypeTouched = true"
              class="w-full rounded-xl border border-slate-200 px-3.5 py-2 text-xs sm:text-sm font-bold text-slate-800 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20">
              <option value="group">Lớp nhóm</option>
              <option value="individual">Cá nhân 1:1</option>
              <option value="">Chưa chọn (1 học viên trong ca = 1:1)</option>
            </select>
            <p class="text-[11px] text-slate-400 mt-1">
              Lớp nhóm: ca dạy luôn tính lương ca nhóm (đi học ≤ một nửa sĩ số nhận 50%). Cá nhân: mỗi học viên một ca
              1:1 riêng, tính lương ca 1:1.
            </p>
          </div>

          <!-- Số buổi được nghỉ có phép -->
          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1">
              Số buổi được nghỉ có phép
            </label>
            <input
              type="number"
              min="0"
              v-model="form.allowed_absences"
              placeholder="Mặc định 3 buổi"
              class="w-full rounded-xl border border-slate-200 px-3.5 py-2 text-xs sm:text-sm font-bold text-slate-800 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20"
            />
            <p class="text-[11px] text-slate-400 mt-1">
              VD: khóa 1 tháng = 1 buổi, 3 tháng = 3 buổi, 6 tháng = 6 buổi. Nghỉ có phép trong số buổi này không bị trừ
              buổi; vượt quá thì trừ như vắng không phép. Áp dụng cho học viên đăng ký khóa từ nay.
            </p>
          </div>

          <!-- Mô tả -->
          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1">
              Mô tả khóa học
            </label>
            <textarea
              v-model="form.description"
              rows="3"
              placeholder="VD: Khóa học dành cho trẻ em từ 6-12 tuổi..."
              class="w-full rounded-xl border border-slate-200 p-3 text-xs sm:text-sm text-slate-800 placeholder-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20"
            ></textarea>
          </div>

          <div class="flex items-center justify-end gap-2 border-t border-slate-100 pt-4">
            <button
              type="button"
              @click="showModal = false"
              class="rounded-xl border border-slate-200 px-4 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-50 transition-colors"
            >
              Hủy
            </button>
            <button
              type="submit"
              :disabled="isSubmitting"
              class="rounded-xl bg-indigo-600 hover:bg-indigo-700 px-5 py-2 text-xs font-bold text-white shadow-xs transition-all disabled:opacity-50"
            >
              {{ isSubmitting ? 'Đang lưu...' : (isEditing ? 'Cập nhật' : 'Lưu Khóa Học') }}
            </button>
          </div>
        </form>
      </div>
    </Modal>

    <!-- Modal Lớp học của khóa -->
    <Modal :show="!!classCourse" max-width="3xl" @close="classCourse = null">
      <div v-if="classCourse" class="p-6 space-y-4">
        <div class="flex items-start justify-between gap-3">
          <h3 class="text-lg font-bold text-slate-900">Lớp học · {{ classCourse.name }}</h3>
          <button @click="classCourse = null" class="text-slate-400 hover:text-slate-600 p-1 rounded-lg">
            <X class="h-5 w-5" />
          </button>
        </div>
        <p class="text-xs sm:text-sm text-slate-600 leading-relaxed">
          Tạo lớp học cho khoá nhóm. Mỗi lớp có sĩ số tối đa, có thể gán vào một phòng học của trung tâm, và có các khung giờ
          kèm giáo viên phụ trách. Học viên được gán vào lớp sẽ nhận lịch học theo lớp; hệ thống chặn nếu lớp đã đầy.
          Nếu không tạo lớp, khoá học hoạt động như hiện tại.
        </p>

        <!-- Thêm lớp -->
        <form @submit.prevent="addClass" class="rounded-xl border border-slate-200 bg-slate-50/60 p-4 grid grid-cols-1 sm:grid-cols-[1fr_1fr_auto] gap-3 items-end">
          <label class="text-xs font-semibold text-slate-700">
            Tên lớp học
            <input v-model="newClass.name" placeholder="VD: Nhóm A" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 focus:border-rose-500 focus:outline-none" />
          </label>
          <label class="text-xs font-semibold text-slate-700">
            Phòng học <span class="font-normal text-slate-400">(tuỳ chọn)</span>
            <select v-model="newClass.roomKey" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 focus:border-rose-500 focus:outline-none">
              <option value="">— Không chọn phòng —</option>
              <option v-for="r in classRooms" :key="roomKey(r.center, r.room)" :value="roomKey(r.center, r.room)">{{ r.room }} · {{ r.center }}</option>
            </select>
          </label>
          <button type="submit" :disabled="savingClass" class="inline-flex items-center justify-center gap-1.5 rounded-xl bg-rose-600 hover:bg-rose-700 disabled:opacity-50 px-4 py-2 text-sm font-bold text-white whitespace-nowrap">
            <Plus class="h-4 w-4" /> Thêm lớp học
          </button>
        </form>
        <p v-if="classRooms.length === 0" class="text-[11px] text-slate-500">Chưa có phòng học nào. Thêm phòng ở màn Trung tâm → Phòng học để gán lớp vào phòng.</p>

        <p v-if="classCards.length === 0" class="text-sm text-slate-500">Chưa có lớp học nào.</p>

        <!-- Thẻ từng lớp -->
        <div v-for="card in classCards" :key="card.id" class="rounded-xl border border-slate-200 p-4 space-y-3">
          <div class="flex items-center gap-3">
            <input
              v-model="card.name"
              @change="saveCard(card)"
              class="flex-1 rounded-xl border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-900 focus:border-rose-500 focus:outline-none"
              title="Tên lớp (sửa xong bấm ra ngoài để lưu)"
            />
            <span
              :class="[card.capacity && card.students_count >= card.capacity ? 'bg-rose-50 text-rose-700' : 'bg-slate-100 text-slate-700', 'rounded-full px-2.5 py-1 text-xs font-semibold whitespace-nowrap']"
              title="Học viên đang học / sĩ số tối đa"
            >
              {{ card.students_count }}{{ card.capacity ? ` / ${card.capacity}` : '' }} học viên
            </span>
            <button type="button" @click="removeClass(card)" class="p-1.5 rounded-lg text-slate-500 hover:bg-rose-50 hover:text-rose-600" title="Xoá lớp">
              <Trash2 class="h-4 w-4" />
            </button>
          </div>

          <div class="flex items-center gap-2">
            <span class="text-xs font-semibold text-slate-700 w-20">Phòng học</span>
            <select v-model="card.roomKey" @change="onCardRoomChange(card)" class="rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-sm text-slate-900 focus:border-rose-500 focus:outline-none">
              <option value="">— Không chọn phòng —</option>
              <option v-for="r in classRooms" :key="roomKey(r.center, r.room)" :value="roomKey(r.center, r.room)">{{ r.room }} · {{ r.center }}</option>
            </select>
          </div>

          <!-- Khung giờ -->
          <div class="space-y-2">
            <p class="text-xs text-slate-500">{{ card.schedules.length ? 'Khung giờ' : 'Chưa có khung giờ' }}</p>
            <div v-for="(slot, i) in card.schedules" :key="i" class="flex flex-wrap items-center gap-2 text-sm">
              <span class="w-16 rounded-lg bg-slate-100 px-2 py-1.5 text-center text-xs font-semibold text-slate-700">{{ dayLabel(slot.day) }}</span>
              <span class="rounded-lg bg-slate-100 px-2 py-1.5 text-xs font-mono text-slate-700">{{ slot.start }}{{ slot.end ? ` - ${slot.end}` : '' }}</span>
              <span class="rounded-lg bg-slate-100 px-2 py-1.5 text-xs text-slate-700">{{ slot.teacher || 'Chưa gán giáo viên' }}</span>
              <button type="button" @click="removeSlot(card, i)" class="p-1 rounded-lg text-slate-400 hover:bg-rose-50 hover:text-rose-600" title="Xoá khung giờ">
                <Trash2 class="h-3.5 w-3.5" />
              </button>
            </div>

            <!-- Thêm khung giờ + sĩ số -->
            <div class="flex flex-wrap items-center gap-2">
              <select v-model="card.draft.day" class="rounded-xl border border-slate-200 bg-white px-2.5 py-1.5 text-sm text-slate-900 focus:border-rose-500 focus:outline-none">
                <option value="" disabled>Thứ</option>
                <option v-for="d in DAYS" :key="d.value" :value="d.value">{{ d.label }}</option>
              </select>
              <input v-model="card.draft.start" type="time" title="Giờ bắt đầu" class="rounded-xl border border-slate-200 bg-white px-2.5 py-1.5 text-sm text-slate-900 focus:border-rose-500 focus:outline-none" />
              <span class="text-slate-400">–</span>
              <input v-model="card.draft.end" type="time" title="Giờ kết thúc (tuỳ chọn)" class="rounded-xl border border-slate-200 bg-white px-2.5 py-1.5 text-sm text-slate-900 focus:border-rose-500 focus:outline-none" />
              <select v-model="card.draft.teacher" class="rounded-xl border border-slate-200 bg-white px-2.5 py-1.5 text-sm text-slate-900 focus:border-rose-500 focus:outline-none">
                <option value="">— Thêm giáo viên —</option>
                <option v-for="t in classTeachers" :key="t" :value="t">{{ t }}</option>
              </select>
              <label class="flex items-center gap-1.5 text-xs font-semibold text-slate-700" title="Sĩ số tối đa của lớp (để trống = không giới hạn)">
                Sĩ số
                <input
                  v-model.number="card.capacity"
                  @change="saveCard(card)"
                  type="number"
                  min="1"
                  class="w-20 rounded-xl border border-slate-200 bg-white px-2.5 py-1.5 text-sm text-slate-900 focus:border-rose-500 focus:outline-none"
                />
              </label>
              <button type="button" @click="addSlot(card)" class="rounded-xl border border-slate-200 p-1.5 text-slate-600 hover:bg-slate-50" title="Thêm khung giờ">
                <Plus class="h-4 w-4" />
              </button>
            </div>
          </div>
        </div>
      </div>
    </Modal>
  </div>
</template>
