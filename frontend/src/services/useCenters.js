import { ref, computed } from 'vue';
import { centerApi } from './api';

// Dùng chung cho mọi màn hình: danh sách trung tâm lấy từ màn Trung tâm, chỉ tải một lần
const centers = ref([]);
let loading = null;

export function useCenters() {
  const loadCenters = async (force = false) => {
    if (!force && centers.value.length) return centers.value;
    if (!force && loading) return loading;
    loading = centerApi
      .getCenters()
      .then((res) => {
        centers.value = res.data?.centers || [];
        return centers.value;
      })
      .catch((err) => {
        console.error('Lỗi tải danh sách trung tâm:', err);
        return centers.value;
      })
      .finally(() => {
        loading = null;
      });
    return loading;
  };

  const centerNames = computed(() => centers.value.map((c) => c.name));

  // Trung tâm chính (is_main), nếu không có thì lấy trung tâm đầu tiên
  const defaultCenter = computed(() => (centers.value.find((c) => c.is_main) || centers.value[0])?.name || '');

  const roomNames = (centerName = 'all') =>
    [...new Set(
      centers.value
        .filter((c) => centerName === 'all' || !centerName || c.name === centerName)
        .flatMap((c) => (c.rooms || []).map((r) => r.name)),
    )];

  return { centers, centerNames, defaultCenter, roomNames, loadCenters };
}
