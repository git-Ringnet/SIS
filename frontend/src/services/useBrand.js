import { ref, computed } from 'vue';
import { brandApi } from './api';

// Tên + logo thương hiệu cấu hình ở màn Cài đặt, dùng chung cho sidebar, màn đăng nhập, tiêu đề tab...
const brand = ref({ brand_name: '', logo_url: '' });
let loading = null;

export function useBrand() {
  const loadBrand = async () => {
    if (loading) return loading;
    loading = brandApi
      .get()
      .then((res) => {
        brand.value = { ...brand.value, ...res.data };
      })
      .catch((err) => console.error('Lỗi tải thông tin thương hiệu:', err))
      .finally(() => {
        loading = null;
      });
    return loading;
  };

  // Gọi sau khi lưu ở màn Cài đặt để sidebar / tiêu đề cập nhật ngay
  const setBrand = (data) => {
    brand.value = { ...brand.value, brand_name: data.brand_name, logo_url: data.logo_url };
  };

  const brandName = computed(() => brand.value.brand_name || 'EduCRM');

  // Tách chữ cuối để tô màu nhấn, ví dụ "Khoa Anh" + "Music"
  const nameParts = computed(() => {
    const words = brandName.value.trim().split(/\s+/);
    if (words.length < 2) return { head: words[0], tail: '' };
    return { head: words.slice(0, -1).join(' '), tail: words[words.length - 1] };
  });

  return { brand, brandName, nameParts, loadBrand, setBrand };
}
