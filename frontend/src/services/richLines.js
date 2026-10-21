// Văn bản do trung tâm soạn (mỗi dòng một ý, **in đậm**) → danh sách HTML an toàn để hiển thị bằng v-html
const escapeHtml = (text) =>
  text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');

export const richLines = (text, vars = {}) =>
  (text || '')
    .split('\n')
    .map((line) => line.trim())
    .filter(Boolean)
    .map((line) => {
      let html = escapeHtml(line);
      for (const [key, value] of Object.entries(vars)) {
        html = html.split(`{${key}}`).join(escapeHtml(String(value)));
      }
      return html.replace(/\*\*(.+?)\*\*/g, '<strong class="text-slate-700">$1</strong>');
    });
