<script setup>
import { ref, onMounted } from 'vue';
import { leadApi, courseApi } from '../services/api';
import Badge from '../components/Badge.vue';
import Modal from '../components/Modal.vue';
import {
  Kanban,
  List,
  Plus,
  Phone,
  Sparkles,
  CheckCircle2,
  X,
} from 'lucide-vue-next';

const leads = ref([]);
const courses = ref([]);
const stages = ref({});
const viewMode = ref('kanban');

const showCreateModal = ref(false);
const showConvertModal = ref(false);
const selectedLead = ref(null);
const isSubmitting = ref(false);

const pipelineColumns = [
  { key: 'new', label: '1. Mới tiếp nhận' },
  { key: 'called', label: '2. Đã gọi điện' },
  { key: 'quote_sent', label: '3. Gửi báo giá' },
  { key: 'trial_done', label: '4. Đã học thử' },
  { key: 'registered', label: '5. Đã đăng ký 🎉' },
];

const newLeadForm = ref({
  parent_name: '',
  student_name: '',
  phone: '',
  email: '',
  interested_course_id: '',
  source: 'Facebook',
  status: 'new',
  trial_date: '',
  expected_revenue: 3600000,
  notes: '',
});

const convertForm = ref({
  course_id: '',
  student_name: '',
  gender: 'Nam',
  dob: '',
  total_sessions: 12,
  price: 3600000,
});

const fetchData = async () => {
  try {
    const res = await leadApi.getAll();
    leads.value = res.data.leads;
    courses.value = res.data.courses;
    stages.value = res.data.stages;
    if (courses.value.length > 0 && !newLeadForm.value.interested_course_id) {
      newLeadForm.value.interested_course_id = courses.value[0].id;
    }
  } catch (err) {
    console.error('Error fetching leads: ', err);
  }
};

onMounted(fetchData);

const submitCreateLead = async () => {
  try {
    isSubmitting.value = true;
    await leadApi.create(newLeadForm.value);
    showCreateModal.value = false;
    newLeadForm.value = {
      parent_name: '',
      student_name: '',
      phone: '',
      email: '',
      interested_course_id: courses.value[0]?.id || '',
      source: 'Facebook',
      status: 'new',
      trial_date: '',
      expected_revenue: 3600000,
      notes: '',
    };
    fetchData();
  } catch (err) {
    alert(err.response?.data?.message || 'Lỗi khi tạo lead');
  } finally {
    isSubmitting.value = false;
  }
};

const moveStage = async (lead, newStage) => {
  try {
    await leadApi.updateStatus(lead.id, { status: newStage });
    fetchData();
  } catch (err) {
    alert(err.response?.data?.message || 'Không thể đổi trạng thái');
  }
};

const openConvertModal = (lead) => {
  selectedLead.value = lead;
  convertForm.value = {
    course_id: lead.interested_course_id || courses.value[0]?.id || '',
    student_name: lead.student_name || lead.parent_name,
    gender: 'Nam',
    dob: '',
    total_sessions: 12,
    price: Number(lead.expected_revenue) || 3600000,
  };
  showConvertModal.value = true;
};

const submitConvert = async () => {
  if (!selectedLead.value) return;
  try {
    isSubmitting.value = true;
    await leadApi.convertToStudent(selectedLead.value.id, convertForm.value);
    showConvertModal.value = false;
    fetchData();
  } catch (err) {
    alert(err.response?.data?.message || 'Lỗi khi chuyển đổi');
  } finally {
    isSubmitting.value = false;
  }
};

const getZaloLink = (phone) => {
  if (!phone) return '#';
  return `https://zalo.me/${phone.replace(/[^0-9]/g, '')}`;
};
</script>

<template>
  <div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
      <div>
        <h1 class="text-2xl font-extrabold tracking-tight text-slate-900">
          Pipeline Khách Hàng Tiềm Năng (Lead CRM)
        </h1>
        <p class="text-xs text-slate-500 mt-1">
          Quy trình: Mới tiếp nhận &rarr; Đã gọi &rarr; Gửi báo giá &rarr; Đã học thử &rarr; Đăng ký chính thức.
        </p>
      </div>

      <div class="flex items-center gap-2">
        <div class="flex items-center rounded-xl bg-slate-200/70 p-1 text-xs">
          <button
            @click="viewMode = 'kanban'"
            class="rounded-lg px-3 py-1.5 font-bold transition-all flex items-center gap-1"
            :class="viewMode === 'kanban' ? 'bg-white text-slate-900 shadow-xs' : 'text-slate-600 hover:text-slate-900'"
          >
            <Kanban class="h-3.5 w-3.5" />
            <span>Kanban</span>
          </button>
          <button
            @click="viewMode = 'table'"
            class="rounded-lg px-3 py-1.5 font-bold transition-all flex items-center gap-1"
            :class="viewMode === 'table' ? 'bg-white text-slate-900 shadow-xs' : 'text-slate-600 hover:text-slate-900'"
          >
            <List class="h-3.5 w-3.5" />
            <span>Bảng</span>
          </button>
        </div>

        <button
          @click="showCreateModal = true"
          class="inline-flex items-center gap-1.5 rounded-xl bg-indigo-600 px-4 py-2 text-xs font-bold text-white shadow-md shadow-indigo-600/20 hover:bg-indigo-700 transition-all"
        >
          <Plus class="h-4 w-4" />
          <span>Thêm Lead Mới</span>
        </button>
      </div>
    </div>

    <!-- KANBAN -->
    <div v-if="viewMode === 'kanban'" class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-5 gap-4 items-start">
      <div
        v-for="col in pipelineColumns"
        :key="col.key"
        class="rounded-2xl border border-slate-200/80 bg-slate-100/60 p-3 shadow-xs flex flex-col min-h-[520px]"
      >
        <div class="flex items-center justify-between pb-3 border-b border-slate-200">
          <span class="text-xs font-bold text-slate-800">{{ col.label }}</span>
          <span class="rounded-full bg-white px-2 py-0.5 text-xs font-extrabold text-slate-700 shadow-xs border border-slate-200">
            {{ stages[col.key] || 0 }}
          </span>
        </div>

        <div class="mt-3 space-y-3 flex-1 overflow-y-auto max-h-[600px] pr-1">
          <div
            v-for="lead in leads.filter(l => l.status === col.key)"
            :key="lead.id"
            class="rounded-xl border border-slate-200/90 bg-white p-3.5 shadow-xs transition-all hover:shadow-md hover:border-indigo-300"
          >
            <div class="flex items-start justify-between gap-1">
              <div>
                <h4 class="text-xs font-bold text-slate-900">{{ lead.parent_name }}</h4>
                <p v-if="lead.student_name" class="text-[11px] text-slate-600 font-medium">
                  Bé: {{ lead.student_name }}
                </p>
              </div>
              <Badge variant="gray" size="sm">{{ lead.source }}</Badge>
            </div>

            <div class="mt-2 text-[11px] text-slate-500 space-y-1">
              <div class="flex items-center gap-1">
                <Phone class="h-3 w-3 text-slate-400" />
                <a :href="getZaloLink(lead.phone)" target="_blank" class="text-blue-600 font-semibold hover:underline">
                  {{ lead.phone }}
                </a>
              </div>
              <div v-if="lead.course" class="font-medium text-indigo-700">
                {{ lead.course.name }}
              </div>
            </div>

            <p v-if="lead.notes" class="mt-2 rounded-lg bg-slate-50 p-2 text-[11px] text-slate-600 italic line-clamp-2 border border-slate-100">
              {{ lead.notes }}
            </p>

            <div class="mt-3 pt-2.5 border-t border-slate-100 flex items-center justify-between gap-1">
              <button
                v-if="lead.status !== 'registered'"
                @click="openConvertModal(lead)"
                class="inline-flex items-center gap-1 rounded-md bg-emerald-50 px-2 py-1 text-[11px] font-bold text-emerald-700 hover:bg-emerald-100"
              >
                <Sparkles class="h-3 w-3 text-emerald-600" />
                <span>Tạo HV</span>
              </button>
              <span v-else class="text-[11px] font-bold text-emerald-600 flex items-center gap-1">
                <CheckCircle2 class="h-3 w-3" /> Đã nhập học
              </span>

              <select
                :value="lead.status"
                @change="moveStage(lead, $event.target.value)"
                class="rounded-md border border-slate-200 bg-white px-2 py-0.5 text-[10px] font-medium text-slate-700"
              >
                <option value="new">1. Mới</option>
                <option value="called">2. Đã gọi</option>
                <option value="quote_sent">3. Báo giá</option>
                <option value="trial_done">4. Học thử</option>
                <option value="registered">5. Đăng ký</option>
              </select>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- TABLE -->
    <div v-else class="rounded-2xl border border-slate-200 bg-white shadow-xs overflow-hidden">
      <table class="w-full text-left text-xs">
        <thead class="bg-slate-50 text-slate-500 border-b border-slate-100">
          <tr>
            <th class="py-3 px-4 font-semibold">Khách hàng / Bé</th>
            <th class="py-3 px-4 font-semibold">SĐT / Zalo</th>
            <th class="py-3 px-4 font-semibold">Khóa quan tâm</th>
            <th class="py-3 px-4 font-semibold">Nguồn</th>
            <th class="py-3 px-4 font-semibold">Trạng thái</th>
            <th class="py-3 px-4 font-semibold text-right">Thao tác</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 text-slate-700">
          <tr v-for="lead in leads" :key="lead.id" class="hover:bg-slate-50">
            <td class="py-3.5 px-4">
              <span class="font-bold text-slate-900">{{ lead.parent_name }}</span>
              <span v-if="lead.student_name" class="block text-slate-500 text-[11px]">Bé: {{ lead.student_name }}</span>
            </td>
            <td class="py-3.5 px-4 font-semibold text-blue-600">{{ lead.phone }}</td>
            <td class="py-3.5 px-4">{{ lead.course?.name || '—' }}</td>
            <td class="py-3.5 px-4"><Badge variant="gray" size="sm">{{ lead.source }}</Badge></td>
            <td class="py-3.5 px-4">
              <select
                :value="lead.status"
                @change="moveStage(lead, $event.target.value)"
                class="rounded-lg border border-slate-200 px-2.5 py-1 text-xs font-semibold"
              >
                <option value="new">Mới tiếp nhận</option>
                <option value="called">Đã gọi điện</option>
                <option value="quote_sent">Gửi báo giá</option>
                <option value="trial_done">Đã học thử</option>
                <option value="registered">Đã đăng ký</option>
              </select>
            </td>
            <td class="py-3.5 px-4 text-right">
              <button
                v-if="lead.status !== 'registered'"
                @click="openConvertModal(lead)"
                class="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-emerald-700"
              >
                Chuyển thành Học Viên
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Modals -->
    <Modal :show="showCreateModal" max-width="lg" @close="showCreateModal = false">
      <div class="p-6">
        <div class="flex items-center justify-between border-b border-slate-100 pb-4">
          <h3 class="text-base font-bold text-slate-900">Thêm Khách Hàng Tiềm Năng (Lead)</h3>
          <button @click="showCreateModal = false" class="text-slate-400 hover:text-slate-600"><X class="h-5 w-5" /></button>
        </div>

        <form @submit.prevent="submitCreateLead" class="mt-4 space-y-4">
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-semibold text-slate-700">Tên phụ huynh <span class="text-rose-500">*</span></label>
              <input type="text" v-model="newLeadForm.parent_name" required class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-xs" />
            </div>
            <div>
              <label class="block text-xs font-semibold text-slate-700">Tên bé (nếu có)</label>
              <input type="text" v-model="newLeadForm.student_name" class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-xs" />
            </div>
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-semibold text-slate-700">Số điện thoại <span class="text-rose-500">*</span></label>
              <input type="text" v-model="newLeadForm.phone" required class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-xs" />
            </div>
            <div>
              <label class="block text-xs font-semibold text-slate-700">Nguồn khách</label>
              <select v-model="newLeadForm.source" class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-xs">
                <option value="Facebook">Facebook Ads / Fanpage</option>
                <option value="Zalo">Zalo OA</option>
                <option value="Hotline">Hotline</option>
                <option value="Giới thiệu">Phụ huynh giới thiệu</option>
                <option value="Website">Website</option>
              </select>
            </div>
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-700">Khóa học quan tâm</label>
            <select v-model="newLeadForm.interested_course_id" class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-xs">
              <option v-for="c in courses" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-700">Ghi chú nhu cầu</label>
            <textarea v-model="newLeadForm.notes" rows="3" class="mt-1 block w-full rounded-lg border border-slate-200 p-2 text-xs"></textarea>
          </div>

          <div class="flex items-center justify-end gap-2 border-t border-slate-100 pt-4">
            <button type="button" @click="showCreateModal = false" class="rounded-lg border px-4 py-2 text-xs">Hủy</button>
            <button type="submit" :disabled="isSubmitting" class="rounded-lg bg-indigo-600 px-4 py-2 text-xs font-bold text-white hover:bg-indigo-700">Lưu Lead</button>
          </div>
        </form>
      </div>
    </Modal>

    <!-- Convert Modal -->
    <Modal :show="showConvertModal" max-width="lg" @close="showConvertModal = false">
      <div v-if="selectedLead" class="p-6">
        <div class="flex items-center justify-between border-b border-slate-100 pb-4">
          <h3 class="text-base font-bold text-slate-900">Chuyển Đổi Lead &rarr; Học Viên</h3>
          <button @click="showConvertModal = false" class="text-slate-400 hover:text-slate-600"><X class="h-5 w-5" /></button>
        </div>

        <form @submit.prevent="submitConvert" class="mt-4 space-y-4">
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-semibold text-slate-700">Tên học viên</label>
              <input type="text" v-model="convertForm.student_name" required class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-xs" />
            </div>
            <div>
              <label class="block text-xs font-semibold text-slate-700">Giới tính</label>
              <select v-model="convertForm.gender" class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-xs">
                <option value="Nam">Nam</option>
                <option value="Nữ">Nữ</option>
              </select>
            </div>
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-700">Khóa học đăng ký</label>
            <select v-model="convertForm.course_id" required class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-xs">
              <option v-for="c in courses" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-semibold text-slate-700">Tổng số buổi cấp</label>
              <input type="number" min="1" v-model.number="convertForm.total_sessions" required class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-xs font-bold" />
            </div>
            <div>
              <label class="block text-xs font-semibold text-slate-700">Học phí thu (VNĐ)</label>
              <input type="number" v-model.number="convertForm.price" required class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-xs font-bold text-emerald-700" />
            </div>
          </div>

          <div class="flex items-center justify-end gap-2 border-t border-slate-100 pt-4">
            <button type="button" @click="showConvertModal = false" class="rounded-lg border px-4 py-2 text-xs">Hủy</button>
            <button type="submit" :disabled="isSubmitting" class="rounded-lg bg-emerald-600 px-4 py-2 text-xs font-bold text-white hover:bg-emerald-700">Xác Nhận Nhập Học</button>
          </div>
        </form>
      </div>
    </Modal>
  </div>
</template>
