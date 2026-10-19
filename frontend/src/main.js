import { createApp } from 'vue';
import App from './App.vue';
import router from './router';
import './assets/main.css';

const app = createApp(App);
app.use(router);

// Chờ router xử lý xong URL ban đầu rồi mới render, tránh sidebar/tiêu đề
// hiển thị tạm theo route mặc định "/" (Bảng điều khiển) trong tích tắc
router.isReady().then(() => app.mount('#app'));
