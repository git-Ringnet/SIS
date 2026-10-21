<script setup>
import DatePickerVN from './DatePickerVN.vue';

// Thông tin thêm của học viên giống các cột của file Excel mẫu: ngày sinh, SĐT Zalo, email phụ huynh, địa chỉ.
// Dùng chung cho form tạo và form sửa học viên.
const model = defineModel({ type: Object, required: true });

const inputClass =
  'w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-xs sm:text-sm font-medium focus:border-red-500 focus:bg-white focus:outline-none focus:ring-1 focus:ring-red-500 transition-all';
</script>

<template>
  <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
    <div>
      <label class="block text-xs font-bold text-slate-700 mb-1">Ngày sinh</label>
      <DatePickerVN v-model="model.dob" :default-today="false" />
      <p class="text-[11px] text-slate-400 mt-0.5">Dùng cho màn Sinh nhật.</p>
    </div>
    <div>
      <label class="block text-xs font-bold text-slate-700 mb-1">SĐT Zalo</label>
      <input v-model="model.parent_zalo" type="text" placeholder="Để trống = dùng SĐT phụ huynh" :class="inputClass" />
    </div>
    <div>
      <label class="block text-xs font-bold text-slate-700 mb-1">Email phụ huynh</label>
      <input v-model="model.parent_email" type="email" placeholder="phuhuynh@gmail.com" :class="inputClass" />
    </div>
    <div>
      <label class="block text-xs font-bold text-slate-700 mb-1">Địa chỉ</label>
      <input v-model="model.address" type="text" placeholder="Ví dụ: 123 Lê Lợi, Hóc Môn" :class="inputClass" />
    </div>
  </div>
</template>
