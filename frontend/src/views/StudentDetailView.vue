<script setup>
import { ref, onMounted, computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { studentApi } from '../services/api';
import Badge from '../components/Badge.vue';
import Modal from '../components/Modal.vue';
import AddSupplyModal from '../components/AddSupplyModal.vue';
import MessageTemplateModal from '../components/MessageTemplateModal.vue';
import RetentionNoteModal from '../components/RetentionNoteModal.vue';
import RenewModal from '../components/RenewModal.vue';
import {
  ArrowLeft,
  BookOpen,
  UserX,
  Plus,
  Edit3,
  Calendar,
  CreditCard,
  MessageSquare,
  Share2,
  Package,
  CheckCircle2,
  CalendarDays,
  Trash2,
  ChevronUp,
  ChevronDown,
  Copy,
  ExternalLink,
} from 'lucide-vue-next';

const route = useRoute();
const router = useRouter();
const studentId = route.params.id;

const student = ref(null);
const courses = ref([]);

const showTemplateModal = ref(false);
const showNoteModal = ref(false);
const showRenewModal = ref(false);
const showAbsenceModal = ref(false);
const showSupplyModal = ref(false);
const showEditCourseModal = ref(false);

const absenceWithLeave = ref(true);
const absenceNote = ref('');
const isSubmitting = ref(false);

// Course inline editor values
const attendedSessionsInput = ref(0);
const totalSessionsInput = ref(12);
const allowedAbsencesInput = ref(3);
const assignedClassInput = ref('');

const fetchData = async () => {
  try {
    const res = await studentApi.get(studentId);
    student.value = res.data.student;
    courses.value = res.data.courses;

    if (student.value) {
      attendedSessionsInput.value = student.value.total_sessions - student.value.remaining_sessions;
      totalSessionsInput.value = student.value.total_sessions;
      allowedAbsencesInput.value = student.value.allowed_absences || 3;
      assignedClassInput.value = student.value.assigned_class || '';
    }
  } catch (err) {
    console.error('Error loading student profile: ', err);
  }
};

onMounted(fetchData);

const attendedSessions = computed(() => {
  if (!student.value) return 0;
  return Math.max(0, student.value.total_sessions - student.value.remaining_sessions);
});

const progressPercent = computed(() => {
  if (!student.value || student.value.total_sessions <= 0) return 0;
  return Math.min(100, Math.round((attendedSessions.value / student.value.total_sessions) * 100));
});

const handleQuickAttendance = async () => {
  if (!student.value) return;
  try {
    isSubmitting.value = true;
    await studentApi.recordAttendance(student.value.id, {
      note: 'Điểm danh hôm nay',
    });
    fetchData();
  } catch (err) {
    alert(err.response?.data?.error || err.response?.data?.message || 'Có lỗi xảy ra khi điểm danh');
  } finally {
    isSubmitting.value = false;
  }
};

const saveAttendedSessions = async () => {
  const newAttended = Number(attendedSessionsInput.value);
  const newTotal = Number(student.value.total_sessions);
  const newRemaining = Math.max(0, newTotal - newAttended);

  try {
    isSubmitting.value = true;
    await studentApi.updateProgress(student.value.id, {
      remaining_sessions: newRemaining,
    });
    fetchData();
    alert('Đã lưu số buổi điểm danh!');
  } catch (err) {
    alert(err.response?.data?.message || 'Lỗi khi cập nhật');
  } finally {
    isSubmitting.value = false;
  }
};

const saveTotalSessions = async () => {
  const newTotal = Number(totalSessionsInput.value);
  const currentAttended = attendedSessions.value;
  const newRemaining = Math.max(0, newTotal - currentAttended);

  try {
    isSubmitting.value = true;
    await studentApi.updateProgress(student.value.id, {
      total_sessions: newTotal,
      remaining_sessions: newRemaining,
    });
    fetchData();
    alert('Đã lưu tổng số buổi học!');
  } catch (err) {
    alert(err.response?.data?.message || 'Lỗi khi cập nhật');
  } finally {
    isSubmitting.value = false;
  }
};

const saveAllowedAbsences = async () => {
  try {
    isSubmitting.value = true;
    await studentApi.updateProgress(student.value.id, {
      allowed_absences: Number(allowedAbsencesInput.value),
    });
    fetchData();
    alert('Đã lưu giới hạn vắng có phép!');
  } catch (err) {
    alert(err.response?.data?.message || 'Lỗi khi cập nhật');
  } finally {
    isSubmitting.value = false;
  }
};

const saveAssignedClass = async () => {
  try {
    isSubmitting.value = true;
    await studentApi.updateProgress(student.value.id, {
      assigned_class: assignedClassInput.value,
    });
    fetchData();
  } catch (err) {
    console.error(err);
  } finally {
    isSubmitting.value = false;
  }
};

const submitAbsence = async () => {
  if (!student.value) return;
  try {
    isSubmitting.value = true;
    await studentApi.recordAbsence(student.value.id, {
      with_leave: absenceWithLeave.value,
      note: absenceNote.value,
    });
    showAbsenceModal.value = false;
    absenceNote.value = '';
    fetchData();
  } catch (err) {
    alert(err.response?.data?.message || 'Có lỗi xảy ra');
  } finally {
    isSubmitting.value = false;
  }
};

const formatDate = (val) => {
  if (!val) return '—';
  const d = new Date(val);
  return d.toISOString().split('T')[0];
};

const formatDateDMY = (val) => {
  if (!val) return '—';
  const d = new Date(val);
  return `${d.getDate()}/${d.getMonth() + 1}/${d.getFullYear()}`;
};

const copyPlaceholder = () => {
  navigator.clipboard?.writeText(student.value?.name || '');
  alert('Đã sao chép tên học viên: ' + student.value?.name);
};

const copyParentPortalLink = () => {
  const url = window.location.href;
  navigator.clipboard?.writeText(url);
  alert('Đã sao chép đường link Cổng phụ huynh:\n' + url);
};
</script>

<template>
  <div v-if="student" class="min-h-screen bg-[#f8fafc] py-8 sm:py-12 px-4 sm:px-6">
    <div class="max-w-4xl mx-auto space-y-6">
      <!-- STANDALONE STUDENT PROFILE CARD matching Screenshot precisely -->
      <div class="rounded-2xl sm:rounded-3xl border border-slate-200/90 bg-white p-6 sm:p-8 shadow-xs space-y-6">
        <!-- 1. Header Profile Info -->
        <div class="flex items-start gap-4">
          <!-- Avatar Box (Chưa có ảnh) -->
          <div class="flex h-20 w-20 shrink-0 items-center justify-center rounded-2xl border border-slate-200 bg-slate-50 text-slate-400 text-xs font-medium text-center p-2">
            <span>Chưa có ảnh</span>
          </div>

          <!-- Name & Center Details -->
          <div>
            <div class="flex items-center gap-2">
              <h1 class="text-xl sm:text-2xl font-black tracking-tight text-slate-900">
                {{ student.name }}
              </h1>
              <span class="rounded-full bg-emerald-50 border border-emerald-200 px-2.5 py-0.5 text-[11px] font-bold text-emerald-700">
                {{ student.status === 'active' ? 'Đang học' : student.status === 'paused' ? 'Tạm dừng' : 'Đã nghỉ' }}
              </span>
            </div>

            <p class="text-xs text-slate-500 mt-1.5">
              Ngày nhập học: <span class="font-medium text-slate-700">{{ formatDate(student.join_date) }}</span>
            </p>
            <p class="text-xs text-slate-500 mt-0.5">
              Trung tâm: <span class="font-medium text-slate-700">{{ student.center || 'Khoa Anh Music Hóc Môn' }}</span>
            </p>
          </div>
        </div>

        <!-- 2. Tổng tiến độ with Coral/Red progress bar matching Screenshot -->
        <div class="space-y-2 pt-1 border-t border-slate-100">
          <div class="flex items-center justify-between text-xs sm:text-sm">
            <span class="font-bold text-slate-900">Tổng tiến độ</span>
            <span class="font-semibold text-slate-500 text-xs">
              {{ attendedSessions }} / {{ student.total_sessions }} buổi &bull; còn {{ student.remaining_sessions }}
            </span>
          </div>

          <!-- Coral / Red Progress Bar -->
          <div class="w-full bg-red-100/60 rounded-full h-2 overflow-hidden">
            <div
              class="h-full bg-red-500 rounded-full transition-all duration-500"
              :style="{ width: `${progressPercent}%` }"
            ></div>
          </div>
        </div>

        <!-- 3. Horizontal Action Pills matching Screenshot -->
        <div class="flex items-center justify-end gap-2 overflow-x-auto pb-1 text-xs">
          <!-- Dot / Icon -->
          <button class="h-8 w-8 rounded-xl border border-slate-200 flex items-center justify-center text-slate-400 hover:bg-slate-50">
            <span class="h-3 w-3 rounded-full border border-red-500"></span>
          </button>

          <!-- [ Nhận xét ] -->
          <button
            @click="showNoteModal = true"
            class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 px-3 py-1.5 font-semibold text-slate-700 transition-colors shrink-0 shadow-2xs"
          >
            <MessageSquare class="h-3.5 w-3.5 text-slate-500" />
            <span>Nhận xét</span>
          </button>

          <!-- [ Học phí ] -->
          <button
            @click="showRenewModal = true"
            class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 px-3 py-1.5 font-semibold text-slate-700 transition-colors shrink-0 shadow-2xs"
          >
            <CreditCard class="h-3.5 w-3.5 text-slate-500" />
            <span>Học phí</span>
          </button>

          <!-- [ Ngày đã học ] -->
          <button
            class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 px-3 py-1.5 font-semibold text-slate-700 transition-colors shrink-0 shadow-2xs"
          >
            <Calendar class="h-3.5 w-3.5 text-slate-500" />
            <span>Ngày đã học</span>
          </button>

          <!-- [ Vắng ] -->
          <button
            @click="showAbsenceModal = true"
            class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 px-3 py-1.5 font-semibold text-slate-700 transition-colors shrink-0 shadow-2xs"
          >
            <UserX class="h-3.5 w-3.5 text-slate-500" />
            <span>Vắng</span>
          </button>

          <!-- [ Cổng phụ huynh ] -->
          <button
            @click="copyParentPortalLink"
            class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 px-3 py-1.5 font-semibold text-slate-700 transition-colors shrink-0 shadow-2xs"
          >
            <Share2 class="h-3.5 w-3.5 text-slate-500" />
            <span>Cổng phụ huynh</span>
          </button>

          <!-- [ Thêm khóa học ] -->
          <button
            @click="showRenewModal = true"
            class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 px-3 py-1.5 font-semibold text-slate-700 transition-colors shrink-0 shadow-2xs"
          >
            <Plus class="h-3.5 w-3.5 text-slate-500" />
            <span>Thêm khóa học</span>
          </button>
        </div>

        <!-- 4. COURSE CARD matching Screenshot -->
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-2xs space-y-3.5">
          <!-- Course Card Header -->
          <div class="flex items-center justify-between">
            <div class="flex items-center gap-2 text-xs font-semibold text-slate-700">
              <div class="flex flex-col text-slate-400">
                <ChevronUp class="h-3 w-3 -mb-1" />
                <ChevronDown class="h-3 w-3" />
              </div>
              <span>{{ attendedSessions }}/{{ student.total_sessions }} đã điểm danh</span>
            </div>

            <!-- Red button: [ Điểm danh hôm nay ] -->
            <button
              @click="handleQuickAttendance"
              :disabled="isSubmitting || student.remaining_sessions <= 0"
              class="rounded-lg bg-red-600 px-3.5 py-1.5 text-xs font-bold text-white hover:bg-red-700 transition-colors shadow-xs disabled:opacity-50"
            >
              Điểm danh hôm nay
            </button>
          </div>

          <!-- Course Title matching Screenshot -->
          <div class="flex items-center justify-between">
            <h2 class="text-sm font-bold text-slate-900">
              [{{ student.course?.category || 'HSSV' }}] {{ student.course?.name || 'Piano 1-1 (tuần 1 buổi)/ 3 THÁNG' }}
            </h2>
            <span class="text-xs font-bold text-slate-400">
              {{ attendedSessions }}/{{ student.total_sessions }}
            </span>
          </div>

          <!-- Subtitle line: Dates & Actions -->
          <div class="flex items-center gap-3 text-xs text-slate-500">
            <div class="flex items-center gap-1">
              <CalendarDays class="h-3.5 w-3.5 text-slate-400" />
              <span>
                Bắt đầu {{ formatDateDMY(student.join_date) }} &bull; Kết thúc dự kiến {{ student.end_date ? formatDateDMY(student.end_date) : '9/12/2026' }} &bull; {{ student.schedule_info || '1 buổi/tuần' }}
              </span>
            </div>
            <button @click="copyPlaceholder" class="text-slate-400 hover:text-slate-600 font-medium">
              Copy placeholder
            </button>
            <button class="text-slate-500 hover:text-slate-700 flex items-center gap-0.5">
              <Edit3 class="h-3 w-3" />
              <span>Sửa</span>
            </button>
          </div>

          <!-- Blue Progress Bar matching Screenshot -->
          <div class="w-full bg-red-100/60 rounded-full h-2 overflow-hidden">
            <div
              class="h-full bg-sky-500 rounded-full transition-all duration-500"
              :style="{ width: `${progressPercent}%` }"
            ></div>
          </div>

          <!-- Inline Sessions Editors matching Screenshot -->
          <div class="flex flex-wrap items-center gap-3 text-xs pt-1">
            <!-- [ 3 ] [ Lưu ] (Blue) -->
            <div class="flex items-center gap-1">
              <input
                type="number"
                v-model="attendedSessionsInput"
                min="0"
                class="w-12 rounded-lg border border-slate-300 bg-white px-2 py-1 text-center font-bold text-slate-900 focus:border-red-500 focus:outline-none"
              />
              <button
                @click="saveAttendedSessions"
                class="rounded-lg bg-sky-500 px-3 py-1 font-bold text-white hover:bg-sky-600 transition-colors"
              >
                Lưu
              </button>
            </div>

            <!-- [ 12 ] [ Lưu ] (Green) -->
            <div class="flex items-center gap-1">
              <input
                type="number"
                v-model="totalSessionsInput"
                min="1"
                class="w-12 rounded-lg border border-slate-300 bg-white px-2 py-1 text-center font-bold text-slate-900 focus:border-red-500 focus:outline-none"
              />
              <button
                @click="saveTotalSessions"
                class="rounded-lg bg-emerald-500 px-3 py-1 font-bold text-white hover:bg-emerald-600 transition-colors"
              >
                Lưu
              </button>
            </div>

            <button class="p-1 text-slate-400 hover:text-slate-600">
              <Calendar class="h-4 w-4" />
            </button>
            <button class="p-1 text-slate-400 hover:text-rose-600">
              <Trash2 class="h-4 w-4" />
            </button>
          </div>

          <!-- Absences row: Đã vắng có phép 1/3 [ 3 ] [ Lưu ] matching Screenshot -->
          <div class="flex items-center gap-2 text-xs pt-1">
            <span class="rounded-md bg-red-50 border border-red-200 px-2 py-1 font-bold text-red-700 text-[11px]">
              Đã vắng có phép {{ student.absences_used || 1 }}/{{ student.allowed_absences || 3 }}
            </span>
            <input
              type="number"
              v-model="allowedAbsencesInput"
              min="0"
              class="w-12 rounded-lg border border-slate-300 bg-white px-2 py-1 text-center font-bold text-slate-900 focus:border-red-500 focus:outline-none"
            />
            <button
              @click="saveAllowedAbsences"
              class="rounded-lg bg-sky-500 px-3 py-1 font-bold text-white hover:bg-sky-600 transition-colors"
            >
              Lưu
            </button>
          </div>

          <!-- Class selector dropdown matching Screenshot -->
          <div class="space-y-1 pt-1">
            <div class="max-w-xs">
              <select
                v-model="assignedClassInput"
                @change="saveAssignedClass"
                class="w-full rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs text-slate-600 focus:border-red-500 focus:outline-none"
              >
                <option value="">&mdash; Chưa gán lớp học &mdash;</option>
                <option value="piano_t3_t5">Piano T3-T5 (18:00 - 19:00)</option>
                <option value="piano_t7_cn">Piano T7-CN (09:00 - 10:00)</option>
                <option value="guitar_t2_t4">Guitar T2-T4 (19:00 - 20:00)</option>
              </select>
            </div>
            <p class="text-[11px] font-bold text-red-600">
              Bắt buộc chọn ít nhất 1 khung giờ cho học viên này
            </p>
          </div>
        </div>

        <!-- 5. SUPPLIES SECTION matching Screenshot -->
        <div class="pt-2 space-y-1 text-xs">
          <div class="flex items-center justify-between">
            <span class="font-medium text-slate-600">Học cụ đã giao</span>
            <button
              @click="showSupplyModal = true"
              class="text-[11px] font-bold text-red-600 hover:underline"
            >
              + Giao thêm học cụ
            </button>
          </div>

          <div v-if="!student.supplies || student.supplies.length === 0" class="text-slate-400 py-1">
            Chưa có học cụ
          </div>

          <div v-else class="divide-y divide-slate-100 bg-slate-50/60 rounded-xl p-3 border border-slate-100 space-y-1.5">
            <div
              v-for="sup in student.supplies"
              :key="sup.id || sup.name"
              class="flex items-center justify-between text-xs py-1"
            >
              <div>
                <span class="font-bold text-slate-800">{{ sup.name }}</span>
                <span class="text-slate-400 ml-2 text-[11px]">(Ngày: {{ formatDate(sup.delivery_date) }})</span>
              </div>
              <span class="font-bold text-slate-700">SL: {{ sup.quantity }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Modals -->
    <AddSupplyModal
      :show="showSupplyModal"
      :student="student"
      @close="showSupplyModal = false"
      @added="fetchData"
    />

    <Modal :show="showAbsenceModal" max-width="md" @close="showAbsenceModal = false">
      <div class="p-6">
        <h3 class="text-base font-bold text-slate-900">Báo Vắng Học Cho {{ student.name }}</h3>
        <form @submit.prevent="submitAbsence" class="mt-4 space-y-4">
          <div class="space-y-2">
            <label class="flex items-center gap-2 text-xs font-medium cursor-pointer">
              <input type="radio" :value="true" v-model="absenceWithLeave" class="text-red-600" />
              <span>Nghỉ có phép</span>
            </label>
            <label class="flex items-center gap-2 text-xs font-medium cursor-pointer">
              <input type="radio" :value="false" v-model="absenceWithLeave" class="text-red-600" />
              <span>Nghỉ không phép</span>
            </label>
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1">Lý do nghỉ</label>
            <textarea
              v-model="absenceNote"
              rows="3"
              placeholder="Nhập lý do học viên xin nghỉ..."
              class="w-full rounded-xl border border-slate-200 p-2.5 text-xs focus:border-red-500 focus:outline-none"
            ></textarea>
          </div>

          <div class="flex items-center justify-end gap-2 border-t border-slate-100 pt-4">
            <button type="button" @click="showAbsenceModal = false" class="rounded-xl border border-slate-200 px-4 py-2 text-xs font-medium">Hủy</button>
            <button type="submit" :disabled="isSubmitting" class="rounded-xl bg-red-600 px-4 py-2 text-xs font-bold text-white hover:bg-red-700">Lưu Báo Vắng</button>
          </div>
        </form>
      </div>
    </Modal>

    <RetentionNoteModal :show="showNoteModal" :student="student" @close="showNoteModal = false" @saved="fetchData" />
    <RenewModal :show="showRenewModal" :student="student" @close="showRenewModal = false" @renewed="fetchData" />
  </div>
</template>
