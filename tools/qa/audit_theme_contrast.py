#!/usr/bin/env python3
"""
Audit kontras dark/light yang MENGHORMATI CASCADE.

Bedanya dari scanner sederhana:
  - Semua sumber digabung dengan urutan pemuatan nyata
    (rmi.css -> extra_head -> rmi_light_compat.css -> <style> halaman).
  - Setiap deklarasi diberi specificity, dan hanya yang menang yang dipakai.
  -mode gelap hanya aktif untuk rule yang TIDAK di-gate light, dan
    sebaliknya. Rule tanpa gate = berlaku di kedua mode.
  - Background ikut di-resolve, jadi teks putih di sidebar gelap
    tidak dianggap salah.

Efek samping yang diharapkan: frame rate Recall naik dan false positive
turun drastis dibanding scanner deklarasi-mentah.
"""
import re, glob, sys, os
from collections import defaultdict

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

# ---------- warna ----------
def _lin(c):
    c /= 255.0
    return c / 12.92 if c <= 0.04045 else ((c + 0.055) / 1.055) ** 2.4

def rgb(h):
    h = h.lstrip('#')
    if len(h) == 3:
        h = ''.join(x * 2 for x in h)
    if len(h) == 6:
        h += 'ff'
    if len(h) != 8:
        return None
    return [int(h[i:i + 2], 16) for i in (0, 2, 4)]

def lum(c):
    return 0.2126 * _lin(c[0]) + 0.7152 * _lin(c[1]) + 0.0722 * _lin(c[2])

def with_alpha(c, a=1.0):
    """normalisasi semua bentuk warna ke [r,g,b,a] float."""
    if c is None:
        return None
    c = list(c)
    while len(c) < 3:
        c.append(0)
    if len(c) == 3:
        c.append(a)
    return [float(x) for x in c[:4]]

def over(fg, bg):
    """komposit fg (punya alpha) di atas bg."""
    fg, bg = with_alpha(fg), with_alpha(bg)
    a = fg[3]
    if a >= 1:
        return fg[:3]
    return [fg[i] * a + bg[i] * (1 - a) for i in range(3)]

def ratio(fg, bg):
    fg = with_alpha(fg if isinstance(fg, (list, tuple)) else rgb(fg))
    bg = with_alpha(bg if isinstance(bg, (list, tuple)) else rgb(bg))
    if not fg or not bg:
        return None
    l1, l2 = lum(fg[:3]), lum(bg[:3])
    hi, lo = max(l1, l2), min(l1, l2)
    return (hi + 0.05) / (lo + 0.05)

# ---------- parsing CSS ----------
def strip_comments(css):
    return re.sub(r'/\*.*?\*/', '', css, flags=re.S)

def parse_rules(css):
    """-> list of (selectors[], body, at_prefix)"""
    css = strip_comments(css)
    out, i, n = [], 0, len(css)
    while i < n:
        b = css.find('{', i)
        if b < 0:
            break
        sel = css[i:b].strip()
        depth, j = 1, b + 1
        while j < n and depth:
            if css[j] == '{':
                depth += 1
            elif css[j] == '}':
                depth -= 1
            j += 1
        body = css[b + 1:j - 1]
        if sel.startswith('@'):
            m = re.match(r'@(media|supports)[^{]*', sel)
            if m:
                cond = sel
                inner = parse_rules(body)
                for s, bd, _ in inner:
                    out.append((s, bd, cond))
            # @keyframes / @font-face -> lewati
        elif sel:
            out.append((split_selectors(sel), body, None))
        i = j
    return out

def split_selectors(sel):
    """memecah selector pada koma, TAPI tidak di dalam kurung."""
    out, buf, depth = [], '', 0
    for ch in sel:
        if ch == '(':
            depth += 1
        elif ch == ')':
            depth = max(0, depth - 1)
        if ch == ',' and depth == 0:
            out.append(buf.strip())
            buf = ''
        else:
            buf += ch
    if buf.strip():
        out.append(buf.strip())
    return out


def class_chain(sel):
    """chain class/pseudo-class per part, TANPA atribut.

    Atribut (mis. [data-theme="light"]) sengaja dibuang: ia sudah
    ditangani oleh gate(). Kalau ikut dihitung, selector
    `html[data-theme="light"] .foo` akan masuk grup berbeda dari `.foo`
    padahal men-target elemen yang sama.
    """
    parts, buf, depth = [], '', 0
    for ch in sel.strip():
        if ch == '(':
            depth += 1
        elif ch == ')':
            depth = max(0, depth - 1)
        if ch.isspace() and depth == 0:
            if buf:
                parts.append(buf)
            buf = ''
        else:
            buf += ch
    if buf:
        parts.append(buf)
    out = []
    for p in parts:
        if p in ('>', '~', '+', ''):
            continue
        p = re.sub(r'\[[^\]]*\]', '', p)          # buang [data-theme=...]
        cls = re.findall(r'\.[A-Za-z0-9_-]+|#[A-Za-z0-9_-]+', p)
        cls += re.findall(r':(?!:)[\w-]+', p)     # pseudo-class (hover/active)
        if cls:
            out.append(cls)
        # tag telanjang (html/body/div/th) DIBUANG: itu konteks leluhur,
        # bukan identitas elemen. `html[data-theme="light"] .foo` dan
        # `.foo` harus masuk grup yang sama.
    return out


def split_decls(body):
    d, buf, q = {}, '', None
    for ch in body:
        if q:
            buf += ch
            if ch == q:
                q = None
            continue
        if ch in '"\'':
            q = ch
            buf += ch
            continue
        if ch == ';':
            if ':' in buf:
                k, v = buf.split(':', 1)
                d[k.strip().lower()] = v.strip()
            buf = ''
        else:
            buf += ch
    if ':' in buf:
        k, v = buf.split(':', 1)
        d[k.strip().lower()] = v.strip()
    return d

def specificity(sel):
    """(a,b,c) tanpa pseudo-class, dengan bonus :is()simplified."""
    s = re.sub(r'::?[a-zA-Z-]+(\([^)]*\))?', ' ', sel)      # buang pseudo + argumennya
    ids = len(re.findall(r'#[A-Za-z0-9_-]+', s))
    cls = len(re.findall(r'\.[A-Za-z0-9_-]+', s)) + len(re.findall(r'\[[^\]]+\]', s))
    typ = len(re.findall(r'(?:^|[\s>+~])([a-zA-Z][A-Za-z0-9-]*)', s))
    return (ids, cls, typ)

def key(sel, order):
    a, b, c = specificity(sel)
    return (a, b, c, order)

def gate(sel):
    """'light' | 'dark' | None (berlaku dua mode)"""
    if re.search(r'data-theme="light"|data-rmi-theme="light"', sel):
        return 'light'
    if re.search(r'data-theme="dark"|data-rmi-theme="dark"', sel):
        return 'dark'
    return None

# ---------- token rmi.css ----------
def load_tokens():
    p = os.path.join(ROOT, '_shared', 'rmi.css')
    css = strip_comments(open(p, encoding='utf-8').read())
    toks = {'dark': {}, 'light': {}}
    for m in re.finditer(r'html\[[^{}]*?\]\s*\{([^{}]*)\}', css):
        sel = css[max(0, m.start()):m.start()]
        head = sel[:200] + ' ' + css[m.start():m.end()]
        if 'light' in head and 'dark' not in head.replace('light', ''):
            mode = 'light'
        elif 'dark' in head:
            mode = 'dark'
        else:
            continue
        for k, v in split_decls(m.group(1)).items():
            if k.startswith('--'):
                toks[mode][k] = v.strip()
    return toks

TOK = load_tokens()

def resolve_vars(val, mode, local):
    if not val:
        return None
    val = val.strip()
    for _ in range(6):
        m = re.fullmatch(r'var\(\s*(--[A-Za-z0-9-]+)\s*(?:,\s*(.+))?\s*\)', val, re.S)
        if not m:
            break
        name, fb = m.group(1), m.group(2)
        table = dict(TOK.get('dark', {}))
        if mode == 'light':
            table.update(TOK.get('light', {}))
        table.update(local)
        val = (table.get(name) or (fb or '').strip() or val)
    return val.strip()

COLOR_RE = re.compile(
    r'^#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$|'
    r'^(?:rgba?|hsla?)\(')

def as_color(v, mode, local):
    if not v:
        return None
    v = resolve_vars(v, mode, local)
    if not v or not COLOR_RE.match(v):
        return None
    if v.startswith('#'):
        c = rgb(v)
        return c
    m = re.match(r'rgba?\(([^)]+)\)', v)
    if m:
        p = [x.strip() for x in m.group(1).split(',')]
        try:
            r, g, b = (int(float(p[i])) for i in range(3))
            a = float(p[3]) if len(p) > 3 else 1.0
        except ValueError:
            return None
        return [r, g, b, a]
    return None

# ---------- perakitan sumber ----------
def sources_for(page):
    """urutan pemuatan nyata, dengan index order."""
    src = []
    shared = os.path.join(ROOT, '_shared', 'rmi.css')
    if os.path.exists(shared):
        src.append(('rmi.css', open(shared, encoding='utf-8').read()))
    compat = os.path.join(ROOT, '_shared', 'rmi_light_compat.css')
    if os.path.exists(compat):
        src.append(('compat', open(compat, encoding='utf-8').read()))
    txt = open(page, encoding='utf-8', errors='replace').read()
    for blk in re.findall(r'<style[^>]*>(.*?)</style>', txt, flags=re.S):
        src.append(('page', blk))
    for blk in re.findall(r"rmi_ui_label_html\('([^']*)'\)", txt):
        pass
    return src

def surface_for(mode, local):
    c = as_color(resolve_vars('var(--rmi-bg)', mode, local), mode, local) or rgb('#0b1220')
    if mode == 'dark':
        panel = as_color(resolve_vars('var(--rmi-panel)', mode, local), mode, local)
        if panel and panel[3] < 1:
            return over(panel, c[:3]) + [1.0]
    return c

def audit_page(page, modes=('dark', 'light')):
    """Resolve cascade per-ELEMENT, bukan per-string-selector."""
    # 1) kumpulkan semua deklarasi, lalu kelompokkan berdasarkan class-chain
    groups = defaultdict(lambda: defaultdict(list))   # chain -> prop -> [(key,val,sel,local)]
    order = 0
    for origin, css in sources_for(page):
        for sels, body, at in parse_rules(css):
            if at and 'reduced-motion' in at:
                continue
            d = split_decls(body)
            local = {k: v for k, v in d.items() if k.startswith('--')}
            for s in sels:
                order += 1
                ch = class_chain(s)
                if not ch:
                    continue
                gk = tuple(tuple(x) for x in ch)
                for prop, val in d.items():
                    groups[gk][prop].append((key(s, order), val, s, local, gate(s)))

    def winning(gk, prop, mode):
        """deklarasi menang: gate cocok, lalu specificity, lalu urutan."""
        cands = [c for c in groups.get(gk, {}).get(prop, [])
                 if c[4] in (None, mode)]
        if not cands:
            return None
        return max(cands, key=lambda t: t[0])

    def surface_of(gk, mode, depth=0):
        """warna permukaan: milik elemen ini, atau leluhurnya."""
        w = winning(gk, 'background-color', mode) or winning(gk, 'background', mode)
        if w:
            c = with_alpha(as_color(w[1], mode, w[3]))
            if c and c[3] > 0.01:
                return c
        if depth < 6 and len(gk) > 1:
            return surface_of(gk[:len(gk) - 1], mode, depth + 1)
        return None

    fails = []
    for mode in modes:
        surf = with_alpha(surface_for(mode, {}))
        for gk in groups:
            w = winning(gk, 'color', mode)
            if not w:
                continue
            col = with_alpha(as_color(w[1], mode, w[3]))
            if not col or col[3] < 0.05:
                continue
            bg = surface_of(gk, mode) or surf
            bgc = over(bg, surf) if bg[3] < 1 else bg
            # gradient: uji tiap color-stop, ambil yang paling buruk
            stops = [c for c in stops_of(winning(gk, 'background', mode) or
                                         winning(gk, 'background-image', mode) or
                                         (None, None, None, None, None), mode, w[3])]
            if stops:
                bgcs = [over(s, surf) if s[3] < 1 else s for s in stops]
            else:
                bgcs = [bgc]
            worst = min(((ratio(col, b) or 99), b) for b in bgcs)
            r, bworst = worst
            if r < 4.5:
                fails.append((mode, w[2], w[1].strip(),
                              '#%02x%02x%02x' % tuple(int(round(v)) for v in bworst[:3]), r))
    return fails


def stops_of(w, mode, local):
    """ambil color-stop dari linear-gradient/radial-gradient."""
    if not w or not w[0]:
        return []
    val = resolve_vars(w[1], mode, local or {})
    if not val or 'gradient' not in val:
        return []
    out = []
    for c in re.findall(r'#[0-9a-fA-F]{3,8}|rgba?\([^)]*\)', val):
        col = with_alpha(as_color(c, mode, local or {}))
        if col:
            out.append(col)
    return out


if __name__ == '__main__':
    pats = sys.argv[1:] or ['**/*.php']
    files = []
    for pat in pats:
        for p in glob.glob(os.path.join(ROOT, pat), recursive=True):
            if '/vendor/' in p or p.startswith(ROOT + '/tools/') or '_backup' in p:
                continue
            if os.path.isfile(p):
                files.append(p)
    tot = 0
    bad = []
    for p in sorted(files):
        try:
            f = audit_page(p)
        except Exception as e:
            print(f"  [skip] {os.path.relpath(p, ROOT)}: {e}")
            continue
        if f:
            bad.append((os.path.relpath(p, ROOT), f))
            tot += len(f)
    print(f"=== {len(files)} halaman diperiksa, {tot} kegagalan <<<")
    for name, f in sorted(bad, key=lambda x: -len(x[1]))[:30]:
        print(f"\n{name}  ({len(f)})")
        for mode, s, c, b, r in f[:8]:
            print(f"   [{mode:5}] {r:5.2f}:1  {c} di atas {b}   {s[:66]}")
