import { ref } from 'vue';

// Giao diện sáng / tối: Lưu độc lập THEO TỪNG TÀI KHOẢN trên máy này.
// Mỗi tài khoản (Admin, Giáo viên, Thu ngân) có sở thích giao diện riêng biệt, không ai ảnh hưởng đến ai.
// Khi đăng xuất, hoặc khi vào các trang công khai (Cổng phụ huynh, Màn hình đăng nhập, QR quầy),
// hệ thống luôn giữ giao diện sáng chuẩn thương hiệu, không làm mờ/lỗi trang của người khác.

const getStorageKey = () => {
  try {
    const raw = localStorage.getItem('auth_user');
    if (raw) {
      const user = JSON.parse(raw);
      if (user?.id) return `sis_theme_user_${user.id}`;
      if (user?.email) return `sis_theme_user_${user.email}`;
    }
  } catch {
    // fallback
  }
  return null;
};

// Đọc theme của tài khoản hiện tại
const readCurrentTheme = () => {
  const key = getStorageKey();
  if (!key) return 'light';
  try {
    // Migration: nếu có sis_theme cũ và chưa có key riêng, chuyển sang key của user
    const legacy = localStorage.getItem('sis_theme');
    const saved = localStorage.getItem(key);
    if (!saved && legacy) {
      localStorage.setItem(key, legacy);
      localStorage.removeItem('sis_theme');
      return legacy === 'dark' ? 'dark' : 'light';
    }
    return saved === 'dark' ? 'dark' : 'light';
  } catch {
    return 'light';
  }
};

const theme = ref(readCurrentTheme());

export const shouldDisableDarkMode = (routePath = '') => {
  if (typeof window === 'undefined') return false;
  const rawPath = routePath || window.location.pathname || '';
  const path = rawPath.replace(/\/$/, '') || '/';
  return (
    path.startsWith('/portal') ||
    path.startsWith('/parent-portal') ||
    path === '/login' ||
    path === '/register'
  );
};

export const applyTheme = (routePath = '') => {
  if (typeof document === 'undefined') return;
  // Nếu ở trang công khai / phụ huynh -> luôn giữ sáng chuẩn
  if (shouldDisableDarkMode(routePath)) {
    document.documentElement.classList.remove('dark');
    return;
  }
  // Nếu đã đăng nhập nội bộ CRM -> áp dụng theo cài đặt riêng của tài khoản đang đăng nhập
  const isDark = theme.value === 'dark' && !!getStorageKey();
  document.documentElement.classList.toggle('dark', isDark);
};

// Đồng bộ lại theme khi user đăng nhập hoặc đổi tài khoản
export function syncThemeForUser() {
  theme.value = readCurrentTheme();
  applyTheme();
}

export function initTheme() {
  try {
    if (!getStorageKey()) {
      localStorage.removeItem('sis_theme');
    }
  } catch {
    // fallback
  }
  applyTheme();
}

export function useTheme() {
  const toggleTheme = () => {
    const key = getStorageKey();
    if (!key) {
      // Chưa đăng nhập: không lưu
      return;
    }
    theme.value = theme.value === 'dark' ? 'light' : 'dark';
    try {
      localStorage.setItem(key, theme.value);
    } catch {
      // Không lưu được (chế độ ẩn danh): vẫn đổi trong phiên này
    }
    applyTheme();
  };

  return { theme, toggleTheme, applyTheme, syncThemeForUser };
}
