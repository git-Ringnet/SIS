<script setup>
import { toLocalDateString } from '../services/date';
import { confirmDialog } from '../services/useConfirm';
import { notify } from '../services/notify';
import { ref, computed, onMounted, watch } from 'vue';
import { useRoute } from 'vue-router';
import { studentApi, courseApi } from '../services/api';
import Badge from '../components/Badge.vue';
import Modal from '../components/Modal.vue';
import AddSupplyModal from '../components/AddSupplyModal.vue';
import AttendanceButton from '../components/AttendanceButton.vue';
import SearchableSelect from '../components/SearchableSelect.vue';
import DatePickerVN from '../components/DatePickerVN.vue';
import Pagination from '../components/Pagination.vue';
import ScheduleFields from '../components/ScheduleFields.vue';
import ImportStudentsModal from '../components/ImportStudentsModal.vue';
import StudentDetailModal from '../components/StudentDetailModal.vue';
import MessageTemplateModal from '../components/MessageTemplateModal.vue';
import RetentionNoteModal from '../components/RetentionNoteModal.vue';
import RenewModal from '../components/RenewModal.vue';
import { useCenters } from '../services/useCenters';
import {
  UserPlus,
  Search,
  Flame,
  AlertTriangle,
  Eye,
  ExternalLink,
  Edit3,
  Trash2,
  Phone,
  X,
  Package,
  CheckCircle2,
  Filter,
  Download,
  UploadCloud,
  Upload,
  User,
  Plus,
  ArrowUp,
  ArrowDown,
  Share2,
  MessageSquare,
  CreditCard,
  Calendar,
  UserX,
  Copy,
  Check,
} from 'lucide-vue-next';
import MoneyInput from '../components/MoneyInput.vue';
import { tuitionState, TUITION_BUTTON } from '../services/tuition';
import ReturnSupplyModal from '../components/ReturnSupplyModal.vue';

const route = useRoute();

const students = ref([]);
const courses = ref([]);
const search = ref('');
const phoneSearch = ref('');
const courseId = ref('all');
const status = ref('all');
const centerFilter = ref('all');
const sessionsFilter = ref('all');
const tuitionFilter = ref('all');
const assignedClassFilter = ref('all');
const teacherFilter = ref('all');
const staffFilter = ref('all');
const sortFilter = ref('newest');
const alertFilter = ref('');
const attendanceStatusFilter = ref('all');
const page = ref(1);
const perPage = ref(20);
const pagination = ref(null);

const availableCenters = ref([]);
const availableTeachers = ref([]);
const availableStaff = ref([]);
const availableClasses = ref([]);

const filterCourseOptions = computed(() => [
  { id: 'all', name: 'Tất cả khóa học' },
  ...courses.value.map(c => ({ id: c.id, name: c.name }))
]);

const filterCenterOptions = computed(() => [
  { id: 'all', name: 'Tất cả trung tâm' },
  ...(availableCenters.value.length ? availableCenters.value : centerNames.value).map(c => ({ id: c, name: c }))
]);

const filterClassOptions = computed(() => [
  { id: 'all', name: 'Tất cả lớp học' },
  { id: 'unassigned', name: 'Chưa gán lớp' },
  ...availableClasses.value.map(cls => (typeof cls === 'object' && cls ? cls : { id: cls, name: cls }))
]);

const filterTeacherOptions = computed(() => [
  { id: 'all', name: 'Tất cả giáo viên' },
  { id: 'unassigned', name: 'Chưa gán' },
  ...availableTeachers.value.map(t => (typeof t === 'object' && t ? t : { id: t, name: t }))
]);

const filterStaffOptions = computed(() => [
  { id: 'all', name: 'Tất cả người phụ trách' },
  { id: 'unassigned', name: 'Chưa gán' },
  ...(availableStaff.value.length ? availableStaff.value : availableTeachers.value).map(s => (typeof s === 'object' && s ? s : { id: s, name: s }))
]);

const showCreateModal = ref(false);
const showEditModal = ref(false);
const showSupplyModal = ref(false);
const showImportModal = ref(false);
const showInfoModal = ref(false);
const showDetailModal = ref(false);
const selectedDetailStudentId = ref(null);
const selectedSupplyStudent = ref(null);
const editingStudent = ref(null);
const isSubmitting = ref(false);

const expandedStudentId = ref(null);
const inlineAttended = ref({});
const inlineTotal = ref({});
const inlineAllowedAbsences = ref({});

const activeActionStudent = ref(null);
const showNoteModal = ref(false);
const showRenewModal = ref(false);
const showAbsenceModal = ref(false);
const showParentPortalModal = ref(false);
const copiedPortalLink = ref(false);
const absenceWithLeave = ref(true);
const absenceNote = ref('');
const isSubmittingAbsence = ref(false);

const formatDate = (val) => {
  if (!val) return '—';
  try {
    const d = new Date(val);
    return toLocalDateString(d);
  } catch {
    return val;
  }
};

// dd-mm-yyyy
const formatDateDMY = (val) => {
  const iso = formatDate(val);
  return /^\d{4}-\d{2}-\d{2}$/.test(iso) ? iso.split('-').reverse().join('-') : iso;
};

const getStudentProgress = (st) => {
  if (!st || st.total_sessions <= 0) return 0;
  const attended = Math.max(0, st.total_sessions - st.remaining_sessions);
  return Math.min(100, Math.round((attended / st.total_sessions) * 100));
};

const toggleExpandStudent = (student) => {
  if (expandedStudentId.value === student.id) {
    expandedStudentId.value = null;
  } else {
    expandedStudentId.value = student.id;
    inlineAttended.value[student.id] = Math.max(0, student.total_sessions - student.remaining_sessions);
    inlineTotal.value[student.id] = student.total_sessions;
    inlineAllowedAbsences.value[student.id] = student.allowed_absences || 3;
  }
};

const handleRowClick = (event, student) => {
  const target = event.target;
  if (target.closest('button') || target.closest('a') || target.closest('input') || target.closest('select') || target.closest('label')) {
    return;
  }
  toggleExpandStudent(student);
};

const saveInlineAttendedSessions = async (st) => {
  const newAttended = Number(inlineAttended.value[st.id]);
  const newTotal = Number(st.total_sessions);
  const newRemaining = Math.max(0, newTotal - newAttended);

  try {
    await studentApi.updateProgress(st.id, {
      remaining_sessions: newRemaining,
    });
    st.remaining_sessions = newRemaining;
    notify.success('Đã lưu số buổi đã học!');
    fetchData();
  } catch (err) {
    notify.apiError(err, 'Lỗi khi cập nhật');
  }
};

const saveInlineTotalSessions = async (st) => {
  const newTotal = Number(inlineTotal.value[st.id]);
  const currentAttended = Math.max(0, st.total_sessions - st.remaining_sessions);
  const newRemaining = Math.max(0, newTotal - currentAttended);

  try {
    await studentApi.updateProgress(st.id, {
      total_sessions: newTotal,
      remaining_sessions: newRemaining,
    });
    st.total_sessions = newTotal;
    st.remaining_sessions = newRemaining;
    notify.success('Đã lưu tổng số buổi học!');
    fetchData();
  } catch (err) {
    notify.apiError(err, 'Lỗi khi cập nhật');
  }
};

const saveInlineAllowedAbsences = async (st) => {
  try {
    const val = Number(inlineAllowedAbsences.value[st.id]);
    await studentApi.updateProgress(st.id, {
      allowed_absences: val,
    });
    st.allowed_absences = val;
    notify.success('Đã lưu giới hạn vắng phép!');
    fetchData();
  } catch (err) {
    notify.apiError(err, 'Lỗi khi cập nhật');
  }
};

const failedAvatars = ref(new Set());
const handleAvatarError = (studentId) => {
  failedAvatars.value.add(studentId);
};

const getAvatarUrl = (url) => {
  if (!url) return '';
  if (url.startsWith('http://') || url.startsWith('https://') || url.startsWith('data:')) {
    return url;
  }
  return url.startsWith('/') ? url : `/${url}`;
};

const uploadAvatarFile = async (event, st) => {
  const file = event.target.files?.[0];
  if (!file) return;

  const formData = new FormData();
  formData.append('avatar', file);

  try {
    const res = await studentApi.uploadAvatar(st.id, formData);
    st.avatar = res.data.avatar;
    failedAvatars.value.delete(st.id);
    notify.success('Đã tải ảnh học viên thành công!');
  } catch (err) {
    notify.apiError(err, 'Không thể tải ảnh');
  }
};

// Ẩn / Hiện khóa hoàn thành
const hideCompletedCourses = ref({});
const toggleHideCompleted = (studentId) => {
  hideCompletedCourses.value[studentId] = !hideCompletedCourses.value[studentId];
};

const getStudentCourses = (student) => {
  return student.registered_courses_list || [];
};

const isCourseCompleted = (c) => {
  return c.status === 'completed' || Number(c.remaining_sessions) <= 0 || (Number(c.attended_sessions) >= Number(c.total_sessions));
};

const getVisibleCourses = (student) => {
  const all = getStudentCourses(student);
  if (!hideCompletedCourses.value[student.id]) {
    return all;
  }
  return all.filter((c) => !isCourseCompleted(c));
};

const getCourseProgress = (c) => {
  if (!c || !c.total_sessions || c.total_sessions <= 0) return 0;
  const attended = Number(c.attended_sessions ?? (c.total_sessions - c.remaining_sessions) ?? 0);
  return Math.min(100, Math.round((attended / c.total_sessions) * 100));
};

// Form thêm khóa học inline
const showAddCourseForm = ref({});
const isSubmittingCourse = ref({});
const addCourseForms = ref({});

const getAddCourseForm = (studentId) => {
  if (!addCourseForms.value[studentId]) {
    const today = new Date().toISOString().split('T')[0];
    addCourseForms.value[studentId] = {
      course_id: courses.value[0]?.id || '',
      total_sessions: 12,
      start_date: today,
      sessions_per_week: '',
      end_date: '',
      end_touched: false,
    };
  }
  return addCourseForms.value[studentId];
};

// Tự tính ngày kết thúc dự kiến khi đổi ngày bắt đầu / tổng buổi / buổi/tuần (trừ khi đã sửa tay).
const recomputeAddCourseEnd = (student) => {
  const form = getAddCourseForm(student.id);
  if (form.end_touched) return;
  const slots = student.schedule_slots || [];
  form.end_date = computeCourseEndDate(
    form.start_date,
    Number(form.total_sessions),
    Number(form.sessions_per_week) || slots.length || 1,
    slots.map((x) => x.day),
  );
};

const toggleAddCourseForm = (student) => {
  showAddCourseForm.value[student.id] = !showAddCourseForm.value[student.id];
  if (showAddCourseForm.value[student.id]) {
    recomputeAddCourseEnd(student);
  }
};

const submitAddCourse = async (student) => {
  const form = getAddCourseForm(student.id);
  if (!form.course_id) {
    notify.error('Vui lòng chọn khóa học.');
    return;
  }
  if (!form.total_sessions || Number(form.total_sessions) <= 0) {
    notify.error('Tổng số buổi phải lớn hơn 0.');
    return;
  }

  try {
    isSubmittingCourse.value[student.id] = true;
    const res = await studentApi.addCourse(student.id, {
      course_id: form.course_id,
      total_sessions: Number(form.total_sessions),
      start_date: form.start_date || null,
      end_date: form.end_date || null,
      sessions_per_week: form.sessions_per_week || null,
    });
    notify.success(res.data.message || 'Đã thêm khóa học cho học viên thành công!');
    showAddCourseForm.value[student.id] = false;
    if (res.data.student) {
      Object.assign(student, res.data.student);
    }
    fetchData();
  } catch (err) {
    notify.apiError(err, 'Lỗi khi thêm khóa học');
  } finally {
    isSubmittingCourse.value[student.id] = false;
  }
};

// Inline inputs cho từng thẻ khóa học
const courseAttendedInputs = ref({});
const courseTotalInputs = ref({});
const courseAllowedAbsencesInputs = ref({});

const getCourseAttendedInput = (student, courseItem) => {
  const key = `${student.id}_${courseItem.id}`;
  if (courseAttendedInputs.value[key] === undefined) {
    courseAttendedInputs.value[key] = courseItem.attended_sessions ?? 0;
  }
  return {
    get value() { return courseAttendedInputs.value[key]; },
    set value(v) { courseAttendedInputs.value[key] = v; }
  };
};

const getCourseTotalInput = (student, courseItem) => {
  const key = `${student.id}_${courseItem.id}`;
  if (courseTotalInputs.value[key] === undefined) {
    courseTotalInputs.value[key] = courseItem.total_sessions ?? 12;
  }
  return {
    get value() { return courseTotalInputs.value[key]; },
    set value(v) { courseTotalInputs.value[key] = v; }
  };
};

const getCourseAllowedAbsencesInput = (student, courseItem) => {
  const key = `${student.id}_${courseItem.id}`;
  if (courseAllowedAbsencesInputs.value[key] === undefined) {
    courseAllowedAbsencesInputs.value[key] = courseItem.allowed_absences ?? 3;
  }
  return {
    get value() { return courseAllowedAbsencesInputs.value[key]; },
    set value(v) { courseAllowedAbsencesInputs.value[key] = v; }
  };
};

const saveCourseAttended = async (student, courseItem) => {
  const key = `${student.id}_${courseItem.id}`;
  const newAttended = Number(courseAttendedInputs.value[key] ?? courseItem.attended_sessions);
  try {
    const res = await studentApi.updateCourseItem(student.id, courseItem.id, {
      attended_sessions: newAttended,
    });
    notify.success('Đã lưu số buổi đã học!');
    if (res.data.student) {
      Object.assign(student, res.data.student);
    }
    fetchData();
  } catch (err) {
    notify.apiError(err, 'Lỗi khi lưu số buổi đã học');
  }
};

const saveCourseTotal = async (student, courseItem) => {
  const key = `${student.id}_${courseItem.id}`;
  const newTotal = Number(courseTotalInputs.value[key] ?? courseItem.total_sessions);
  try {
    const res = await studentApi.updateCourseItem(student.id, courseItem.id, {
      total_sessions: newTotal,
    });
    notify.success('Đã lưu tổng số buổi học!');
    if (res.data.student) {
      Object.assign(student, res.data.student);
    }
    fetchData();
  } catch (err) {
    notify.apiError(err, 'Lỗi khi lưu tổng số buổi');
  }
};

const saveCourseAllowedAbsences = async (student, courseItem) => {
  const key = `${student.id}_${courseItem.id}`;
  const val = Number(courseAllowedAbsencesInputs.value[key] ?? courseItem.allowed_absences);
  try {
    const res = await studentApi.updateCourseItem(student.id, courseItem.id, {
      allowed_absences: val,
    });
    notify.success('Đã lưu giới hạn vắng phép!');
    if (res.data.student) {
      Object.assign(student, res.data.student);
    }
    fetchData();
  } catch (err) {
    notify.apiError(err, 'Lỗi khi lưu giới hạn vắng phép');
  }
};

// ---- Lịch nhiều ca: ca nào thuộc khoá nào ("Chọn lịch cho trang điểm danh") ----
const DAY_SHORT = { 1: 'T2', 2: 'T3', 3: 'T4', 4: 'T5', 5: 'T6', 6: 'T7', 0: 'CN' };
const slotLabel = (slot) => `${DAY_SHORT[slot.day] ?? ''} · ${slot.start}${slot.end ? ` - ${slot.end}` : ''}`;

// Các ca của khoá: đã chọn riêng thì theo danh sách chọn, không thì dùng chung mọi ca
const courseSlots = (student, courseItem) => {
  const all = student.schedule_slots || [];
  const ids = courseItem?.schedule_ids || [];
  return ids.length ? all.filter((x) => ids.includes(x.id)) : all;
};

const slotPicker = ref({ key: null, ids: [] });
const savingSlotPicker = ref(false);

const openSlotPicker = (student, courseItem) => {
  const key = `${student.id}_${courseItem.id}`;
  slotPicker.value = slotPicker.value.key === key ? { key: null, ids: [] } : { key, ids: [...(courseItem.schedule_ids || [])] };
};

const toggleSlotPick = (id) => {
  const ids = slotPicker.value.ids;
  slotPicker.value.ids = ids.includes(id) ? ids.filter((x) => x !== id) : [...ids, id];
};

const saveSlotPicker = async (student, courseItem) => {
  try {
    savingSlotPicker.value = true;
    const res = await studentApi.updateCourseItem(student.id, courseItem.id, { schedule_ids: slotPicker.value.ids });
    notify.success('Đã lưu lịch của khóa học');
    if (res.data.student) Object.assign(student, res.data.student);
    slotPicker.value = { key: null, ids: [] };
    fetchData();
  } catch (err) {
    notify.apiError(err, 'Không lưu được lịch của khóa học');
  } finally {
    savingSlotPicker.value = false;
  }
};

// ---- Đổi khóa học của một thẻ khóa (nút bút chì cạnh tên khóa) ----
const changeCourseState = ref({ show: false, student: null, item: null, courseId: '' });
const savingCourseChange = ref(false);

const openChangeCourse = (student, courseItem) => {
  changeCourseState.value = { show: true, student, item: courseItem, courseId: courseItem.course_id || student.course_id || '' };
};

const submitChangeCourse = async () => {
  const { student, item, courseId } = changeCourseState.value;
  try {
    savingCourseChange.value = true;
    const res = await studentApi.updateCourseItem(student.id, item.id, { course_id: Number(courseId) });
    notify.success('Đã đổi khóa học!');
    if (res.data.student) Object.assign(student, res.data.student);
    changeCourseState.value.show = false;
    fetchData();
  } catch (err) {
    notify.apiError(err, 'Không đổi được khóa học');
  } finally {
    savingCourseChange.value = false;
  }
};

// ---- Thời gian khóa học (nút "Sửa" trên thẻ khóa học) ----
const courseDatesState = ref({ key: null, student: null, item: null, start: '', perWeek: null, end: '', endTouched: false });
const savingCourseDates = ref(false);

const toIsoDate = (val) => (val ? String(val).slice(0, 10) : '');

// Ngày kết thúc dự kiến = buổi học cuối cùng trong tuần cuối:
// số tuần = tổng buổi ÷ buổi/tuần (làm tròn lên); trong tuần cuối lấy ngày học muộn nhất theo lịch học của học viên.
const computeCourseEndDate = (startIso, totalSessions, perWeek, classDays) => {
  if (!startIso || !totalSessions || !perWeek) return '';
  const weeks = Math.ceil(totalSessions / perWeek);
  const lastWeekStart = new Date(`${startIso}T00:00:00`);
  lastWeekStart.setDate(lastWeekStart.getDate() + (weeks - 1) * 7);
  const days = (classDays || []).map(Number);
  for (let offset = 6; offset >= 0; offset--) {
    const d = new Date(lastWeekStart);
    d.setDate(d.getDate() + offset);
    if (days.length === 0 || days.includes(d.getDay())) return toLocalDateString(d);
  }
  return '';
};

const coursePerWeek = (student, courseItem) =>
  Number(courseItem.sessions_per_week) || courseSlots(student, courseItem).length || 1;

// Ngày kết thúc hiển thị trên thẻ khóa: ngày đã lưu, nếu chưa có thì tự tính.
const courseEndDate = (student, courseItem) =>
  toIsoDate(courseItem.end_date || student.end_date) ||
  computeCourseEndDate(
    toIsoDate(courseItem.start_date || student.join_date),
    Number(courseItem.total_sessions),
    coursePerWeek(student, courseItem),
    courseSlots(student, courseItem).map((x) => x.day),
  );

const openCourseDates = (student, courseItem) => {
  const key = `${student.id}_${courseItem.id}`;
  if (courseDatesState.value.key === key) {
    courseDatesState.value.key = null;
    return;
  }
  const perWeek = Number(courseItem.sessions_per_week) || (courseSlots(student, courseItem).length || null);
  courseDatesState.value = {
    key,
    student,
    item: courseItem,
    start: toIsoDate(courseItem.start_date || student.join_date),
    perWeek,
    end: courseEndDate(student, courseItem),
    endTouched: !!(courseItem.end_date || student.end_date),
  };
};

const recomputeCourseEnd = () => {
  const st = courseDatesState.value;
  if (st.endTouched || !st.item) return;
  st.end = computeCourseEndDate(st.start, Number(st.item.total_sessions), Number(st.perWeek), courseSlots(st.student, st.item).map((x) => x.day));
};

const submitCourseDates = async () => {
  const { student, item, start, perWeek, end } = courseDatesState.value;
  try {
    savingCourseDates.value = true;
    const res = await studentApi.updateCourseItem(student.id, item.id, {
      start_date: start || null,
      sessions_per_week: perWeek ? Number(perWeek) : null,
      end_date: end || null,
    });
    notify.success('Đã lưu thời gian khóa học!');
    if (res.data.student) Object.assign(student, res.data.student);
    courseDatesState.value.key = null;
    fetchData();
  } catch (err) {
    notify.apiError(err, 'Không lưu được thời gian khóa học');
  } finally {
    savingCourseDates.value = false;
  }
};

const deleteStudentCourse = async (student, courseItem) => {
  const allCourses = getStudentCourses(student);
  if (allCourses.length <= 1) {
    notify.error('Học viên phải có ít nhất 1 khóa học.');
    return;
  }
  if (!confirm(`Bạn có chắc chắn muốn xóa khóa học "${courseItem.course_name}" của học viên ${student.name}?`)) {
    return;
  }
  try {
    const res = await studentApi.deleteCourseItem(student.id, courseItem.id);
    notify.success(res.data.message || 'Đã xóa khóa học thành công!');
    if (res.data.student) {
      Object.assign(student, res.data.student);
    }
    fetchData();
  } catch (err) {
    notify.apiError(err, 'Lỗi khi xóa khóa học');
  }
};

const openRetentionNote = (st) => {
  activeActionStudent.value = st;
  showNoteModal.value = true;
};

// Thu hồi học cụ giao nhầm
const returningSupply = ref(null);
const openReturnSupply = (student, supply) => {
  returningSupply.value = { student, supply };
};

const openRenew = (st) => {
  activeActionStudent.value = st;
  showRenewModal.value = true;
};

const openAbsence = (st) => {
  activeActionStudent.value = st;
  absenceNote.value = '';
  absenceWithLeave.value = true;
  showAbsenceModal.value = true;
};

const openParentPortal = (st) => {
  activeActionStudent.value = st;
  showParentPortalModal.value = true;
};

const submitAbsence = async () => {
  if (!activeActionStudent.value) return;
  try {
    isSubmittingAbsence.value = true;
    await studentApi.recordAbsence(activeActionStudent.value.id, {
      with_leave: absenceWithLeave.value,
      note: absenceNote.value,
    });
    showAbsenceModal.value = false;
    notify.success('Đã ghi nhận vắng học!');
    fetchData();
  } catch (err) {
    notify.apiError(err, 'Lỗi khi ghi nhận vắng');
  } finally {
    isSubmittingAbsence.value = false;
  }
};

const activeParentPortalUrl = computed(() => {
  if (!activeActionStudent.value?.id) return '';
  return `${window.location.origin}/portal/${activeActionStudent.value.id}`;
});

const copyParentPortalLink = () => {
  if (!activeParentPortalUrl.value) return;
  navigator.clipboard?.writeText(activeParentPortalUrl.value);
  copiedPortalLink.value = true;
  notify.success('Đã sao chép link Cổng phụ huynh!');
  setTimeout(() => {
    copiedPortalLink.value = false;
  }, 2500);
};

const openStudentDetail = (id) => {
  selectedDetailStudentId.value = id;
  showDetailModal.value = true;
};

const newStudentForm = ref({
  name: '',
  dob: '',
  gender: 'Nam',
  parent_name: '',
  parent_phone: '',
  parent_zalo: '',
  parent_email: '',
  course_id: '',
  total_sessions: 12,
  status: 'active',
  join_date: toLocalDateString(),
  notes: '',
  create_invoice: true,
  invoice_amount: 3600000,
  // Mặc định chưa đóng: phiếu chờ thu, không tính doanh thu, cổng phụ huynh hiện "Chưa thanh toán"
  invoice_paid: false,
  center: '',
  schedules: [],
});
const { centerNames, defaultCenter, loadCenters } = useCenters();

const fetchData = async () => {
  try {
    const res = await studentApi.getAll({
      search: search.value,
      phone: phoneSearch.value,
      center: centerFilter.value,
      course_id: courseId.value,
      status: status.value,
      sessions_filter: sessionsFilter.value,
      tuition_status: tuitionFilter.value,
      alert: alertFilter.value,
      attendance_status: attendanceStatusFilter.value,
      assigned_class: assignedClassFilter.value,
      teacher: teacherFilter.value,
      staff: staffFilter.value,
      sort_by: sortFilter.value,
      page: page.value,
      per_page: perPage.value,
    });
    pagination.value = res.data.pagination || null;
    // Trang hiện tại không còn dữ liệu (ví dụ vừa xoá dòng cuối) thì lùi về trang cuối
    if (pagination.value && page.value > pagination.value.last_page && pagination.value.last_page >= 1) {
      page.value = pagination.value.last_page;
      return fetchData();
    }
    students.value = res.data.students || [];
    courses.value = res.data.courses || [];
    if (res.data.centers) availableCenters.value = res.data.centers;
    if (res.data.teachers) availableTeachers.value = res.data.teachers;
    if (res.data.staff_members) availableStaff.value = res.data.staff_members;
    if (res.data.classes) availableClasses.value = res.data.classes;
    if (courses.value.length > 0 && !newStudentForm.value.course_id) {
      newStudentForm.value.course_id = courses.value[0].id;
    }
  } catch (err) {
    console.error('Error fetching students: ', err);
  }
};

const handleDownloadTemplate = async () => {
  try {
    const res = await studentApi.downloadTemplate();
    const blob = new Blob([res.data], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
    const url = window.URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.setAttribute('download', 'mau_nhap_hoc_vien.xlsx');
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    window.URL.revokeObjectURL(url);
    notify.success('Đã tải file mẫu. Mở file xem sheet "Hướng dẫn" trước khi điền.');
  } catch (err) {
    console.error('Error downloading template:', err);
    notify.error('Không thể tải file mẫu. Vui lòng thử lại!');
  }
};

// Đổi bộ lọc thì quay về trang 1
const applyFilters = () => {
  page.value = 1;
  fetchData();
};

const changePage = (p) => {
  page.value = p;
  fetchData();
};

const changePerPage = (n) => {
  perPage.value = n;
  applyFilters();
};

onMounted(() => {
  fetchData();
  loadCenters().then(() => {
    newStudentForm.value.center ||= defaultCenter.value;
  });
  if (route.query.create === 'true') {
    showCreateModal.value = true;
  }
});

watch(
  () => route.query.create,
  (val) => {
    if (val === 'true') {
      showCreateModal.value = true;
    }
  }
);

const courseOptions = computed(() => {
  return courses.value.map((c) => ({
    id: c.id,
    name: `${c.name} (${formatVND(c.price)})`,
    rawName: c.name,
    price: c.price,
    default_sessions: c.default_sessions,
  }));
});

const onCourseChange = (courseOpt) => {
  const cId = courseOpt?.id || Number(newStudentForm.value.course_id);
  const selectedCourse = courses.value.find((c) => c.id === cId);
  if (selectedCourse) {
    newStudentForm.value.total_sessions = selectedCourse.default_sessions || 12;
    newStudentForm.value.invoice_amount = selectedCourse.price || 3600000;
  }
};

const openSupplyModal = (student) => {
  selectedSupplyStudent.value = student;
  showSupplyModal.value = true;
};

// Cảnh báo trùng lịch / vượt sức chứa phòng (vẫn lưu, chỉ nhắc để sắp xếp lại)
const showScheduleWarnings = (warnings) => {
  (warnings || []).forEach((w) => notify.warning(w, { duration: 10000 }));
};

const submitCreateStudent = async () => {
  if (!newStudentForm.value.name || !newStudentForm.value.parent_name || !newStudentForm.value.parent_phone) {
    notify.warning('Vui lòng điền đầy đủ Tên học viên, Tên phụ huynh và Số điện thoại');
    return;
  }

  try {
    isSubmitting.value = true;
    const res = await studentApi.create(newStudentForm.value);
    showScheduleWarnings(res.data.warnings);
    showCreateModal.value = false;
    newStudentForm.value = {
      name: '',
      dob: '',
      gender: 'Nam',
      parent_name: '',
      parent_phone: '',
      parent_zalo: '',
      parent_email: '',
      course_id: courses.value[0]?.id || '',
      total_sessions: 12,
      status: 'active',
      join_date: toLocalDateString(),
      notes: '',
      create_invoice: true,
      invoice_amount: 3600000,
      invoice_paid: false,
      center: defaultCenter.value,
      schedules: [],
    };
    applyFilters();
  } catch (err) {
    notify.apiError(err, 'Có lỗi xảy ra khi tạo học viên');
  } finally {
    isSubmitting.value = false;
  }
};

const openEditStudent = (student) => {
  // Sửa trên bản sao danh sách ca (lịch nhiều ca)
  editingStudent.value = { ...student, schedules: (student.schedule_slots || []).map((slot) => ({ ...slot })) };
  showEditModal.value = true;
};

const submitEditStudent = async () => {
  if (!editingStudent.value) return;
  try {
    isSubmitting.value = true;
    const res = await studentApi.update(editingStudent.value.id, editingStudent.value);
    showScheduleWarnings(res.data.warnings);
    showEditModal.value = false;
    fetchData();
  } catch (err) {
    notify.apiError(err, 'Có lỗi xảy ra khi cập nhật');
  } finally {
    isSubmitting.value = false;
  }
};

const deleteStudent = async (student) => {
  if (await confirmDialog({
    title: 'Xoá học viên?',
    message: `Học viên "${student.name}" sẽ bị xoá khỏi hệ thống.`,
    confirmText: 'Xoá học viên',
  })) {
    try {
      await studentApi.delete(student.id);
      fetchData();
    } catch (err) {
      notify.apiError(err, 'Không thể xóa');
    }
  }
};


const formatVND = (val) => {
  return new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(val || 0);
};

const getDotTooltip = (dot, idx) => {
  if (dot.status === 'present') {
    return `Buổi ${idx + 1}: Có mặt ${dot.date ? '(' + dot.date + ')' : ''}`;
  }
  if (dot.status === 'absent') {
    return `Buổi ${idx + 1}: Vắng mặt ${dot.date ? '(' + dot.date + ')' : ''}`;
  }
  if (dot.status === 'excused') {
    return `Buổi ${idx + 1}: Nghỉ có phép ${dot.date ? '(' + dot.date + ')' : ''}`;
  }
  return `Buổi ${idx + 1}: Chưa diễn ra`;
};

const getAttendanceSummaryTooltip = (student) => {
  const titles = {
    excellent: 'Chuyên cần xuất sắc (Chuỗi đi học đầy đủ)',
    good: 'Chuyên cần tốt',
    attention: 'Cần quan tâm (Vắng 1 buổi gần đây)',
    at_risk: 'Nguy cơ nghỉ học (Vắng 2 buổi liên tiếp trở lên)',
    returned: 'Đã quay lại học đều',
  };
  return titles[student.attendance_status] || 'Lịch sử 5 buổi gần nhất';
};
</script>

<template>
  <div class="space-y-5 w-full">
    <!-- Header matching Senyapp style -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 bg-white p-4 sm:p-5 rounded-2xl border border-slate-200 shadow-2xs">
      <div>
        <h1 class="text-xl sm:text-2xl font-black tracking-tight text-slate-900 flex items-center gap-2">
          Học viên <span class="text-sm font-semibold text-slate-400">({{ pagination?.total ?? students.length }})</span>
        </h1>
      </div>

      <div class="flex items-center gap-2 flex-wrap">
        <!-- Mẫu Excel button -->
        <button
          type="button"
          @click="handleDownloadTemplate"
          class="inline-flex items-center gap-1.5 h-10 px-3.5 rounded-xl border border-slate-200 bg-white text-slate-700 text-sm font-semibold hover:bg-slate-50 transition-colors shadow-2xs active:scale-[0.98]"
        >
          <Download class="h-4 w-4 text-slate-500" />
          <span>Mẫu Excel</span>
        </button>

        <!-- Import Excel/CSV button -->
        <button
          type="button"
          @click="showImportModal = true"
          class="inline-flex items-center gap-1.5 h-10 px-3.5 rounded-xl border border-slate-200 bg-white text-slate-700 text-sm font-semibold hover:bg-slate-50 transition-colors shadow-2xs active:scale-[0.98]"
        >
          <UploadCloud class="h-4 w-4 text-slate-500" />
          <span>Import Excel</span>
        </button>

        <!-- + Học viên mới button -->
        <button
          type="button"
          @click="showCreateModal = true"
          class="inline-flex items-center gap-2 h-10 rounded-xl bg-rose-600 px-4 text-sm font-bold text-white shadow-xs shadow-rose-600/20 hover:bg-rose-700 transition-all active:scale-[0.98]"
        >
          <UserPlus class="h-4 w-4" />
          <span>+ Học viên mới</span>
        </button>
      </div>
    </div>

    <!-- Filters: 2 Clean Rows (Matching SenyApp) -->
    <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-2xs space-y-3">
      <!-- Row 1: Tên học viên, Điện thoại, Tất cả trung tâm, Tất cả trạng thái, Tất cả khóa học, Tất cả số buổi khoá học -->
      <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
        <!-- Tên học viên -->
        <div class="relative">
          <input
            type="text"
            v-model="search"
            @keyup.enter="applyFilters"
            @change="applyFilters"
            placeholder="Tên học viên"
            class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-sm font-normal text-slate-800 placeholder-slate-400 focus:border-indigo-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20 transition-all"
          />
        </div>

        <!-- Điện thoại -->
        <div class="relative">
          <input
            type="text"
            v-model="phoneSearch"
            @keyup.enter="applyFilters"
            @change="applyFilters"
            placeholder="Điện thoại"
            class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-sm font-normal text-slate-800 placeholder-slate-400 focus:border-indigo-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20 transition-all"
          />
        </div>

        <!-- Tất cả trung tâm (Tìm kiếm & Chọn từ dropdown) -->
        <div>
          <SearchableSelect
            v-model="centerFilter"
            :options="filterCenterOptions"
            label-key="name"
            value-key="id"
            placeholder="Tất cả trung tâm"
            @change="applyFilters"
          />
        </div>

        <!-- Tất cả trạng thái -->
        <div>
          <select
            v-model="status"
            @change="applyFilters"
            class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-sm font-normal text-slate-700 focus:border-indigo-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20 transition-all cursor-pointer"
          >
            <option value="all">Tất cả trạng thái</option>
            <option value="active">Đang học</option>
            <option value="paused">Tạm dừng</option>
            <option value="dropped">Đã nghỉ</option>
          </select>
        </div>

        <!-- Tất cả khóa học (Tìm kiếm & Chọn từ dropdown) -->
        <div>
          <SearchableSelect
            v-model="courseId"
            :options="filterCourseOptions"
            label-key="name"
            value-key="id"
            placeholder="Tất cả khóa học"
            @change="applyFilters"
          />
        </div>

        <!-- Tất cả số buổi khoá học -->
        <div>
          <select
            v-model="sessionsFilter"
            @change="applyFilters"
            class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-sm font-normal text-slate-700 focus:border-indigo-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20 transition-all cursor-pointer"
          >
            <option value="all">Tất cả số buổi khoá học</option>
            <option value="expiring">🔥 Sắp hết (&le; 5 buổi)</option>
            <option value="plenty">Còn nhiều (&gt; 5 buổi)</option>
          </select>
        </div>
      </div>

      <!-- Row 2: Tất cả thanh toán học cụ, Tất cả chuyên cần, Tất cả người phụ trách, Tất cả lớp học, Tất cả giáo viên, Sắp xếp theo tên -->
      <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 pt-1">
        <!-- Tất cả thanh toán học cụ -->
        <div>
          <select
            v-model="tuitionFilter"
            @change="applyFilters"
            class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-sm font-normal text-slate-700 focus:border-indigo-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20 transition-all cursor-pointer"
          >
            <option value="all">Tất cả thanh toán học cụ</option>
            <option value="paid">Đã thanh toán đủ</option>
            <option value="unpaid">Chưa thanh toán</option>
          </select>
        </div>

        <!-- Tất cả chuyên cần -->
        <div>
          <select
            v-model="attendanceStatusFilter"
            @change="applyFilters"
            class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-sm font-normal text-slate-700 focus:border-indigo-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20 transition-all cursor-pointer"
          >
            <option value="all">Tất cả chuyên cần</option>
            <option value="excellent">🔥 Chuyên cần xuất sắc</option>
            <option value="good">😊 Chuyên cần tốt</option>
            <option value="attention">⚠️ Cần quan tâm</option>
            <option value="at_risk">🚨 Nguy cơ nghỉ học</option>
            <option value="returned">💪 Đã quay lại học đều</option>
          </select>
        </div>

        <!-- Tất cả người phụ trách (Tìm kiếm & Chọn từ dropdown) -->
        <div>
          <SearchableSelect
            v-model="staffFilter"
            :options="filterStaffOptions"
            label-key="name"
            value-key="id"
            placeholder="Tất cả người phụ trách"
            @change="applyFilters"
          />
        </div>

        <!-- Tất cả lớp học (Tìm kiếm & Chọn từ dropdown) -->
        <div>
          <SearchableSelect
            v-model="assignedClassFilter"
            :options="filterClassOptions"
            label-key="name"
            value-key="id"
            placeholder="Tất cả lớp học"
            @change="applyFilters"
          />
        </div>

        <!-- Tất cả giáo viên (Tìm kiếm & Chọn từ dropdown) -->
        <div>
          <SearchableSelect
            v-model="teacherFilter"
            :options="filterTeacherOptions"
            label-key="name"
            value-key="id"
            placeholder="Tất cả giáo viên"
            @change="applyFilters"
          />
        </div>

        <!-- Sắp xếp theo tên -->
        <div>
          <select
            v-model="sortFilter"
            @change="applyFilters"
            class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-sm font-normal text-slate-700 focus:border-indigo-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20 transition-all cursor-pointer"
          >
            <option value="newest">Mới thêm gần nhất</option>
            <option value="name_asc">Sắp xếp theo tên (A-Z)</option>
            <option value="name_desc">Sắp xếp theo tên (Z-A)</option>
            <option value="sessions_asc">Buổi học còn ít nhất</option>
            <option value="sessions_desc">Buổi học còn nhiều nhất</option>
          </select>
        </div>
      </div>
    </div>

    <!-- Student Table Card -->
    <div class="rounded-2xl border border-slate-200 bg-white shadow-2xs overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-left text-sm min-w-[950px]">
          <thead class="bg-slate-50/90 text-slate-500 border-b border-slate-200/80 font-bold uppercase tracking-wider text-xs">
            <tr>
              <th class="py-4 px-4">Tên</th>
              <th class="py-4 px-4">Phụ huynh</th>
              <th class="py-4 px-4">Điện thoại</th>
              <th class="py-4 px-4 text-center">Số buổi</th>
              <th class="py-4 px-4 text-center">Vắng</th>
              <th class="py-4 px-4 text-center">Trạng thái</th>
              <th class="py-4 px-4 text-right">Thao tác</th>
              <th class="py-4 px-4">Người phụ trách</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100 text-slate-700">
            <template
              v-for="student in students"
              :key="student.id"
            >
              <tr
                @click="handleRowClick($event, student)"
                class="hover:bg-slate-50/80 transition-colors cursor-pointer select-none"
                :class="expandedStudentId === student.id ? 'bg-red-50/20' : ''"
              >
              <!-- Name, Course & Attendance Dots (SenyApp style) -->
              <td class="py-4 px-4">
                <div class="flex items-center gap-3">
                  <div class="min-w-0">
                    <button
                      type="button"
                      @click="openStudentDetail(student.id)"
                      class="font-black text-slate-900 hover:text-indigo-600 block text-sm sm:text-base tracking-tight truncate text-left cursor-pointer transition-colors"
                      title="Xem hồ sơ học viên"
                    >
                      {{ student.name }}
                    </button>
                    <span class="text-xs text-slate-400 block font-medium truncate mb-1">
                      {{ student.course?.name }}
                    </span>

                    <!-- Dãy 5 chấm điểm danh gần nhất & Biểu tượng Chuyên cần (Kích thước lớn rõ ràng theo chuẩn) -->
                    <div class="flex items-center gap-2 mt-1" :title="getAttendanceSummaryTooltip(student)">
                      <!-- Dãy 5 chấm -->
                      <div class="flex items-center gap-1.5 bg-slate-100/90 px-2.5 py-1 rounded-full border border-slate-200/80 shadow-2xs">
                        <span
                          v-for="(dot, idx) in (student.attendance_dots || [])"
                          :key="idx"
                          class="inline-block h-3.5 w-3.5 rounded-full transition-all hover:scale-125 cursor-help"
                          :class="[
                            dot.status === 'present' ? 'bg-emerald-500 shadow-2xs shadow-emerald-500/50' :
                            dot.status === 'absent' ? 'bg-rose-500 shadow-2xs shadow-rose-500/50' :
                            dot.status === 'excused' ? 'bg-amber-400 shadow-2xs shadow-amber-400/50' :
                            'bg-white border-2 border-slate-300'
                          ]"
                          :title="getDotTooltip(dot, idx)"
                        ></span>
                      </div>

                      <!-- Biểu tượng ngọn lửa / cảm xúc theo mức độ chuyên cần -->
                      <span
                        v-if="student.attendance_status === 'excellent'"
                        class="text-lg select-none cursor-help transition-transform hover:scale-125 inline-block leading-none"
                        title="Chuyên cần xuất sắc"
                      >
                        🔥
                      </span>
                      <span
                        v-else-if="student.attendance_status === 'good'"
                        class="text-base select-none cursor-help transition-transform hover:scale-125 inline-block leading-none"
                        title="Chuyên cần tốt"
                      >
                        😊
                      </span>
                      <span
                        v-else-if="student.attendance_status === 'attention'"
                        class="text-base select-none cursor-help transition-transform hover:scale-125 inline-block leading-none"
                        title="Cần quan tâm (Vắng 1 buổi gần đây)"
                      >
                        ⚠️
                      </span>
                      <span
                        v-else-if="student.attendance_status === 'at_risk'"
                        class="text-base select-none cursor-help transition-transform hover:scale-125 inline-block leading-none"
                        title="Nguy cơ nghỉ học (Vắng 2 buổi liên tiếp trở lên)"
                      >
                        🚨
                      </span>
                      <span
                        v-else-if="student.attendance_status === 'returned'"
                        class="text-base select-none cursor-help transition-transform hover:scale-125 inline-block leading-none"
                        title="Đã quay lại học đều"
                      >
                        💪
                      </span>
                    </div>
                  </div>
                </div>
              </td>

              <!-- Parent -->
              <td class="py-3.5 px-4 font-semibold text-slate-800">
                {{ student.parent_name }}
              </td>

              <!-- Số điện thoại phụ huynh (bấm để gọi) -->
              <td class="py-3.5 px-4 font-mono font-medium">
                <a
                  :href="`tel:${student.parent_phone}`"
                  class="text-blue-600 font-bold hover:underline"
                >
                  {{ student.parent_phone }}
                </a>
              </td>

              <!-- Sessions Progress Pill -->
              <td class="py-3.5 px-4 text-center whitespace-nowrap">
                <span
                  class="inline-block rounded-lg px-2.5 py-1 font-bold text-xs shadow-2xs"
                  :class="[
                    student.remaining_sessions <= 2
                      ? 'bg-red-100 text-red-700'
                      : student.remaining_sessions <= 5
                      ? 'bg-amber-100 text-amber-800'
                      : 'bg-emerald-100 text-emerald-800'
                  ]"
                >
                  {{ student.total_sessions - student.remaining_sessions }}/{{ student.total_sessions }} ({{ student.remaining_sessions }} còn lại)
                </span>
              </td>

              <!-- Absences -->
              <td class="py-3.5 px-4 text-center">
                <!-- Số buổi vắng liên tiếp gần nhất (không phải số buổi bù) -->
                <span v-if="student.consecutive_absences > 0" class="font-bold text-red-600"
                  :title="`Vắng ${student.consecutive_absences} buổi liên tiếp gần nhất, cần liên hệ / xếp học bù`">
                  {{ student.consecutive_absences }} <span class="font-medium text-[11px]">liên tiếp</span>
                </span>
                <span v-else class="text-slate-400 font-mono">0</span>
              </td>

              <!-- Status -->
              <td class="py-3.5 px-4 text-center">
                <Badge :variant="student.status === 'active' ? 'green' : student.status === 'paused' ? 'amber' : 'red'">
                  {{ student.status === 'active' ? 'Đang học' : student.status === 'paused' ? 'Tạm dừng' : 'Đã nghỉ' }}
                </Badge>
              </td>

              <!-- Actions matching Screenshot 1 exactly -->
              <td class="py-3.5 px-4 text-right">
                <div class="flex items-center justify-end gap-1">
                  <!-- Eye: Xem hồ sơ học viên dạng Modal -->
                  <button
                    type="button"
                    @click="openStudentDetail(student.id)"
                    class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-800 transition-colors"
                    title="Xem hồ sơ học viên (Modal)"
                  >
                    <Eye class="h-4 w-4" />
                  </button>

                  <!-- ExternalLink: Mở trang riêng chi tiết thông tin học viên -->
                  <router-link
                    :to="`/students/${student.id}`"
                    target="_blank"
                    class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-blue-600 transition-colors"
                    title="Mở trang chi tiết học viên ở tab mới"
                  >
                    <ExternalLink class="h-4 w-4" />
                  </router-link>

                  <!-- Edit3: Sửa thông tin -->
                  <button
                    @click="openEditStudent(student)"
                    class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-red-600 transition-colors"
                    title="Chỉnh sửa thông tin"
                  >
                    <Edit3 class="h-4 w-4" />
                  </button>

                  <!-- Package / Box: Thêm học cụ (Red box in Screenshot 1) -->
                  <button
                    @click="openSupplyModal(student)"
                    class="rounded-lg p-1.5 text-slate-500 hover:bg-red-50 hover:text-red-600 transition-colors"
                    title="Thêm học cụ cho học viên"
                  >
                    <Package class="h-4 w-4 text-slate-600 hover:text-red-600" />
                  </button>

                  <!-- Trash2: Xóa -->
                  <button
                    @click.stop="deleteStudent(student)"
                    class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-red-600 transition-colors"
                    title="Xóa học viên"
                  >
                    <Trash2 class="h-4 w-4" />
                  </button>
                </div>
              </td>

              <!-- Người phụ trách -->
              <td class="py-3.5 px-4 text-xs whitespace-nowrap">
                <template v-if="student.teacher">
                  <div class="font-bold text-slate-800">{{ student.teacher }}</div>
                  <div v-if="student.staff_email" class="text-slate-400 text-[11px] font-mono">{{ student.staff_email }}</div>
                </template>
                <template v-else>
                  <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[11px] font-medium bg-slate-100 text-slate-400">
                    Chưa gán
                  </span>
                </template>
              </td>
            </tr>

            <!-- DROPDOWN THÔNG TIN HỌC VIÊN KHI CLICK VÀO VÙNG TRỐNG (SenyApp style) -->
            <tr v-if="expandedStudentId === student.id" class="bg-[#fafbfc] border-y-2 border-slate-200">
              <td colspan="8" class="p-5 sm:p-7 space-y-6">
                <!-- 1. Ảnh học viên -->
                <div class="flex items-start gap-4">
                  <!-- Ảnh preview -->
                  <div class="flex h-24 w-24 shrink-0 items-center justify-center rounded-2xl border border-slate-200 bg-white shadow-2xs overflow-hidden">
                    <img
                      v-if="student.avatar && !failedAvatars.has(student.id)"
                      :src="getAvatarUrl(student.avatar)"
                      @error="handleAvatarError(student.id)"
                      class="h-full w-full object-cover"
                    />
                    <div v-else class="text-slate-400 text-xs font-medium text-center p-2 flex flex-col items-center justify-center h-full">
                      <User class="h-8 w-8 text-slate-300 mb-1" />
                      <span>Chưa có ảnh</span>
                    </div>
                  </div>

                  <div class="space-y-1.5">
                    <h4 class="text-sm font-bold text-slate-900">Ảnh học viên</h4>
                    <p class="text-xs text-slate-400">
                      Ảnh lớn sẽ được tự động nén xuống dưới 2MB. JPG/PNG.
                    </p>
                    <label class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 text-xs font-semibold text-slate-700 shadow-2xs cursor-pointer active:scale-95 transition-all">
                      <Upload class="h-3.5 w-3.5 text-slate-500" />
                      <span>Tải ảnh lên</span>
                      <input type="file" accept="image/*" class="hidden" @change="uploadAvatarFile($event, student)" />
                    </label>
                  </div>
                </div>

                <!-- 2. Tổng tiến độ -->
                <div class="space-y-2">
                  <div class="flex items-center justify-between text-xs sm:text-sm">
                    <span class="font-bold text-slate-900">Tổng tiến độ</span>
                    <span class="font-semibold text-slate-500 text-xs">
                      {{ student.total_sessions - student.remaining_sessions }} / {{ student.total_sessions }} buổi &bull; còn {{ student.remaining_sessions }}
                    </span>
                  </div>

                  <!-- Coral / Red Progress Bar matching Screenshot -->
                  <div class="w-full bg-red-100/60 rounded-full h-2.5 overflow-hidden">
                    <div
                      class="h-full bg-red-500 rounded-full transition-all duration-500"
                      :style="{ width: `${getStudentProgress(student)}%` }"
                    ></div>
                  </div>
                </div>

                <!-- 3. Khóa học đã đăng ký -->
                <div class="space-y-3">
                  <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-3">
                    <div>
                      <h4 class="text-sm font-bold text-slate-900">Khóa học đã đăng ký</h4>
                      <p class="text-xs text-slate-400 mt-0.5">
                        Tổng đã mua: {{ student.total_sessions }} &bull; Đã điểm danh: {{ student.total_sessions - student.remaining_sessions }} &bull; Còn lại: {{ student.remaining_sessions }}
                      </p>
                    </div>

                    <!-- Dãy nút hành động chuẩn SenyApp -->
                    <div class="flex flex-wrap items-center gap-2 text-xs">
                      <button
                        type="button"
                        @click="toggleHideCompleted(student.id)"
                        class="h-8 w-8 rounded-xl border flex items-center justify-center shadow-2xs transition-colors"
                        :class="hideCompletedCourses[student.id] ? 'bg-red-50 border-red-300 text-red-600' : 'bg-white border-slate-200 text-slate-400 hover:bg-slate-50'"
                        :title="hideCompletedCourses[student.id] ? 'Đang ẩn khóa hoàn thành (Bấm để hiện tất cả)' : 'Đang hiện tất cả khóa (Bấm để ẩn khóa hoàn thành)'"
                      >
                        <span
                          class="h-3 w-3 rounded-full transition-all"
                          :class="hideCompletedCourses[student.id] ? 'bg-red-500 ring-2 ring-red-200' : 'border-2 border-red-500'"
                        ></span>
                      </button>

                      <button
                        type="button"
                        @click="openRetentionNote(student)"
                        class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 px-3 py-1.5 font-semibold text-slate-700 shadow-2xs active:scale-95 transition-all"
                      >
                        <MessageSquare class="h-3.5 w-3.5 text-slate-500" />
                        <span>Nhận xét</span>
                      </button>

                      <!-- Đã đóng học phí và còn nhiều buổi: hiện "Đã đóng HP" (bấm vẫn mở, có cảnh báo thu trùng) -->
                      <button
                        type="button"
                        @click="openRenew(student)"
                        :title="{ pending: 'Phụ huynh chưa đóng học phí, bấm để xác nhận đã thu', paid: 'Học viên đã đóng học phí, chỉ thu tiếp khi gia hạn', due: 'Thu học phí / gia hạn' }[tuitionState(student)]"
                        :class="['inline-flex items-center gap-1.5 rounded-xl border px-3 py-1.5 font-semibold shadow-2xs active:scale-95 transition-all', TUITION_BUTTON[tuitionState(student)].class]"
                      >
                        <CreditCard class="h-3.5 w-3.5" :class="TUITION_BUTTON[tuitionState(student)].icon" />
                        <span>{{ TUITION_BUTTON[tuitionState(student)].label }}</span>
                      </button>

                      <button
                        type="button"
                        @click="openStudentDetail(student.id)"
                        class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 px-3 py-1.5 font-semibold text-slate-700 shadow-2xs active:scale-95 transition-all"
                      >
                        <Calendar class="h-3.5 w-3.5 text-slate-500" />
                        <span>Ngày đã học</span>
                      </button>

                      <button
                        type="button"
                        @click="openAbsence(student)"
                        class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 px-3 py-1.5 font-semibold text-slate-700 shadow-2xs active:scale-95 transition-all"
                      >
                        <UserX class="h-3.5 w-3.5 text-slate-500" />
                        <span>Vắng</span>
                      </button>

                      <button
                        type="button"
                        @click="openParentPortal(student)"
                        class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 px-3 py-1.5 font-semibold text-slate-700 shadow-2xs active:scale-95 transition-all"
                      >
                        <Share2 class="h-3.5 w-3.5 text-slate-500" />
                        <span>Cổng phụ huynh</span>
                      </button>

                      <button
                        type="button"
                        @click="toggleAddCourseForm(student)"
                        class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 px-3 py-1.5 font-semibold text-slate-700 shadow-2xs active:scale-95 transition-all"
                        :class="showAddCourseForm[student.id] ? 'bg-slate-100 ring-2 ring-red-500/20 text-red-600' : ''"
                      >
                        <Plus class="h-3.5 w-3.5 text-slate-500" />
                        <span>Thêm khóa học</span>
                      </button>
                    </div>
                  </div>

                  <!-- FORM THÊM KHÓA HỌC INLINE (Khớp ảnh 3) -->
                  <div
                    v-if="showAddCourseForm[student.id]"
                    class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5 shadow-2xs space-y-4 animate-in fade-in duration-200"
                  >
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3.5">
                      <!-- 1. Khóa học -->
                      <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1.5">Khóa học</label>
                        <select
                          v-model="getAddCourseForm(student.id).course_id"
                          class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-800 focus:border-red-500 focus:ring-1 focus:ring-red-500 focus:outline-none transition-all"
                        >
                          <option value="" disabled>Chọn khóa học</option>
                          <option v-for="c in courses" :key="c.id" :value="c.id">
                            {{ c.name }}
                          </option>
                        </select>
                      </div>

                      <!-- 2. Tổng số buổi -->
                      <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1.5">Tổng số buổi</label>
                        <input
                          type="number"
                          min="1"
                          v-model="getAddCourseForm(student.id).total_sessions"
                          @input="recomputeAddCourseEnd(student)"
                          class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-800 focus:border-red-500 focus:ring-1 focus:ring-red-500 focus:outline-none transition-all"
                        />
                      </div>

                      <!-- 3. Ngày bắt đầu -->
                      <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1.5">Ngày bắt đầu</label>
                        <input
                          type="date"
                          v-model="getAddCourseForm(student.id).start_date"
                          @input="recomputeAddCourseEnd(student)"
                          class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-800 focus:border-red-500 focus:ring-1 focus:ring-red-500 focus:outline-none transition-all"
                        />
                      </div>

                      <!-- 4. Buổi/tuần (tuỳ chọn) -->
                      <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1.5">Buổi/tuần (tuỳ chọn)</label>
                        <input
                          type="text"
                          placeholder="vd: 2"
                          v-model="getAddCourseForm(student.id).sessions_per_week"
                          @input="recomputeAddCourseEnd(student)"
                          class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-800 focus:border-red-500 focus:ring-1 focus:ring-red-500 focus:outline-none transition-all"
                        />
                      </div>

                      <!-- 5. Ngày kết thúc dự kiến -->
                      <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1.5">Ngày kết thúc dự kiến</label>
                        <input
                          type="date"
                          placeholder="dd/mm/yyyy"
                          v-model="getAddCourseForm(student.id).end_date"
                          @input="getAddCourseForm(student.id).end_touched = !!getAddCourseForm(student.id).end_date"
                          class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-800 focus:border-red-500 focus:ring-1 focus:ring-red-500 focus:outline-none transition-all"
                        />
                      </div>
                    </div>

                    <!-- Nút Thêm (đỏ nổi bật góc phải như ảnh 3) -->
                    <div class="flex items-center justify-end gap-2 pt-1 border-t border-slate-100">
                      <button
                        type="button"
                        @click="showAddCourseForm[student.id] = false"
                        class="px-4 py-2 rounded-xl border border-slate-200 text-slate-600 hover:bg-slate-50 text-xs font-semibold transition-all"
                      >
                        Hủy
                      </button>
                      <button
                        type="button"
                        @click="submitAddCourse(student)"
                        :disabled="isSubmittingCourse[student.id]"
                        class="px-6 py-2 rounded-xl bg-red-600 hover:bg-red-700 active:scale-95 text-white font-bold text-xs shadow-2xs transition-all disabled:opacity-50"
                      >
                        {{ isSubmittingCourse[student.id] ? 'Đang thêm...' : 'Thêm' }}
                      </button>
                    </div>
                  </div>

                  <!-- Danh sách các thẻ khóa học chi tiết -->
                  <div class="space-y-3">
                    <div
                      v-for="(cItem, cIdx) in getVisibleCourses(student)"
                      :key="cItem.id || cIdx"
                      class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5 shadow-2xs space-y-3.5 transition-all"
                    >
                      <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                          <div class="flex flex-col text-slate-400">
                            <ArrowUp class="h-3 w-3" />
                            <ArrowDown class="h-3 w-3 -mt-0.5" />
                          </div>
                          <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-amber-100/70 border border-amber-200 text-amber-900 font-bold text-xs">
                            <span>{{ cItem.course_name || student.course?.name || 'Khóa học' }}</span>
                            <Edit3 class="h-3 w-3 text-slate-400 cursor-pointer hover:text-slate-700" title="Đổi khóa học" @click="openChangeCourse(student, cItem)" />
                          </span>
                        </div>

                        <span class="text-xs font-bold text-slate-500 font-mono">
                          {{ cItem.attended_sessions ?? (cItem.total_sessions - cItem.remaining_sessions) }} / {{ cItem.total_sessions }}
                        </span>
                      </div>

                      <!-- Subline: Dates & Actions -->
                      <div class="relative flex flex-wrap items-center gap-3 text-xs text-slate-500">
                        <div class="flex items-center gap-1">
                          <Calendar class="h-3.5 w-3.5 text-slate-400" />
                          <span>
                            Bắt đầu {{ formatDateDMY(cItem.start_date || student.join_date) }} &bull; Kết thúc dự kiến {{ formatDateDMY(courseEndDate(student, cItem)) }} &bull; {{ cItem.schedule_info || student.schedule_info || '1 buổi/tuần' }}
                            <template v-if="cItem.schedule_ids?.length"> &bull; Ca: {{ courseSlots(student, cItem).map(slotLabel).join(', ') }}</template>
                          </span>
                        </div>
                        <button
                          type="button"
                          @click="openCourseDates(student, cItem)"
                          title="Sửa ngày bắt đầu, số buổi/tuần, ngày kết thúc dự kiến"
                          class="text-slate-500 hover:text-slate-800 flex items-center gap-0.5 font-medium"
                        >
                          <Edit3 class="h-3 w-3" />
                          <span>Sửa</span>
                        </button>

                        <!-- Popup thời gian khóa học -->
                        <div
                          v-if="courseDatesState.key === `${student.id}_${cItem.id}`"
                          class="absolute right-0 top-full z-30 mt-2 w-80 rounded-2xl border border-slate-200 bg-white p-4 shadow-xl space-y-3 text-slate-700"
                        >
                          <div class="text-xs font-semibold">
                            Ngày bắt đầu
                            <DatePickerVN v-model="courseDatesState.start" :default-today="false" class="mt-1" @change="recomputeCourseEnd" />
                          </div>
                          <label class="block text-xs font-semibold">
                            Buổi/tuần <span class="font-normal text-slate-400">(tuỳ chọn)</span>
                            <input
                              v-model.number="courseDatesState.perWeek"
                              type="number"
                              min="1"
                              max="14"
                              @input="recomputeCourseEnd"
                              class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm text-slate-900 focus:border-rose-500 focus:outline-none"
                            />
                          </label>
                          <div class="text-xs font-semibold">
                            Ngày kết thúc dự kiến
                            <DatePickerVN v-model="courseDatesState.end" :default-today="false" class="mt-1" @change="courseDatesState.endTouched = true" />
                          </div>
                          <p class="text-[11px] leading-relaxed text-slate-500">
                            Tự tính từ tổng {{ cItem.total_sessions }} buổi ÷ buổi/tuần, và lấy đúng buổi học cuối cùng trong tuần cuối
                            (theo lịch học của học viên). Sửa tay sẽ giữ nguyên ngày bạn chọn.
                            <button
                              v-if="courseDatesState.endTouched"
                              type="button"
                              @click="courseDatesState.endTouched = false; recomputeCourseEnd()"
                              class="font-semibold text-rose-600 hover:underline"
                            >
                              Tính lại tự động
                            </button>
                          </p>
                          <div class="flex justify-end gap-2">
                            <button type="button" @click="courseDatesState.key = null" class="rounded-xl border border-slate-200 px-4 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                              Huỷ
                            </button>
                            <button
                              type="button"
                              :disabled="savingCourseDates"
                              @click="submitCourseDates"
                              class="rounded-xl bg-rose-600 hover:bg-rose-700 disabled:opacity-50 px-4 py-1.5 text-xs font-bold text-white"
                            >
                              Lưu
                            </button>
                          </div>
                        </div>
                      </div>

                      <!-- Blue progress bar matching Screenshot -->
                      <div class="w-full bg-red-100/60 rounded-full h-2 overflow-hidden">
                        <div
                          class="h-full bg-sky-500 rounded-full transition-all duration-500"
                          :style="{ width: `${getCourseProgress(cItem)}%` }"
                        ></div>
                      </div>

                      <!-- Inline Editors: [ Attended ] [ Lưu ] | [ Total ] [ Lưu ] | [ 📅 ] | [ 🗑️ ] -->
                      <div class="flex flex-wrap items-end gap-3 text-xs pt-1">
                        <div class="space-y-0.5" title="Số buổi học viên đã học thực tế của khóa này. Sửa khi cần điều chỉnh tay (ví dụ chuyển từ sổ cũ sang); số buổi còn lại tự tính = Tổng buổi − Đã học.">
                          <span class="text-[10px] font-semibold text-slate-500">Đã học (buổi)</span>
                          <div class="flex items-center gap-1">
                          <input
                            type="number"
                            v-model="getCourseAttendedInput(student, cItem).value"
                            aria-label="Số buổi đã học"
                            min="0"
                            class="w-14 rounded-lg border border-slate-300 bg-white px-2 py-1 text-center font-bold text-slate-900 focus:border-sky-500 focus:outline-none"
                          />
                          <button
                            type="button"
                            @click="saveCourseAttended(student, cItem)"
                            title="Lưu số buổi đã học"
                            class="rounded-lg bg-sky-500 px-3 py-1 font-bold text-white hover:bg-sky-600 transition-colors shadow-2xs"
                          >
                            Lưu
                          </button>
                          </div>
                        </div>

                        <div class="space-y-0.5" title="Tổng số buổi học viên đã mua / đăng ký cho khóa này.">
                          <span class="text-[10px] font-semibold text-slate-500">Tổng buổi đã mua</span>
                          <div class="flex items-center gap-1">
                          <input
                            type="number"
                            v-model="getCourseTotalInput(student, cItem).value"
                            aria-label="Tổng số buổi đã mua"
                            min="1"
                            class="w-14 rounded-lg border border-slate-300 bg-white px-2 py-1 text-center font-bold text-slate-900 focus:border-emerald-500 focus:outline-none"
                          />
                          <button
                            type="button"
                            @click="saveCourseTotal(student, cItem)"
                            title="Lưu tổng số buổi"
                            class="rounded-lg bg-emerald-500 px-3 py-1 font-bold text-white hover:bg-emerald-600 transition-colors shadow-2xs"
                          >
                            Lưu
                          </button>
                          </div>
                        </div>

                        <div class="relative">
                          <button
                            type="button"
                            @click="openSlotPicker(student, cItem)"
                            :class="[
                              cItem.schedule_ids?.length ? 'bg-amber-400 text-slate-900 hover:bg-amber-500' : 'text-slate-400 hover:text-slate-600 hover:bg-slate-50',
                              'p-1.5 rounded-lg transition-colors',
                            ]"
                            title="Chọn lịch cho trang điểm danh"
                          >
                            <Calendar class="h-4 w-4" />
                          </button>
                          <!-- Popup chọn ca của khoá -->
                          <div
                            v-if="slotPicker.key === `${student.id}_${cItem.id}`"
                            class="absolute right-0 top-full z-30 mt-2 w-80 rounded-2xl border border-slate-200 bg-white p-4 shadow-xl space-y-3 text-left"
                          >
                            <p class="text-sm font-bold text-slate-900">Chọn lịch cho trang điểm danh</p>
                            <p class="text-[11px] leading-relaxed text-slate-500">
                              Chọn các ca của khoá này: điểm danh ca nào sẽ trừ buổi vào khoá đó. Không chọn ca nào thì khoá
                              dùng chung mọi ca (như hiện tại).
                            </p>
                            <p v-if="!(student.schedule_slots || []).length" class="text-xs text-slate-400">
                              Học viên chưa có lịch học. Bấm sửa học viên để thêm ca.
                            </p>
                            <label
                              v-for="slot in student.schedule_slots || []"
                              :key="slot.id"
                              class="flex items-center gap-2 text-sm text-slate-800 cursor-pointer"
                            >
                              <input
                                type="checkbox"
                                :checked="slotPicker.ids.includes(slot.id)"
                                @change="toggleSlotPick(slot.id)"
                                class="h-4 w-4 rounded border-slate-300 text-rose-600 focus:ring-rose-500"
                              />
                              <span>{{ slotLabel(slot) }}</span>
                              <span v-if="slot.teacher" class="text-xs text-slate-400">· {{ slot.teacher }}</span>
                            </label>
                            <div class="flex justify-end gap-2 pt-1">
                              <button type="button" @click="slotPicker = { key: null, ids: [] }" class="rounded-xl border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                Huỷ
                              </button>
                              <button
                                type="button"
                                :disabled="savingSlotPicker"
                                @click="saveSlotPicker(student, cItem)"
                                class="rounded-xl bg-rose-600 hover:bg-rose-700 disabled:opacity-50 px-3 py-1.5 text-xs font-bold text-white"
                              >
                                Lưu
                              </button>
                            </div>
                          </div>
                        </div>
                        <button
                          type="button"
                          @click="deleteStudentCourse(student, cItem)"
                          class="p-1.5 text-slate-400 hover:text-rose-600 rounded-lg hover:bg-rose-50 transition-colors"
                          title="Xóa khóa học này"
                        >
                          <Trash2 class="h-4 w-4" />
                        </button>
                      </div>

                      <!-- Absences row: Đã vắng có phép 0/2 [ 2 ] [ Lưu ] -->
                      <div class="flex items-end gap-2 text-xs pt-1">
                        <span class="rounded-md bg-red-50 border border-red-200 px-2.5 py-1 font-bold text-red-700 text-[11px]" title="Đã dùng / tối đa số buổi vắng có phép">
                          Đã vắng có phép {{ cItem.absences_used || 0 }}/{{ cItem.allowed_absences ?? 3 }}
                        </span>
                        <div class="space-y-0.5" title="Số buổi được phép vắng có phép tối đa của khóa này. Nhãn bên cạnh cho biết đã dùng bao nhiêu buổi.">
                          <span class="text-[10px] font-semibold text-slate-500">Số buổi được vắng có phép</span>
                          <div class="flex items-center gap-1">
                        <input
                          type="number"
                          v-model="getCourseAllowedAbsencesInput(student, cItem).value"
                          aria-label="Số buổi được vắng có phép"
                          min="0"
                          class="w-14 rounded-lg border border-slate-300 bg-white px-2 py-1 text-center font-bold text-slate-900 focus:border-sky-500 focus:outline-none"
                        />
                        <button
                          type="button"
                          @click="saveCourseAllowedAbsences(student, cItem)"
                          title="Lưu số buổi được vắng có phép"
                          class="rounded-lg bg-sky-500 px-3 py-1 font-bold text-white hover:bg-sky-600 transition-colors shadow-2xs"
                        >
                          Lưu
                        </button>
                          </div>
                        </div>
                      </div>
                    </div>

                    <!-- Khi tất cả khóa hoàn thành đang bị ẩn -->
                    <div
                      v-if="getVisibleCourses(student).length === 0"
                      class="p-5 text-center rounded-2xl border border-dashed border-slate-200 bg-slate-50/50 text-slate-500 text-xs font-medium"
                    >
                      <span>Khóa học đã hoàn thành đang được ẩn.</span>
                      <button
                        type="button"
                        @click="toggleHideCompleted(student.id)"
                        class="ml-1.5 text-red-600 font-bold hover:underline"
                      >
                        Bấm vào đây để hiện
                      </button>
                    </div>
                  </div>
                </div>

                <!-- 4. Học cụ đã giao -->
                <div class="space-y-1 text-xs pt-1">
                  <div class="flex items-center justify-between">
                    <span class="font-bold text-slate-700">Học cụ đã giao</span>
                    <button
                      type="button"
                      @click="openSupplyModal(student)"
                      class="text-[11px] font-bold text-rose-600 hover:underline"
                    >
                      + Giao thêm học cụ
                    </button>
                  </div>
                  <div v-if="!student.supplies || student.supplies.length === 0" class="text-slate-400 py-1">
                    Chưa có học cụ
                  </div>
                  <div v-else class="divide-y divide-slate-100 bg-white rounded-xl p-3 border border-slate-200/80 space-y-1.5">
                    <div v-for="sup in student.supplies" :key="sup.id || sup.name" class="flex items-center justify-between gap-2 py-1">
                      <span class="font-bold text-slate-800">{{ sup.name }} ({{ formatDate(sup.delivery_date) }})</span>
                      <span class="flex items-center gap-2">
                        <span class="font-bold text-slate-700">SL: {{ sup.quantity }}</span>
                        <button v-if="sup.id" type="button" @click="openReturnSupply(student, sup)"
                          class="rounded-lg px-2 py-0.5 text-[11px] font-semibold text-rose-600 hover:bg-rose-50" title="Thu hồi học cụ giao nhầm">
                          Thu hồi
                        </button>
                      </span>
                    </div>
                  </div>
                </div>
              </td>
            </tr>
          </template>

          <tr v-if="students.length === 0">
            <td colspan="8" class="py-12 text-center text-xs text-slate-400">
              Không tìm thấy học viên nào phù hợp.
            </td>
          </tr>
          </tbody>
        </table>
      </div>
      <Pagination :meta="pagination" @change-page="changePage" @change-per-page="changePerPage" />
    </div>

    <!-- Create Student Modal -->
    <Modal :show="showCreateModal" max-width="2xl" @close="showCreateModal = false">
      <div class="p-6 sm:p-8">
        <div class="flex items-start justify-between border-b border-slate-100 pb-4">
          <div class="flex items-center gap-3">
            <div class="flex h-10 w-10 items-center justify-center rounded-2xl bg-red-50 text-red-600">
              <UserPlus class="h-5 w-5" />
            </div>
            <div>
              <h3 class="text-base sm:text-lg font-black text-slate-900">Thêm Học Viên Mới</h3>
              <p class="text-xs text-slate-500 mt-0.5">Điền thông tin học viên để bắt đầu quản lý lộ trình học & nhắc phí</p>
            </div>
          </div>
          <button @click="showCreateModal = false" class="rounded-lg p-1 text-slate-400 hover:text-slate-600 hover:bg-slate-100">
            <X class="h-5 w-5" />
          </button>
        </div>

        <form @submit.prevent="submitCreateStudent" class="mt-6 space-y-4">
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-3.5">
            <div class="sm:col-span-2">
              <label class="block text-xs font-bold text-slate-700 mb-1">
                Tên học viên <span class="text-red-600">*</span>
              </label>
              <input
                type="text"
                v-model="newStudentForm.name"
                required
                placeholder="Ví dụ: Nguyễn Minh Khang"
                class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-xs sm:text-sm font-medium focus:border-red-500 focus:bg-white focus:outline-none focus:ring-1 focus:ring-red-500 transition-all"
              />
            </div>
            <div>
              <label class="block text-xs font-bold text-slate-700 mb-1">Giới tính</label>
              <select
                v-model="newStudentForm.gender"
                class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-xs sm:text-sm font-medium focus:border-red-500 focus:bg-white focus:outline-none focus:ring-1 focus:ring-red-500 transition-all"
              >
                <option value="Nam">Nam</option>
                <option value="Nữ">Nữ</option>
                <option value="Khác">Khác</option>
              </select>
            </div>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
            <div>
              <label class="block text-xs font-bold text-slate-700 mb-1">
                Tên phụ huynh <span class="text-red-600">*</span>
              </label>
              <input
                type="text"
                v-model="newStudentForm.parent_name"
                required
                placeholder="Ví dụ: Chị Phương Thảo"
                class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-xs sm:text-sm font-medium focus:border-red-500 focus:bg-white focus:outline-none focus:ring-1 focus:ring-red-500 transition-all"
              />
            </div>
            <div>
              <label class="block text-xs font-bold text-slate-700 mb-1">
                Số điện thoại phụ huynh <span class="text-red-600">*</span>
              </label>
              <input
                type="text"
                v-model="newStudentForm.parent_phone"
                required
                placeholder="Ví dụ: 0912345678"
                class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-xs sm:text-sm font-medium focus:border-red-500 focus:bg-white focus:outline-none focus:ring-1 focus:ring-red-500 transition-all"
              />
            </div>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-3 gap-3.5">
            <div class="sm:col-span-2">
              <label class="block text-xs font-bold text-slate-700 mb-1">
                Khóa học đăng ký <span class="text-red-600">*</span>
              </label>
              <SearchableSelect
                v-model="newStudentForm.course_id"
                :options="courseOptions"
                placeholder="Chọn khóa học..."
                @change="onCourseChange"
                required
              />
            </div>
            <div>
              <label class="block text-xs font-bold text-slate-700 mb-1">
                Số buổi học <span class="text-red-600">*</span>
              </label>
              <input
                type="number"
                min="1"
                v-model.number="newStudentForm.total_sessions"
                required
                class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-xs sm:text-sm font-bold text-red-600 focus:border-red-500 focus:bg-white focus:outline-none focus:ring-1 focus:ring-red-500 transition-all"
              />
            </div>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
            <div>
              <label class="block text-xs font-bold text-slate-700 mb-1">Ngày bắt đầu học</label>
              <DatePickerVN
                v-model="newStudentForm.join_date"
                :default-today="true"
              />
            </div>
            <div>
              <label class="block text-xs font-bold text-slate-700 mb-1">Học phí thu (VNĐ)</label>
              <MoneyInput
                v-model="newStudentForm.invoice_amount" placeholder="3.600.000"
                class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-xs sm:text-sm font-bold text-slate-900 focus:border-red-500 focus:bg-white focus:outline-none focus:ring-1 focus:ring-red-500 transition-all"
              />
            </div>
          </div>

          <!-- Tình trạng học phí lúc đăng ký: chưa đóng thì phiếu chờ thu (công nợ), không tính doanh thu -->
          <div>
            <label class="block text-xs font-bold text-slate-700 mb-1">Tình trạng học phí</label>
            <div class="grid grid-cols-2 gap-2 text-xs sm:text-sm">
              <label class="flex items-center gap-2 rounded-xl border px-3 py-2 cursor-pointer"
                :class="!newStudentForm.invoice_paid ? 'border-amber-300 bg-amber-50 text-amber-900 font-semibold' : 'border-slate-200 text-slate-600'">
                <input v-model="newStudentForm.invoice_paid" type="radio" :value="false" class="accent-amber-600" />
                Chưa đóng (chờ thu)
              </label>
              <label class="flex items-center gap-2 rounded-xl border px-3 py-2 cursor-pointer"
                :class="newStudentForm.invoice_paid ? 'border-emerald-300 bg-emerald-50 text-emerald-800 font-semibold' : 'border-slate-200 text-slate-600'">
                <input v-model="newStudentForm.invoice_paid" type="radio" :value="true" class="accent-emerald-600" />
                Đã đóng học phí
              </label>
            </div>
            <p class="text-[11px] text-slate-400 mt-1">Chưa đóng: cổng phụ huynh hiện "Chưa thanh toán", khi phụ huynh đóng tiền bấm nút "Chưa đóng HP" hoặc vào Hóa đơn để xác nhận đã thu.</p>
          </div>

          <div>
            <label class="block text-xs font-bold text-slate-700 mb-1">Trung tâm</label>
            <select
              v-model="newStudentForm.center"
              class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-xs sm:text-sm font-medium focus:border-red-500 focus:bg-white focus:outline-none focus:ring-1 focus:ring-red-500 transition-all"
            >
              <option v-for="c in centerNames" :key="c" :value="c">{{ c }}</option>
            </select>
          </div>

          <ScheduleFields v-model="newStudentForm" :center="newStudentForm.center" />

          <div>
            <label class="block text-xs font-bold text-slate-700 mb-1">Ghi chú ban đầu (nếu có)</label>
            <textarea
              v-model="newStudentForm.notes"
              rows="2"
              placeholder="Ví dụ: Bé học kèm 1-1 tối thứ 3 và thứ 5..."
              class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-xs sm:text-sm font-medium focus:border-red-500 focus:bg-white focus:outline-none focus:ring-1 focus:ring-red-500 transition-all"
            ></textarea>
          </div>

          <div class="flex items-center justify-end gap-3 border-t border-slate-100 pt-5">
            <button
              type="button"
              @click="showCreateModal = false"
              class="rounded-xl border border-slate-200 bg-white px-5 py-2.5 text-xs sm:text-sm font-bold text-slate-700 hover:bg-slate-50 transition-colors"
            >
              Hủy bỏ
            </button>
            <button
              type="submit"
              :disabled="isSubmitting"
              class="rounded-xl bg-indigo-600 px-6 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 shadow-xs shadow-indigo-600/20 transition-colors disabled:opacity-50 flex items-center gap-2"
            >
              <CheckCircle2 class="h-4 w-4" />
              <span>{{ isSubmitting ? 'Đang lưu...' : 'Lưu Học Viên' }}</span>
            </button>
          </div>
        </form>
      </div>
    </Modal>

    <!-- Edit Student Modal -->
    <Modal :show="showEditModal" max-width="2xl" @close="showEditModal = false">
      <div v-if="editingStudent" class="p-6 sm:p-8">
        <div class="flex items-start justify-between border-b border-slate-100 pb-4">
          <div class="flex items-center gap-3">
            <div class="flex h-10 w-10 items-center justify-center rounded-2xl bg-red-50 text-red-600">
              <Edit3 class="h-5 w-5" />
            </div>
            <div>
              <h3 class="text-base sm:text-lg font-black text-slate-900">Sửa Thông Tin Học Viên</h3>
              <p class="text-xs text-slate-500 mt-0.5">{{ editingStudent.name }} &bull; {{ editingStudent.student_code }}</p>
            </div>
          </div>
          <button @click="showEditModal = false" class="rounded-lg p-1 text-slate-400 hover:text-slate-600 hover:bg-slate-100">
            <X class="h-5 w-5" />
          </button>
        </div>

        <form @submit.prevent="submitEditStudent" class="mt-6 space-y-4">
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
            <div>
              <label class="block text-xs font-bold text-slate-700 mb-1">Tên học viên</label>
              <input
                type="text"
                v-model="editingStudent.name"
                required
                class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-xs sm:text-sm font-medium focus:border-red-500 focus:bg-white focus:outline-none focus:ring-1 focus:ring-red-500 transition-all"
              />
            </div>
            <div>
              <label class="block text-xs font-bold text-slate-700 mb-1">Phụ huynh</label>
              <input
                type="text"
                v-model="editingStudent.parent_name"
                required
                class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-xs sm:text-sm font-medium focus:border-red-500 focus:bg-white focus:outline-none focus:ring-1 focus:ring-red-500 transition-all"
              />
            </div>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
            <div>
              <label class="block text-xs font-bold text-slate-700 mb-1">Số điện thoại</label>
              <input
                type="text"
                v-model="editingStudent.parent_phone"
                required
                class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-xs sm:text-sm font-medium focus:border-red-500 focus:bg-white focus:outline-none focus:ring-1 focus:ring-red-500 transition-all"
              />
            </div>
            <div>
              <label class="block text-xs font-bold text-slate-700 mb-1">Khóa học</label>
              <SearchableSelect
                v-model="editingStudent.course_id"
                :options="courseOptions"
                placeholder="Chọn khóa học..."
              />
            </div>
          </div>

          <div class="grid grid-cols-3 gap-3.5">
            <div>
              <label class="block text-xs font-bold text-slate-700 mb-1">Tổng số buổi</label>
              <input
                type="number"
                min="1"
                v-model.number="editingStudent.total_sessions"
                required
                class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-xs sm:text-sm font-bold text-slate-800"
              />
            </div>
            <div>
              <label class="block text-xs font-bold text-slate-700 mb-1">Số buổi còn lại</label>
              <input
                type="number"
                min="0"
                v-model.number="editingStudent.remaining_sessions"
                required
                class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-xs sm:text-sm font-bold text-red-600"
              />
            </div>
            <div>
              <label class="block text-xs font-bold text-slate-700 mb-1">Trạng thái</label>
              <select
                v-model="editingStudent.status"
                class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-xs sm:text-sm font-medium"
              >
                <option value="active">Đang học</option>
                <option value="paused">Tạm dừng</option>
                <option value="dropped">Đã nghỉ</option>
                <option value="inactive">Đã nghỉ (kết thúc khóa học)</option>
              </select>
            </div>
          </div>

          <div>
            <label class="block text-xs font-bold text-slate-700 mb-1">Trung tâm</label>
            <select
              v-model="editingStudent.center"
              class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-xs sm:text-sm font-medium focus:border-red-500 focus:bg-white focus:outline-none focus:ring-1 focus:ring-red-500 transition-all"
            >
              <option v-for="c in centerNames" :key="c" :value="c">{{ c }}</option>
            </select>
          </div>

          <ScheduleFields v-model="editingStudent" :center="editingStudent.center" />

          <div class="flex items-center justify-end gap-3 border-t border-slate-100 pt-5">
            <button
              type="button"
              @click="showEditModal = false"
              class="rounded-xl border border-slate-200 bg-white px-5 py-2.5 text-xs sm:text-sm font-bold text-slate-700 hover:bg-slate-50"
            >
              Hủy
            </button>
            <button
              type="submit"
              :disabled="isSubmitting"
              class="rounded-xl bg-indigo-600 px-6 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 shadow-xs shadow-indigo-600/20 disabled:opacity-50"
            >
              Cập Nhật
            </button>
          </div>
        </form>
      </div>
    </Modal>

    <!-- Add Supply Modal -->
    <AddSupplyModal
      :show="showSupplyModal"
      :student="selectedSupplyStudent"
      @close="showSupplyModal = false"
      @added="fetchData"
    />

    <!-- Import Students Excel/CSV Modal -->
    <ImportStudentsModal
      :show="showImportModal"
      @close="showImportModal = false"
      @imported="fetchData"
    />

    <!-- Info / Guide Modal -->
    <!-- Đổi khóa học cho một thẻ khóa -->
    <Modal :show="changeCourseState.show" max-width="md" @close="changeCourseState.show = false">
      <div class="p-6 space-y-4">
        <div class="flex items-center justify-between">
          <h3 class="text-base font-bold text-slate-900">Đổi khóa học</h3>
          <button type="button" @click="changeCourseState.show = false" class="p-1 rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-600">
            <X class="h-5 w-5" />
          </button>
        </div>
        <label class="block text-xs font-semibold text-slate-700">
          Khóa học thay thế
          <select
            v-model="changeCourseState.courseId"
            class="mt-1.5 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-900 focus:border-rose-500 focus:outline-none"
          >
            <option v-for="c in courses" :key="c.id" :value="c.id">{{ c.name }}</option>
          </select>
        </label>
        <p class="text-[11px] text-slate-500">Số buổi đã học, tổng buổi và thời gian của thẻ khóa này được giữ nguyên.</p>
        <div class="flex justify-end gap-2">
          <button type="button" @click="changeCourseState.show = false" class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
            Hủy
          </button>
          <button
            type="button"
            :disabled="savingCourseChange || !changeCourseState.courseId || Number(changeCourseState.courseId) === Number(changeCourseState.item?.course_id)"
            @click="submitChangeCourse"
            class="rounded-xl bg-rose-600 hover:bg-rose-700 disabled:opacity-50 px-4 py-2 text-sm font-bold text-white"
          >
            Lưu thay đổi
          </button>
        </div>
      </div>
    </Modal>

    <Modal :show="showInfoModal" max-width="lg" @close="showInfoModal = false">
      <div class="p-6">
        <div class="flex items-center justify-between pb-4 border-b border-slate-100">
          <div class="flex items-center gap-2.5">
            <span class="text-xl">ℹ️</span>
            <h3 class="text-base font-bold text-slate-900">Hướng dẫn & Tiêu chuẩn Chuyên cần</h3>
          </div>
          <button
            @click="showInfoModal = false"
            class="p-1.5 text-slate-400 hover:text-slate-600 rounded-lg hover:bg-slate-100"
          >
            <X class="h-4 w-4" />
          </button>
        </div>

        <div class="mt-4 space-y-3.5 text-xs text-slate-600 leading-relaxed">
          <div class="p-3 bg-slate-50 rounded-xl space-y-2">
            <h4 class="font-bold text-slate-800 text-sm">Ý nghĩa 5 chấm điểm danh:</h4>
            <div class="grid grid-cols-2 gap-2 text-xs">
              <div class="flex items-center gap-2">
                <span class="h-3 w-3 rounded-full bg-emerald-500"></span>
                <span>Đi học đầy đủ</span>
              </div>
              <div class="flex items-center gap-2">
                <span class="h-3 w-3 rounded-full bg-rose-500"></span>
                <span>Vắng không phép</span>
              </div>
              <div class="flex items-center gap-2">
                <span class="h-3 w-3 rounded-full bg-amber-400"></span>
                <span>Nghỉ có phép</span>
              </div>
              <div class="flex items-center gap-2">
                <span class="h-3 w-3 rounded-full bg-white border border-slate-300"></span>
                <span>Chưa diễn ra</span>
              </div>
            </div>
          </div>

          <div class="p-3 bg-indigo-50/50 rounded-xl space-y-2">
            <h4 class="font-bold text-slate-800 text-sm">Huy hiệu Chuyên cần:</h4>
            <ul class="space-y-1.5 text-xs">
              <li>🔥 <strong class="text-slate-800">Chuyên cần xuất sắc:</strong> Không vắng buổi nào trong 5 buổi gần nhất.</li>
              <li>😊 <strong class="text-slate-800">Chuyên cần tốt:</strong> Không có buổi vắng liên tiếp.</li>
              <li>⚠️ <strong class="text-slate-800">Cần quan tâm:</strong> Đang vắng 1 buổi gần đây.</li>
              <li>🚨 <strong class="text-slate-800">Nguy cơ nghỉ học:</strong> Vắng liên tiếp từ 2 buổi trở lên.</li>
              <li>💪 <strong class="text-slate-800">Đã quay lại học đều:</strong> Đã đi học lại sau chuỗi vắng.</li>
            </ul>
          </div>
        </div>

        <div class="mt-5 pt-3 border-t border-slate-100 flex justify-end">
          <button
            type="button"
            @click="showInfoModal = false"
            class="px-4 py-2 text-xs font-bold text-slate-700 bg-slate-100 hover:bg-slate-200 rounded-xl"
          >
            Đã hiểu
          </button>
        </div>
      </div>
    </Modal>

    <!-- Modal Chi Tiết Hồ Sơ Học Viên -->
    <StudentDetailModal
      :show="showDetailModal"
      :student-id="selectedDetailStudentId"
      @close="showDetailModal = false"
      @updated="fetchData"
    />

    <!-- Retention Note Modal -->
    <RetentionNoteModal
      :show="showNoteModal"
      :student="activeActionStudent"
      @close="showNoteModal = false"
      @saved="fetchData"
    />

    <!-- Thu hồi học cụ giao nhầm -->
    <ReturnSupplyModal
      :show="!!returningSupply"
      :student="returningSupply?.student"
      :supply="returningSupply?.supply"
      @close="returningSupply = null"
      @returned="fetchData"
    />

    <!-- Renew Modal -->
    <RenewModal
      :show="showRenewModal"
      :student="activeActionStudent"
      @close="showRenewModal = false"
      @renewed="fetchData"
    />

    <!-- Absence Modal -->
    <Modal :show="showAbsenceModal" max-width="md" @close="showAbsenceModal = false">
      <div class="p-5 space-y-4 text-xs">
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
          <h3 class="text-sm font-bold text-slate-900">Ghi nhận vắng học cho {{ activeActionStudent?.name }}</h3>
          <button @click="showAbsenceModal = false" class="text-slate-400 hover:text-slate-600">
            <X class="h-4 w-4" />
          </button>
        </div>

        <form @submit.prevent="submitAbsence" class="space-y-3">
          <div>
            <label class="block font-semibold text-slate-700 mb-1">Loại vắng</label>
            <div class="flex items-center gap-4">
              <label class="flex items-center gap-1.5 cursor-pointer">
                <input type="radio" :value="true" v-model="absenceWithLeave" class="text-indigo-600" />
                <span>Nghỉ có phép</span>
              </label>
              <label class="flex items-center gap-1.5 cursor-pointer">
                <input type="radio" :value="false" v-model="absenceWithLeave" class="text-indigo-600" />
                <span>Vắng không phép</span>
              </label>
            </div>
          </div>

          <div>
            <label class="block font-semibold text-slate-700 mb-1">Lý do / Ghi chú</label>
            <textarea
              v-model="absenceNote"
              rows="3"
              placeholder="Nhập lý do nghỉ học (bận, ốm, đi du lịch...)"
              class="w-full rounded-xl border border-slate-200 p-2.5 text-xs focus:border-indigo-500 focus:outline-none"
            ></textarea>
          </div>

          <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-100">
            <button
              type="button"
              @click="showAbsenceModal = false"
              class="px-3.5 py-1.5 rounded-xl border border-slate-200 text-slate-600 font-semibold"
            >
              Hủy
            </button>
            <button
              type="submit"
              :disabled="isSubmittingAbsence"
              class="px-4 py-1.5 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-bold disabled:opacity-50"
            >
              {{ isSubmittingAbsence ? 'Đang lưu...' : 'Lưu vắng' }}
            </button>
          </div>
        </form>
      </div>
    </Modal>

    <!-- Parent Portal Modal -->
    <Modal :show="showParentPortalModal" max-width="md" @close="showParentPortalModal = false">
      <div class="p-5 space-y-4 text-xs">
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
          <h3 class="text-sm font-bold text-slate-900">Cổng thông tin phụ huynh</h3>
          <button @click="showParentPortalModal = false" class="text-slate-400 hover:text-slate-600">
            <X class="h-4 w-4" />
          </button>
        </div>

        <p class="text-slate-600 leading-relaxed">
          Gửi đường link này cho phụ huynh học viên <strong>{{ activeActionStudent?.name }}</strong>. Phụ huynh truy cập link cần nhập <strong class="text-indigo-600">4 số cuối số điện thoại</strong> để tra cứu số buổi đã học, tiến độ và lịch học:
        </p>

        <div class="flex items-center gap-2 bg-slate-50 p-2 rounded-xl border border-slate-200">
          <input
            type="text"
            readonly
            :value="activeParentPortalUrl"
            class="w-full bg-transparent border-0 text-slate-700 text-xs focus:ring-0 select-all font-mono"
          />
          <button
            type="button"
            @click="copyParentPortalLink"
            class="px-3 py-1.5 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white font-bold shrink-0 transition-colors inline-flex items-center gap-1"
          >
            <Check v-if="copiedPortalLink" class="h-3.5 w-3.5 text-emerald-300" />
            <Copy v-else class="h-3.5 w-3.5" />
            <span>{{ copiedPortalLink ? 'Đã chép' : 'Sao chép' }}</span>
          </button>
        </div>

        <div class="p-3 bg-amber-50 rounded-xl border border-amber-200 text-amber-900 text-[11px] space-y-1">
          <p class="font-bold">🔒 Cơ chế bảo mật thông tin học viên:</p>
          <p>
            Khi mở link, phụ huynh sẽ thấy ô nhập 4 số cuối SĐT. Mã xác thực của học viên này là: <span class="font-mono font-bold text-amber-700 bg-amber-100/80 px-1.5 py-0.5 rounded">{{ activeActionStudent?.parent_phone ? activeActionStudent.parent_phone.slice(-4) : '••••' }}</span>
          </p>
        </div>

        <div class="flex justify-between items-center pt-2">
          <a
            :href="activeParentPortalUrl"
            target="_blank"
            class="px-3 py-2 rounded-xl border border-indigo-200 text-indigo-700 hover:bg-indigo-50 font-bold transition-colors inline-flex items-center gap-1"
          >
            <ExternalLink class="h-3.5 w-3.5" />
            <span>Mở Cổng phụ huynh</span>
          </a>
          <button
            type="button"
            @click="showParentPortalModal = false"
            class="px-4 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold"
          >
            Đóng
          </button>
        </div>
      </div>
    </Modal>
  </div>
</template>
