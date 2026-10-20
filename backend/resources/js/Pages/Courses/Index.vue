<script setup>
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Badge.vue';
import Modal from '@/Components/Modal.vue';
import {
    BookOpen,
    Plus,
    Users,
    Edit3,
    Trash2,
    X,
    Search,
    CheckCircle2,
    GraduationCap,
} from 'lucide-vue-next';

const props = defineProps({
    courses: {
        type: Array,
        default: () => [],
    },
});

const searchQuery = ref('');
const categoryFilter = ref('all');
const statusFilter = ref('all');

const showModal = ref(false);
const isEditing = ref(false);
const editingId = ref(null);
const isSubmitting = ref(false);

const presetColors = [
    '#3b82f6',
    '#8b5cf6',
    '#ec4899',
    '#f59e0b',
    '#10b981',
    '#ef4444',
    '#06b6d4',
    '#6366f1',
];

const form = ref({
    name: '',
    code: '',
    category: 'Âm nhạc',
    default_sessions: 12,
    price: 3600000,
    color: '#3b82f6',
    description: '',
    is_active: true,
});

const categories = computed(() => {
    const cats = new Set(['Âm nhạc', 'Mỹ thuật', 'Ngoại ngữ', 'Kỹ năng']);
    props.courses.forEach(c => {
        if (c.category) cats.add(c.category);
    });
    return Array.from(cats);
});

const stats = computed(() => {
    const total = props.courses.length;
    const active = props.courses.filter(c => c.is_active !== false).length;
    const totalStudents = props.courses.reduce((acc, c) => acc + (c.active_students_count || c.students_count || 0), 0);
    return { total, active, totalStudents };
});

const filteredCourses = computed(() => {
    return props.courses.filter(c => {
        if (searchQuery.value.trim()) {
            const q = searchQuery.value.toLowerCase().trim();
            const matchName = (c.name || '').toLowerCase().includes(q);
            const matchCode = (c.code || '').toLowerCase().includes(q);
            const matchDesc = (c.description || '').toLowerCase().includes(q);
            if (!matchName && !matchCode && !matchDesc) return false;
        }

        if (categoryFilter.value !== 'all' && c.category !== categoryFilter.value) {
            return false;
        }

        if (statusFilter.value === 'active' && c.is_active === false) {
            return false;
        }
        if (statusFilter.value === 'inactive' && c.is_active !== false) {
            return false;
        }

        return true;
    });
});

const openCreate = () => {
    isEditing.value = false;
    editingId.value = null;
    form.value = {
        name: '',
        code: 'CRS-' + Math.floor(10 + Math.random() * 90),
        category: 'Âm nhạc',
        default_sessions: 12,
        price: 3600000,
        color: '#3b82f6',
        description: '',
        is_active: true,
    };
    showModal.value = true;
};

const openEdit = (c) => {
    isEditing.value = true;
    editingId.value = c.id;
    form.value = {
        ...c,
        price: Number(c.price),
        is_active: c.is_active !== false,
    };
    showModal.value = true;
};

const submit = () => {
    isSubmitting.value = true;
    if (isEditing.value) {
        router.put(`/courses/${editingId.value}`, form.value, {
            preserveScroll: true,
            onSuccess: () => {
                isSubmitting.value = false;
                showModal.value = false;
            },
            onError: () => {
                isSubmitting.value = false;
            },
        });
    } else {
        router.post('/courses', form.value, {
            preserveScroll: true,
            onSuccess: () => {
                isSubmitting.value = false;
                showModal.value = false;
            },
            onError: () => {
                isSubmitting.value = false;
            },
        });
    }
};

const deleteCourse = (c) => {
    if (confirm(`Bạn có chắc muốn xóa khóa học "${c.name}"?`)) {
        router.delete(`/courses/${c.id}`);
    }
};

const formatVND = (val) => {
    return new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(val || 0);
};

const getCategoryBadgeVariant = (cat) => {
    switch (cat) {
        case 'Âm nhạc': return 'purple';
        case 'Mỹ thuật': return 'indigo';
        case 'Ngoại ngữ': return 'blue';
        case 'Kỹ năng': return 'amber';
        default: return 'gray';
    }
};
</script>

<template>
    <AppLayout>
        <Head title="Quản Lý Khóa Học & Lớp" />

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
                        <CheckCircle2 class="h-5 w-5" />
                    </div>
                    <div>
                        <p class="text-[11px] font-semibold text-slate-500">Đang hoạt động</p>
                        <p class="text-lg font-black text-emerald-600">{{ stats.active }} <span class="text-xs font-normal text-slate-400">khóa mở</span></p>
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
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3">
                    <div class="sm:col-span-2 relative">
                        <Search class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-400" />
                        <input
                            v-model="searchQuery"
                            type="text"
                            placeholder="Tìm theo tên khóa học, mã khóa..."
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

                    <div>
                        <select
                            v-model="categoryFilter"
                            class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3 py-2 text-xs sm:text-sm text-slate-700 focus:border-indigo-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20 transition-all cursor-pointer"
                        >
                            <option value="all">Tất cả danh mục</option>
                            <option v-for="cat in categories" :key="cat" :value="cat">{{ cat }}</option>
                        </select>
                    </div>

                    <div>
                        <select
                            v-model="statusFilter"
                            class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3 py-2 text-xs sm:text-sm text-slate-700 focus:border-indigo-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20 transition-all cursor-pointer"
                        >
                            <option value="all">Tất cả trạng thái</option>
                            <option value="active">Đang hoạt động</option>
                            <option value="inactive">Tạm ngừng</option>
                        </select>
                    </div>
                </div>

                <!-- Bảng danh sách khóa học -->
                <div class="overflow-x-auto rounded-xl border border-slate-100">
                    <table class="w-full text-left border-collapse text-xs sm:text-sm">
                        <thead>
                            <tr class="border-b border-slate-100 bg-slate-50/80 text-[11px] sm:text-xs font-semibold text-slate-500 uppercase tracking-wider">
                                <th class="py-3 px-3.5">Mã</th>
                                <th class="py-3 px-3.5">Khóa học</th>
                                <th class="py-3 px-3.5">Danh mục</th>
                                <th class="py-3 px-3.5 text-center">Số buổi</th>
                                <th class="py-3 px-3.5 text-right">Học phí</th>
                                <th class="py-3 px-3.5 text-right">Đơn giá / buổi</th>
                                <th class="py-3 px-3.5 text-center">Học viên</th>
                                <th class="py-3 px-3.5 text-center">Trạng thái</th>
                                <th class="py-3 px-3.5 text-right">Thao tác</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr
                                v-for="c in filteredCourses"
                                :key="c.id"
                                class="hover:bg-slate-50/70 transition-colors"
                            >
                                <td class="py-3.5 px-3.5 font-mono text-xs font-bold text-slate-600 whitespace-nowrap">
                                    <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md bg-slate-100 text-slate-700">
                                        <span
                                            class="h-2 w-2 rounded-full shrink-0"
                                            :style="{ backgroundColor: c.color || '#3b82f6' }"
                                        ></span>
                                        <span>{{ c.code }}</span>
                                    </span>
                                </td>

                                <td class="py-3.5 px-3.5 max-w-xs">
                                    <div class="font-bold text-slate-900 hover:text-indigo-600 transition-colors">
                                        {{ c.name }}
                                    </div>
                                    <p v-if="c.description" class="text-[11px] text-slate-400 line-clamp-1 mt-0.5">
                                        {{ c.description }}
                                    </p>
                                </td>

                                <td class="py-3.5 px-3.5 whitespace-nowrap">
                                    <Badge :variant="getCategoryBadgeVariant(c.category)" size="sm">
                                        {{ c.category }}
                                    </Badge>
                                </td>

                                <td class="py-3.5 px-3.5 text-center whitespace-nowrap font-medium text-slate-700">
                                    <span class="inline-block px-2 py-0.5 rounded-md bg-slate-100 font-bold text-xs">
                                        {{ c.default_sessions }} buổi
                                    </span>
                                </td>

                                <td class="py-3.5 px-3.5 text-right whitespace-nowrap font-bold text-emerald-600">
                                    {{ formatVND(c.price) }}
                                </td>

                                <td class="py-3.5 px-3.5 text-right whitespace-nowrap text-xs text-slate-500 font-medium">
                                    {{ c.default_sessions > 0 ? formatVND(Math.round(c.price / c.default_sessions)) : '—' }}
                                </td>

                                <td class="py-3.5 px-3.5 text-center whitespace-nowrap">
                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-indigo-50 text-indigo-700">
                                        <Users class="h-3 w-3" />
                                        <span>{{ c.active_students_count ?? c.students_count ?? 0 }}</span>
                                    </span>
                                </td>

                                <td class="py-3.5 px-3.5 text-center whitespace-nowrap">
                                    <span
                                        class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-bold"
                                        :class="c.is_active !== false ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'"
                                    >
                                        {{ c.is_active !== false ? 'Đang mở' : 'Tạm ngừng' }}
                                    </span>
                                </td>

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

                            <tr v-if="filteredCourses.length === 0">
                                <td colspan="9" class="py-12 text-center">
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
                            <div>
                                <label class="block text-xs font-semibold text-slate-700 mb-1">
                                    Mã khóa <span class="text-rose-500">*</span>
                                </label>
                                <input
                                    type="text"
                                    v-model="form.code"
                                    required
                                    placeholder="VD: CRS-01"
                                    class="w-full rounded-xl border border-slate-200 px-3.5 py-2 text-xs sm:text-sm font-mono text-slate-800 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20"
                                />
                            </div>

                            <div>
                                <label class="block text-xs font-semibold text-slate-700 mb-1">
                                    Danh mục <span class="text-rose-500">*</span>
                                </label>
                                <select
                                    v-model="form.category"
                                    class="w-full rounded-xl border border-slate-200 px-3.5 py-2 text-xs sm:text-sm text-slate-800 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20"
                                >
                                    <option value="Âm nhạc">Âm nhạc</option>
                                    <option value="Mỹ thuật">Mỹ thuật</option>
                                    <option value="Ngoại ngữ">Ngoại ngữ</option>
                                    <option value="Kỹ năng">Kỹ năng</option>
                                </select>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
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

                            <div>
                                <label class="block text-xs font-semibold text-slate-700 mb-1">
                                    Học phí (VNĐ) <span class="text-rose-500">*</span>
                                </label>
                                <input
                                    type="number"
                                    min="0"
                                    step="50000"
                                    v-model.number="form.price"
                                    required
                                    class="w-full rounded-xl border border-slate-200 px-3.5 py-2 text-xs sm:text-sm font-bold text-emerald-700 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20"
                                />
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1.5">
                                Màu nhận diện
                            </label>
                            <div class="flex items-center gap-2">
                                <button
                                    v-for="color in presetColors"
                                    :key="color"
                                    type="button"
                                    @click="form.color = color"
                                    class="h-7 w-7 rounded-full border-2 transition-transform hover:scale-110 flex items-center justify-center"
                                    :class="form.color === color ? 'border-slate-800 scale-110 shadow-xs' : 'border-white'"
                                    :style="{ backgroundColor: color }"
                                >
                                    <span v-if="form.color === color" class="h-2 w-2 rounded-full bg-white"></span>
                                </button>
                                <input
                                    type="color"
                                    v-model="form.color"
                                    class="h-7 w-7 rounded-full border-0 cursor-pointer p-0 bg-transparent"
                                    title="Chọn màu khác"
                                />
                            </div>
                        </div>

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

                        <div class="flex items-center gap-2 pt-1">
                            <input
                                type="checkbox"
                                id="is_active_checkbox_inertia"
                                v-model="form.is_active"
                                class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                            />
                            <label for="is_active_checkbox_inertia" class="text-xs font-semibold text-slate-700 cursor-pointer">
                                Đang mở tuyển sinh & đào tạo
                            </label>
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
        </div>
    </AppLayout>
</template>
