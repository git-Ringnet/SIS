<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Badge.vue';
import Modal from '@/Components/Modal.vue';
import { BookOpen, Plus, Users, Edit3, Trash2, X, Check } from 'lucide-vue-next';

const props = defineProps({
    courses: {
        type: Array,
        default: () => [],
    },
});

const showModal = ref(false);
const isEditing = ref(false);
const editingId = ref(null);
const isSubmitting = ref(false);

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
    form.value = { ...c, price: Number(c.price) };
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
    if (confirm(`Bạn có chắc muốn xóa khóa học ${c.name}?`)) {
        router.delete(`/courses/${c.id}`);
    }
};

const formatVND = (val) => {
    return new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(val || 0);
};
</script>

<template>
    <AppLayout>
        <Head title="Quản Lý Khóa Học & Lớp" />

        <div class="space-y-6">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-extrabold tracking-tight text-slate-900">
                        Danh Mục Khóa Học
                    </h1>
                    <p class="text-xs text-slate-500 mt-1">
                        Quản lý các chương trình đào tạo Âm nhạc, Mỹ thuật, Ngoại ngữ và mức học phí tiêu chuẩn.
                    </p>
                </div>

                <button
                    @click="openCreate"
                    class="inline-flex items-center gap-1.5 rounded-xl bg-indigo-600 px-4 py-2.5 text-xs font-bold text-white shadow-md shadow-indigo-600/20 hover:bg-indigo-700"
                >
                    <Plus class="h-4 w-4" />
                    <span>Thêm Khóa Học</span>
                </button>
            </div>

            <!-- Courses Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <div
                    v-for="c in courses"
                    :key="c.id"
                    class="rounded-2xl border border-slate-200/90 bg-white p-5 shadow-xs transition-all hover:shadow-md flex flex-col justify-between"
                >
                    <div>
                        <div class="flex items-start justify-between">
                            <div class="flex items-center gap-2">
                                <span class="h-3 w-3 rounded-full shrink-0" :style="{ backgroundColor: c.color }"></span>
                                <Badge variant="indigo" size="sm">{{ c.category }}</Badge>
                            </div>
                            <span class="font-mono text-xs font-bold text-slate-400">{{ c.code }}</span>
                        </div>

                        <h3 class="mt-3 text-base font-bold text-slate-900">{{ c.name }}</h3>
                        <p class="mt-1 text-xs text-slate-500 line-clamp-2 leading-relaxed">{{ c.description || 'Chưa có mô tả chi tiết' }}</p>
                    </div>

                    <div class="mt-6 pt-4 border-t border-slate-100">
                        <div class="flex items-baseline justify-between">
                            <span class="text-xs text-slate-500">{{ c.default_sessions }} buổi / khóa</span>
                            <span class="text-lg font-extrabold text-emerald-600">{{ formatVND(c.price) }}</span>
                        </div>

                        <div class="mt-3 flex items-center justify-between text-xs text-slate-600">
                            <span class="flex items-center gap-1">
                                <Users class="h-3.5 w-3.5 text-slate-400" />
                                <strong>{{ c.active_students_count || 0 }}</strong> học viên đang học
                            </span>
                            <div class="flex items-center gap-1">
                                <button @click="openEdit(c)" class="p-1 text-slate-400 hover:text-indigo-600"><Edit3 class="h-4 w-4" /></button>
                                <button @click="deleteCourse(c)" class="p-1 text-slate-400 hover:text-rose-600"><Trash2 class="h-4 w-4" /></button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Course Modal -->
        <Modal :show="showModal" max-width="md" @close="showModal = false">
            <div class="p-6">
                <div class="flex items-center justify-between border-b border-slate-100 pb-4">
                    <h3 class="text-base font-bold text-slate-900">{{ isEditing ? 'Sửa Khóa Học' : 'Thêm Khóa Học Mới' }}</h3>
                    <button @click="showModal = false" class="text-slate-400 hover:text-slate-600"><X class="h-5 w-5" /></button>
                </div>

                <form @submit.prevent="submit" class="mt-4 space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-slate-700">Tên khóa học</label>
                        <input type="text" v-model="form.name" required class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-xs" />
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-slate-700">Mã khóa</label>
                            <input type="text" v-model="form.code" required class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-xs" />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-700">Danh mục</label>
                            <select v-model="form.category" class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-xs">
                                <option value="Âm nhạc">Âm nhạc</option>
                                <option value="Mỹ thuật">Mỹ thuật</option>
                                <option value="Ngoại ngữ">Ngoại ngữ</option>
                                <option value="Kỹ năng">Kỹ năng</option>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-slate-700">Số buổi tiêu chuẩn</label>
                            <input type="number" min="1" v-model.number="form.default_sessions" required class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-xs font-bold" />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-700">Học phí (VNĐ)</label>
                            <input type="number" min="0" step="50000" v-model.number="form.price" required class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-xs font-bold text-emerald-700" />
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-700">Mô tả khóa học</label>
                        <textarea v-model="form.description" rows="3" class="mt-1 block w-full rounded-lg border border-slate-200 p-2 text-xs"></textarea>
                    </div>

                    <div class="flex items-center justify-end gap-2 border-t border-slate-100 pt-4">
                        <button type="button" @click="showModal = false" class="rounded-lg border px-4 py-2 text-xs font-medium">Hủy</button>
                        <button type="submit" :disabled="isSubmitting" class="rounded-lg bg-indigo-600 px-4 py-2 text-xs font-bold text-white hover:bg-indigo-700 disabled:opacity-50">Lưu Khóa Học</button>
                    </div>
                </form>
            </div>
        </Modal>
    </AppLayout>
</template>
