<script setup>
import { ref, computed, watch, nextTick } from 'vue';
import Modal from './Modal.vue';
import { 
    Copy, 
    Check, 
    ExternalLink, 
    MessageSquareText, 
    Sparkles, 
    X, 
    Edit3, 
    Save, 
    RotateCcw, 
    Info, 
    HelpCircle, 
    ChevronDown, 
    ChevronUp,
    Plus,
    Eye
} from 'lucide-vue-next';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    student: {
        type: Object,
        default: null,
    },
    templates: {
        type: Array,
        default: () => [],
    },
});

const emit = defineEmits(['close', 'template-updated']);

const localTemplates = ref([]);
const selectedCategory = ref('all');
const copiedId = ref(null);
const editingTemplateId = ref(null);
const isSaving = ref(false);
const saveSuccessId = ref(null);
const showGuide = ref(true);
const textareaRefs = ref({});

const editForm = ref({
    id: null,
    title: '',
    template_content: '',
    category: '',
});

const availableVariables = [
    { tag: '{ten_hoc_vien}', label: 'Tên học viên', desc: 'Họ và tên của học viên', example: 'Lê Gia Hưng' },
    { tag: '{ten_phu_huynh}', label: 'Tên phụ huynh', desc: 'Họ tên bố/mẹ học sinh', example: 'Chị Thanh Mai' },
    { tag: '{sdt_phu_huynh}', label: 'SĐT phụ huynh', desc: 'Số điện thoại liên hệ', example: '0934567890' },
    { tag: '{so_buoi_con_lai}', label: 'Số buổi còn lại', desc: 'Số buổi học chưa điểm danh', example: '0' },
    { tag: '{khoa_hoc}', label: 'Tên khóa học', desc: 'Tên khóa học hiện tại của học viên', example: 'Guitar Acoustic' },
    { tag: '{so_tien}', label: 'Học phí', desc: 'Mức học phí khóa học', example: '3.600.000đ' },
    { tag: '{so_tai_khoan}', label: 'Số tài khoản', desc: 'STK ngân hàng nhận thanh toán', example: '1029384756 (HO VIET DUNG)' },
    { tag: '{ngan_hang}', label: 'Tên ngân hàng', desc: 'Ngân hàng thụ hưởng', example: 'Vietcombank' },
];

const categories = [
    { id: 'all', label: 'Tất cả mẫu' },
    { id: 'tuition_reminder', label: 'Nhắc học phí' },
    { id: 'absence_alert', label: 'Cảnh báo vắng' },
    { id: 'payment_confirmation', label: 'Xác nhận đóng phí' },
    { id: 'general_care', label: 'Chăm sóc & Lời khen' },
];

watch(() => props.templates, (newVal) => {
    if (newVal && newVal.length > 0) {
        localTemplates.value = JSON.parse(JSON.stringify(newVal));
    }
}, { immediate: true, deep: true });

watch(() => props.show, async (isOpen) => {
    if (isOpen && (!localTemplates.value || localTemplates.value.length === 0)) {
        try {
            const res = await fetch('/api/message-templates');
            const data = await res.json();
            if (data?.templates) {
                localTemplates.value = data.templates;
            }
        } catch (e) {
            console.error('Lỗi khi tải mẫu tin nhắn:', e);
        }
    }
});

const filteredTemplates = computed(() => {
    if (selectedCategory.value === 'all') return localTemplates.value;
    return localTemplates.value.filter(t => t.category === selectedCategory.value);
});

const formatVND = (val) => {
    if (!val) return '3.600.000đ';
    return new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(val);
};

const renderTemplate = (content) => {
    if (!content) return '';
    if (!props.student) {
        return content
            .replace(/{ten_hoc_vien}/g, 'Lê Gia Hưng')
            .replace(/{ten_phu_huynh}/g, 'Chị Thanh Mai')
            .replace(/{so_buoi_con_lai}/g, '0')
            .replace(/{khoa_hoc}/g, 'Guitar Acoustic & Fingerstyle')
            .replace(/{so_tien}/g, '3.600.000đ')
            .replace(/{so_tai_khoan}/g, '1029384756 (HO VIET DUNG)')
            .replace(/{ngan_hang}/g, 'Vietcombank')
            .replace(/{sdt_phu_huynh}/g, '0934567890');
    }

    const courseName = props.student.course ? props.student.course.name : 'Khóa học';
    const price = props.student.course ? formatVND(props.student.course.price) : '3.600.000đ';

    return content
        .replace(/{ten_hoc_vien}/g, props.student.name || 'Học viên')
        .replace(/{ten_phu_huynh}/g, props.student.parent_name || 'Phụ huynh')
        .replace(/{so_buoi_con_lai}/g, props.student.remaining_sessions ?? 0)
        .replace(/{khoa_hoc}/g, courseName)
        .replace(/{so_tien}/g, price)
        .replace(/{so_tai_khoan}/g, '1029384756 (HO VIET DUNG)')
        .replace(/{ngan_hang}/g, 'Vietcombank')
        .replace(/{sdt_phu_huynh}/g, props.student.parent_phone || '');
};

const copyToClipboard = async (text, id) => {
    try {
        await navigator.clipboard.writeText(text);
        copiedId.value = id;
        setTimeout(() => {
            copiedId.value = null;
        }, 2000);
    } catch (err) {
        console.error('Failed to copy text: ', err);
    }
};

const getZaloLink = () => {
    if (!props.student || !props.student.parent_phone) return '#';
    const cleanPhone = props.student.parent_phone.replace(/[^0-9]/g, '');
    return `https://zalo.me/${cleanPhone}`;
};

const startEdit = (tmpl) => {
    editingTemplateId.value = tmpl.id;
    editForm.value = {
        id: tmpl.id,
        title: tmpl.title,
        template_content: tmpl.template_content,
        category: tmpl.category,
    };
    nextTick(() => {
        const el = textareaRefs.value[tmpl.id];
        if (el) {
            el.focus();
        }
    });
};

const cancelEdit = () => {
    editingTemplateId.value = null;
    editForm.value = {
        id: null,
        title: '',
        template_content: '',
        category: '',
    };
};

const insertVariable = (tag) => {
    const tmplId = editingTemplateId.value;
    const el = textareaRefs.value[tmplId];
    if (!el) {
        editForm.value.template_content += tag;
        return;
    }

    const start = el.selectionStart ?? editForm.value.template_content.length;
    const end = el.selectionEnd ?? editForm.value.template_content.length;
    const currentText = editForm.value.template_content;

    editForm.value.template_content = 
        currentText.substring(0, start) + tag + currentText.substring(end);

    nextTick(() => {
        el.focus();
        const newPos = start + tag.length;
        el.setSelectionRange(newPos, newPos);
    });
};

const saveTemplate = async (tmpl) => {
    if (!editForm.value.title.trim() || !editForm.value.template_content.trim()) {
        alert('Vui lòng không để trống tiêu đề hoặc nội dung mẫu tin nhắn.');
        return;
    }

    isSaving.value = true;
    try {
        const res = await fetch(`/api/message-templates/${tmpl.id}`, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            },
            body: JSON.stringify({
                title: editForm.value.title.trim(),
                template_content: editForm.value.template_content.trim(),
                category: editForm.value.category,
            }),
        });
        const data = await res.json();
        const updated = data.template;

        const index = localTemplates.value.findIndex(t => t.id === tmpl.id);
        if (index !== -1) {
            localTemplates.value[index] = { ...localTemplates.value[index], ...updated };
        }

        emit('template-updated', updated);

        saveSuccessId.value = tmpl.id;
        setTimeout(() => {
            saveSuccessId.value = null;
        }, 2500);

        editingTemplateId.value = null;
    } catch (err) {
        console.error('Lỗi khi lưu mẫu tin nhắn:', err);
        alert('Không thể lưu mẫu tin nhắn. Vui lòng thử lại!');
    } finally {
        isSaving.value = false;
    }
};

const resetToDefault = async (tmpl) => {
    if (!confirm(`Bạn có chắc muốn khôi phục nội dung ban đầu cho mẫu "${tmpl.title}"?`)) {
        return;
    }

    isSaving.value = true;
    try {
        const res = await fetch(`/api/message-templates/${tmpl.id}/reset`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            },
        });
        const data = await res.json();
        const updated = data.template;

        editForm.value.template_content = updated.template_content;
        const index = localTemplates.value.findIndex(t => t.id === tmpl.id);
        if (index !== -1) {
            localTemplates.value[index] = { ...localTemplates.value[index], ...updated };
        }

        emit('template-updated', updated);

        saveSuccessId.value = tmpl.id;
        setTimeout(() => {
            saveSuccessId.value = null;
        }, 2500);
    } catch (err) {
        console.error('Lỗi khi khôi phục mẫu mặc định:', err);
        alert('Không thể khôi phục mẫu mặc định.');
    } finally {
        isSaving.value = false;
    }
};
</script>

<template>
    <Modal :show="show" max-width="3xl" @close="emit('close')">
        <div class="flex flex-col h-[85vh] max-h-[750px] min-h-[480px] bg-white">
            <!-- Fixed Header & Controls -->
            <div class="px-5 pt-5 pb-3 sm:px-6 sm:pt-6 sm:pb-3.5 border-b border-slate-100 shrink-0">
                <!-- Modal Header Title & Close button -->
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600 shadow-xs shrink-0">
                            <MessageSquareText class="h-5 w-5" />
                        </div>
                        <div>
                            <h3 class="text-base sm:text-lg font-bold text-slate-900 leading-tight">
                                Mẫu Tin Nhắn Tái Đăng Ký & Chăm Sóc
                            </h3>
                            <p v-if="student" class="text-xs text-slate-500 mt-0.5">
                                Tự động điền dữ liệu cho bé: <span class="font-semibold text-slate-800">{{ student.name }}</span> 
                                (Còn <span class="font-bold text-rose-600">{{ student.remaining_sessions }}</span> buổi)
                            </p>
                            <p v-else class="text-xs text-slate-500 mt-0.5">
                                Quản lý và tùy chỉnh nội dung các mẫu tin nhắn mẫu của trung tâm
                            </p>
                        </div>
                    </div>
                    <button
                        @click="emit('close')"
                        class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 transition-colors"
                        title="Đóng cửa sổ"
                    >
                        <X class="h-5 w-5" />
                    </button>
                </div>

                <!-- Quick Student Info Bar -->
                <div v-if="student" class="mt-3 flex flex-wrap items-center justify-between gap-2.5 rounded-xl bg-slate-50 px-3 py-2 text-xs border border-slate-200/80">
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                        <div>
                            <span class="text-slate-500">Phụ huynh:</span>
                            <span class="ml-1 font-semibold text-slate-800">{{ student.parent_name || 'Chưa cập nhật' }}</span>
                        </div>
                        <div>
                            <span class="text-slate-500">SĐT:</span>
                            <span class="ml-1 font-semibold text-slate-800">{{ student.parent_phone || 'Chưa cập nhật' }}</span>
                        </div>
                        <div>
                            <span class="text-slate-500">Khóa học:</span>
                            <span class="ml-1 font-semibold text-slate-800">{{ student.course?.name || 'Khóa học' }}</span>
                        </div>
                    </div>
                    <a
                        :href="getZaloLink()"
                        target="_blank"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3 py-1.5 font-medium text-white shadow-xs hover:bg-blue-700 transition-colors shrink-0"
                    >
                        <span>Mở Chat Zalo</span>
                        <ExternalLink class="h-3.5 w-3.5" />
                    </a>
                </div>

                <!-- Category Filter Pills -->
                <div class="mt-3 flex flex-wrap gap-1.5">
                    <button
                        v-for="cat in categories"
                        :key="cat.id"
                        @click="selectedCategory = cat.id"
                        class="rounded-lg px-3 py-1.5 text-xs font-medium transition-all"
                        :class="selectedCategory === cat.id ? 'bg-indigo-600 text-white shadow-xs' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'"
                    >
                        {{ cat.label }}
                    </button>
                </div>
            </div>

            <!-- Scrollable Templates List Area -->
            <div class="flex-1 min-h-0 overflow-y-auto px-5 py-4 sm:px-6 space-y-4 overscroll-contain">
                <div
                    v-for="tmpl in filteredTemplates"
                    :key="tmpl.id"
                    class="rounded-xl border border-slate-200 bg-white p-4 shadow-xs transition-all hover:border-indigo-300 hover:shadow-md"
                >
                    <!-- VIEW MODE -->
                    <template v-if="editingTemplateId !== tmpl.id">
                        <div class="flex items-start justify-between gap-2">
                            <div class="flex items-center gap-2">
                                <span class="flex h-2 w-2 rounded-full bg-indigo-500"></span>
                                <h4 class="text-sm font-semibold text-slate-800">{{ tmpl.title }}</h4>
                            </div>
                            <div class="flex items-center gap-2">
                                <button
                                    @click="startEdit(tmpl)"
                                    class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-medium text-slate-600 hover:bg-slate-50 hover:text-indigo-600 hover:border-indigo-200 transition-colors"
                                    title="Chỉnh sửa nội dung mẫu tin nhắn này"
                                >
                                    <Edit3 class="h-3.5 w-3.5" />
                                    <span>Sửa mẫu</span>
                                </button>

                                <button
                                    @click="copyToClipboard(renderTemplate(tmpl.template_content), tmpl.id)"
                                    class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition-all shadow-xs"
                                    :class="copiedId === tmpl.id ? 'bg-emerald-600 text-white' : 'bg-indigo-50 text-indigo-700 hover:bg-indigo-100'"
                                >
                                    <Check v-if="copiedId === tmpl.id" class="h-3.5 w-3.5" />
                                    <Copy v-else class="h-3.5 w-3.5" />
                                    <span>{{ copiedId === tmpl.id ? 'Đã Copy!' : '1-Click Copy' }}</span>
                                </button>
                            </div>
                        </div>

                        <div class="mt-2.5 rounded-lg bg-slate-50 p-3 font-sans text-xs leading-relaxed text-slate-700 whitespace-pre-line border border-slate-100 select-all">
                            {{ renderTemplate(tmpl.template_content) }}
                        </div>
                    </template>

                    <!-- EDIT MODE -->
                    <template v-else>
                        <div class="space-y-3">
                            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-2.5">
                                <div class="flex items-center gap-2">
                                    <span class="flex h-2 w-2 rounded-full bg-amber-500 animate-pulse"></span>
                                    <span class="text-xs font-bold uppercase tracking-wider text-amber-700">Đang chỉnh sửa mẫu tin nhắn</span>
                                </div>
                                <div class="flex items-center gap-1.5">
                                    <button
                                        @click="showGuide = !showGuide"
                                        type="button"
                                        class="inline-flex items-center gap-1 text-xs text-indigo-600 hover:text-indigo-700 font-medium px-2 py-1 rounded bg-indigo-50/60 hover:bg-indigo-50"
                                    >
                                        <HelpCircle class="h-3.5 w-3.5" />
                                        <span>{{ showGuide ? 'Ẩn hướng dẫn biến' : 'Xem hướng dẫn biến' }}</span>
                                        <ChevronUp v-if="showGuide" class="h-3 w-3" />
                                        <ChevronDown v-else class="h-3 w-3" />
                                    </button>
                                    <button
                                        @click="resetToDefault(tmpl)"
                                        :disabled="isSaving"
                                        type="button"
                                        class="inline-flex items-center gap-1 text-xs text-slate-500 hover:text-rose-600 px-2 py-1 rounded hover:bg-rose-50 transition-colors"
                                        title="Khôi phục lại nội dung chuẩn ban đầu"
                                    >
                                        <RotateCcw class="h-3.5 w-3.5" />
                                        <span>Khôi phục gốc</span>
                                    </button>
                                </div>
                            </div>

                            <div>
                                <label class="block text-xs font-semibold text-slate-700 mb-1">Tiêu đề mẫu tin nhắn:</label>
                                <input
                                    v-model="editForm.title"
                                    type="text"
                                    class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-xs text-slate-800 shadow-xs focus:border-indigo-500 focus:outline-hidden focus:ring-1 focus:ring-indigo-500"
                                    placeholder="Nhập tiêu đề mẫu..."
                                />
                            </div>

                            <!-- Variable Insert Quick Toolbar -->
                            <div class="rounded-xl border border-indigo-100 bg-indigo-50/50 p-3">
                                <div class="flex items-center justify-between mb-2">
                                    <div class="flex items-center gap-1.5 text-xs font-bold text-indigo-900">
                                        <Sparkles class="h-3.5 w-3.5 text-indigo-600" />
                                        <span>Bấm vào biến bên dưới để chèn nhanh vào vị trí con trỏ:</span>
                                    </div>
                                    <span class="text-[11px] text-indigo-600">Tự động điền dữ liệu học viên</span>
                                </div>

                                <!-- Variable Chips -->
                                <div class="flex flex-wrap gap-1.5">
                                    <button
                                        v-for="v in availableVariables"
                                        :key="v.tag"
                                        type="button"
                                        @click="insertVariable(v.tag)"
                                        class="group inline-flex items-center gap-1 rounded-md border border-indigo-200 bg-white px-2 py-1 text-xs font-mono font-medium text-indigo-700 shadow-2xs hover:bg-indigo-600 hover:text-white hover:border-indigo-600 transition-all"
                                        :title="`${v.label}: ${v.desc} (Ví dụ: ${v.example})`"
                                    >
                                        <Plus class="h-3 w-3 text-indigo-500 group-hover:text-white transition-colors" />
                                        <span>{{ v.tag }}</span>
                                        <span class="text-[10px] text-slate-400 group-hover:text-indigo-100 font-sans">({{ v.label }})</span>
                                    </button>
                                </div>

                                <!-- Collapsible Detailed Variable Guide Table -->
                                <div v-if="showGuide" class="mt-3 pt-2.5 border-t border-indigo-100/80 text-[11px]">
                                    <div class="flex items-center gap-1 font-semibold text-slate-700 mb-1.5">
                                        <Info class="h-3 w-3 text-indigo-600" />
                                        <span>Bảng giải nghĩa các biến hiển thị thông tin:</span>
                                    </div>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-1.5">
                                        <div
                                            v-for="v in availableVariables"
                                            :key="v.tag"
                                            class="flex items-center justify-between rounded bg-white/80 px-2 py-1 border border-indigo-50"
                                        >
                                            <span class="font-mono font-semibold text-indigo-800">{{ v.tag }}</span>
                                            <span class="text-slate-600">{{ v.label }} <span class="text-slate-400">({{ v.desc }})</span></span>
                                        </div>
                                    </div>
                                    <p class="mt-2 text-slate-500 italic">
                                        💡 Mẹo: Nếu lỡ tay xóa mất biến như họ tên, số tài khoản ngân hàng, chỉ cần nhấp vào các nút bấm màu xanh phía trên để thêm lại ngay.
                                    </p>
                                </div>
                            </div>

                            <div>
                                <label class="block text-xs font-semibold text-slate-700 mb-1">Nội dung mẫu (chứa các biến động):</label>
                                <textarea
                                    :ref="el => { if (el) textareaRefs[tmpl.id] = el }"
                                    v-model="editForm.template_content"
                                    rows="6"
                                    class="w-full rounded-lg border border-slate-300 p-3 font-sans text-xs leading-relaxed text-slate-800 shadow-xs focus:border-indigo-500 focus:outline-hidden focus:ring-1 focus:ring-indigo-500"
                                    placeholder="Nhập nội dung tin nhắn mẫu..."
                                ></textarea>
                            </div>

                            <!-- Live Preview Box -->
                            <div class="rounded-lg border border-emerald-200 bg-emerald-50/40 p-3">
                                <div class="flex items-center gap-1.5 text-xs font-bold text-emerald-800 mb-1.5">
                                    <Eye class="h-3.5 w-3.5 text-emerald-600" />
                                    <span>Xem trước kết quả thực tế khi gửi cho phụ huynh:</span>
                                </div>
                                <div class="rounded-md bg-white p-2.5 text-xs text-slate-700 leading-relaxed whitespace-pre-line border border-emerald-100 shadow-2xs">
                                    {{ renderTemplate(editForm.template_content) }}
                                </div>
                            </div>

                            <!-- Edit Action Buttons -->
                            <div class="flex items-center justify-end gap-2 pt-1 border-t border-slate-100">
                                <button
                                    @click="cancelEdit"
                                    :disabled="isSaving"
                                    type="button"
                                    class="rounded-lg border border-slate-200 bg-white px-3.5 py-1.5 text-xs font-medium text-slate-600 hover:bg-slate-50 transition-colors"
                                >
                                    Hủy bỏ
                                </button>
                                <button
                                    @click="saveTemplate(tmpl)"
                                    :disabled="isSaving"
                                    type="button"
                                    class="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 px-4 py-1.5 text-xs font-semibold text-white shadow-xs hover:bg-indigo-700 disabled:opacity-50 transition-all"
                                >
                                    <Save class="h-3.5 w-3.5" />
                                    <span>{{ isSaving ? 'Đang lưu...' : 'Lưu mẫu tin nhắn' }}</span>
                                </button>
                            </div>
                        </div>
                    </template>
                </div>

                <div v-if="filteredTemplates.length === 0" class="py-12 text-center text-xs text-slate-400">
                    Không tìm thấy mẫu tin nhắn nào trong danh mục này.
                </div>
            </div>

            <!-- Fixed Footer Info -->
            <div class="px-5 py-3 sm:px-6 border-t border-slate-100 bg-slate-50/80 shrink-0 flex items-center justify-between text-xs text-slate-500">
                <div class="flex items-center gap-1.5 truncate mr-3">
                    <Sparkles class="h-4 w-4 text-amber-500 shrink-0" />
                    <span class="truncate">Hệ thống tự động điền tên bé, số buổi còn lại và học phí chính xác khi gửi.</span>
                </div>
                <button
                    @click="emit('close')"
                    class="rounded-lg border border-slate-200 bg-white px-4 py-1.5 font-medium text-slate-700 hover:bg-slate-100 transition-colors shrink-0 shadow-2xs"
                >
                    Đóng
                </button>
            </div>
        </div>
    </Modal>
</template>
