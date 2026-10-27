"""Sinh lại phần Dark theme trong src/assets/main.css (không đảo thang màu slate).

Chạy trong thư mục frontend sau khi thêm class màu mới:  python scripts/gen-dark-theme.py src/assets/main.css
Chỉ sinh ghi đè cho class đang có trong src/ nên CSS không phình to.
"""
import glob, re, sys

# Chỉ sinh ghi đè cho class thật sự có trong code (quét src/**/*.vue, *.js) để CSS không phình to
USED = set()
for f in glob.glob('src/**/*.vue', recursive=True) + glob.glob('src/**/*.js', recursive=True):
    USED.update(re.findall(r'[a-z][a-z0-9:-]*-(?:white|[a-z]+-\d+)(?:/\d+)?', open(f, encoding='utf-8').read()))

C500 = {
    'red': 'oklch(63.7% 0.237 25.331)', 'rose': 'oklch(64.5% 0.246 16.439)', 'pink': 'oklch(65.6% 0.241 354.308)',
    'orange': 'oklch(70.5% 0.213 47.604)', 'amber': 'oklch(76.9% 0.188 70.08)', 'yellow': 'oklch(79.5% 0.184 86.047)',
    'lime': 'oklch(76.8% 0.233 130.85)', 'green': 'oklch(72.3% 0.219 149.579)', 'emerald': 'oklch(69.6% 0.17 162.48)',
    'teal': 'oklch(70.4% 0.14 182.503)', 'cyan': 'oklch(71.5% 0.143 215.221)', 'sky': 'oklch(68.5% 0.169 237.323)',
    'blue': 'oklch(62.3% 0.214 259.815)', 'indigo': 'oklch(58.5% 0.233 277.117)', 'violet': 'oklch(60.6% 0.25 292.717)',
    'purple': 'oklch(62.7% 0.265 303.9)', 'fuchsia': 'oklch(66.7% 0.295 322.15)',
}
ALPHAS = [10, 20, 30, 40, 50, 60, 70, 75, 80, 90, 95]

def mix(c, pct, other='transparent'):
    return f'color-mix(in oklab, {c} {pct}%, {other})'

def esc(cls):
    return cls.replace(':', '\\:').replace('/', '\\/').replace('[', '\\[').replace(']', '\\]').replace('#', '\\#')

# Phần tử đã có class dark:... riêng cho thuộc tính đó thì class đó thắng (bỏ qua ghi đè tự động)
GUARD = {'bg': 'dark:bg-', 'text': 'dark:text-', 'border': 'dark:border-'}

def sel(cls, prop, state=''):
    """Selector có độ ưu tiên bằng đúng utility của Tailwind (:where không cộng độ ưu tiên)."""
    guard_prefix = GUARD[prop]
    if cls.startswith('hover:'):
        guard_prefix = guard_prefix.replace('dark:', 'dark:hover:')
        state = ':hover'
    elif cls.startswith('focus:'):
        guard_prefix = guard_prefix.replace('dark:', 'dark:focus:')
        state = ':focus'
    return f':where(.dark) .{esc(cls)}{state}:where(:not([class*="{guard_prefix}"]))'

out = []
w = out.append

def rule(classes, prop, decl, alphas=False):
    sels = []
    for c in classes:
        variants = [c] + ([f'{c}/{a}' for a in ALPHAS] if alphas else [])
        for v in variants:
            if v in USED:
                sels.append(sel(v, prop))
    if not sels:
        return
    w('    ' + ',\n    '.join(sels) + ' {')
    w(f'        {decl}')
    w('    }')

def bg_rule(base_cls, color, with_hover=True, min_alpha=10):
    # màu nền kèm các độ trong suốt /NN (giữ đúng độ trong suốt)
    for prefix in ([''] + (['hover:'] if with_hover else [])):
        if f'{prefix}{base_cls}' in USED:
            w('    ' + sel(f'{prefix}{base_cls}', 'bg') + f' {{ background-color: {color}; }}')
        for a in [a for a in ALPHAS if a >= min_alpha and f'{prefix}{base_cls}/{a}' in USED]:
            w('    ' + sel(f'{prefix}{base_cls}/{a}', 'bg') + f' {{ background-color: {mix(color, a)}; }}')

w("""/* ------------------------------------------------------------------ Dark theme
 * Bật bằng class "dark" trên <html> (nút mặt trăng / mặt trời ở thanh trên cùng).
 * Tự áp cho mọi màn mà không phải sửa từng màn: chỉ đổi những gì vốn SÁNG — nền trắng / slate nhạt → nền tối,
 * chữ slate đậm → chữ sáng, viền nhạt → viền tối, tông màu nhạt (bg-*-50/100, border-*-200) → tông tối trong suốt,
 * chữ màu đậm (text-*-700/800/900) → tông sáng. Nền vốn đã tối (bg-slate-600..950, nút màu) và chữ vốn đã sáng
 * (text-white, text-slate-50..300) giữ nguyên, nên không bị trùng màu chữ với nền.
 * Không đảo thang màu slate: class dark:... viết riêng ở từng màn dùng đúng bảng màu Tailwind, và phần tử nào
 * đã có dark:bg- / dark:text- / dark:border- thì class đó được ưu tiên hơn ghi đè tự động dưới đây.
 */
@custom-variant dark (&:where(.dark, .dark *));

html.dark {
    color-scheme: dark;
    --dark-page: oklch(17% 0.028 265);     /* nền trang */
    --dark-card: oklch(21.5% 0.03 265);    /* bg-white: thẻ, bảng, modal */
    --dark-raised: oklch(25% 0.03 264);    /* bg-slate-50: ô nhấn nhẹ trong thẻ */
    --dark-raised-2: oklch(28.5% 0.032 264); /* bg-slate-100 */
    --dark-raised-3: oklch(33% 0.034 262);   /* bg-slate-200 */
    --dark-line: oklch(31% 0.03 262);      /* viền nhạt slate-100/200 */
    --dark-line-strong: oklch(40% 0.035 260); /* viền slate-300/400 */
}

html.dark ::-webkit-scrollbar-thumb { background: oklch(40% 0.03 260); }
html.dark ::-webkit-scrollbar-thumb:hover { background: oklch(50% 0.03 260); }

@layer utilities {
    html.dark,
    html.dark body,
    :where(.dark) .bg-\\[\\#f8fafc\\],
    :where(.dark) .bg-\\[\\#fafbfc\\] {
        background-color: var(--dark-page);
    }
""")

w('    /* Nền sáng → nền tối */')
# bg-white/10..30: lớp kính mờ trên nền màu (đi với chữ trắng) → giữ nguyên
bg_rule('bg-white', 'var(--dark-card)', min_alpha=40)
w('    ' + sel('focus:bg-white', 'bg') + ' { background-color: var(--dark-card); }')
bg_rule('bg-slate-50', 'var(--dark-raised)')
bg_rule('bg-slate-100', 'var(--dark-raised-2)')
bg_rule('bg-slate-200', 'var(--dark-raised-3)')
w(f'    :where(.dark) .via-white {{ --tw-gradient-via: var(--dark-card); }}')
w(f'    :where(.dark) .from-slate-50 {{ --tw-gradient-from: var(--dark-raised); }}')
w(f'    :where(.dark) .to-slate-100 {{ --tw-gradient-to: var(--dark-raised-2); }}')
w('')
w('    /* Viền nhạt → viền tối */')
rule(['border-slate-100', 'border-slate-200'], 'border', 'border-color: var(--dark-line);', alphas=True)
rule(['border-slate-300', 'hover:border-slate-300', 'hover:border-slate-400'], 'border', 'border-color: var(--dark-line-strong);')
for d in [d for d in ['divide-slate-50', 'divide-slate-100', 'divide-slate-200', 'divide-slate-200/60'] if d in USED]:
    w(f'    :where(.dark) .{esc(d)} > :not(:last-child) {{ border-color: var(--dark-line); }}')
w('')
w('    /* Chữ slate đậm → chữ sáng (text-slate-300 trở xuống vốn đã sáng, giữ nguyên) */')
for shade, light in [(900, 'oklch(96% 0.006 250)'), (800, 'oklch(93% 0.008 252)'), (700, 'oklch(88% 0.012 255)'),
                     (600, 'oklch(80% 0.016 256)'), (500, 'oklch(71% 0.02 257)'), (400, 'oklch(62% 0.025 257)')]:
    rule([f'text-slate-{shade}', f'hover:text-slate-{shade}'], 'text', f'color: {light};')
w('')

for name, c in C500.items():
    w(f'    /* {name} */')
    bg_rule(f'bg-{name}-50', mix(c, 14))
    bg_rule(f'bg-{name}-100', mix(c, 20))
    for g in ('from', 'to'):
        if f'{g}-{name}-50' in USED:
            w(f'    :where(.dark) .{g}-{name}-50 {{ --tw-gradient-{g}: {mix(c, 14)}; }}')
    rule([f'border-{name}-100', f'border-{name}-200', f'hover:border-{name}-200', f'border-{name}-300'], 'border',
         f'border-color: {mix(c, 35)};', alphas=True)
    rule([f'text-{name}-600', f'hover:text-{name}-600'], 'text', f'color: {mix(c, 60, "white")};')
    rule([f'text-{name}-700', f'hover:text-{name}-700'], 'text', f'color: {mix(c, 45, "white")};')
    rule([f'text-{name}-800', f'hover:text-{name}-800'], 'text', f'color: {mix(c, 32, "white")};')
    rule([f'text-{name}-900', f'hover:text-{name}-900', f'text-{name}-950'], 'text', f'color: {mix(c, 20, "white")};')
    w('    ' + ',\n    '.join(sel(f'text-{name}-{s}/{a}', 'text') for s in (700, 900) for a in (40, 50, 60, 70, 80))
      + f' {{ color: {mix(mix(c, 45, "white"), 70)}; }}')
w('}')

css = '\n'.join(out) + '\n'

p = sys.argv[1]
s = open(p, encoding='utf-8', newline='').read()
crlf = '\r\n' in s
s = s.replace('\r\n', '\n')
marker = '/* ------------------------------------------------------------------ Dark theme'
head = s[:s.index(marker)]
s = head + css
if crlf:
    s = s.replace('\n', '\r\n')
open(p, 'w', encoding='utf-8', newline='').write(s)
print(len(css.splitlines()), 'dòng CSS dark theme')
