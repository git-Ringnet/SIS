import { createApp } from 'vue';
import App from './App.vue';
import router from './router';
import './assets/main.css';
import { initTheme } from './services/useTheme';

// Bật giao diện tối đã chọn trước khi render (tránh nháy trắng)
initTheme();

const app = createApp(App);
app.use(router);

// Chờ router xử lý xong URL ban đầu rồi mới render, tránh sidebar/tiêu đề
// hiển thị tạm theo route mặc định "/" (Bảng điều khiển) trong tích tắc
router.isReady().then(() => app.mount('#app'));
