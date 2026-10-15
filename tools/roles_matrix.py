#!/usr/bin/env python3
"""
Ma tran vai tro x trang cho QLBH-CLOUD: moi vai tro (ADMIN, MANAGER, CASHIER, vai tro tuy chinh) mo MOI trang,
so sanh voi quyen ky vong suy ra tu source (requireRole o dau file + PERMISSION_GROUP_FILES), bao:
  - LECH QUYEN: vai tro vao duoc trang khong duoc phep (nghiem trong) hoac bi chan trang dang le duoc vao
  - LOI: HTTP 5xx hoac co 'Fatal error/Warning/Parse error' trong noi dung
Goi boi tools/roles_test.sh:  roles_matrix.py <A> <cookie_dir> <repo_dir>
"""
import re, subprocess, sys, os, json
from concurrent.futures import ThreadPoolExecutor

A, CK, REPO = sys.argv[1], sys.argv[2], sys.argv[3]
SKIP = set("logout.php lock.php login.php backup.php backup_cron.php backup_download.php daily_cron.php payment_reminder_cron.php config.php config.sample.php seed.php test_isolation.php tenant_export.php tenant_backup_download.php".split())

src = open(os.path.join(REPO, 'inc_functions.php'), encoding='utf-8').read()
groups = {}
m = re.search(r"const PERMISSION_GROUP_FILES = \[(.*?)\n\];", src, re.S)
for g, body in re.findall(r"'(\w+)' => \[(.*?)\],", m.group(1), re.S):
    groups[g] = set(re.findall(r"'([\w\-]+\.php)'", body))

def guard(f):
    text = open(os.path.join(REPO, f), encoding='utf-8', errors='ignore').read()
    text = re.sub(r'/\*.*?\*/', '', text, flags=re.S)       # bo comment khoi
    text = re.sub(r'(?m)^\s*(//|#).*$', '', text)             # bo comment dong
    r = re.search(r"requireRole\(([^)]*)\)", text)
    if r:
        roles = tuple(sorted(re.findall(r"'(\w+)'", r.group(1))))
        return roles
    if 'requireLogin' in text:
        return ('LOGIN',)
    return ('PUBLIC',)

files = sorted(f for f in os.listdir(REPO) if f.endswith('.php') and not f.startswith(('inc_', 'fix_', '_')) and f not in SKIP)
guards = {f: guard(f) for f in files}

# vai tro -> (ten, base_role, nhom quyen tuy chinh)
ROLES = [
    ('admin', 'ADMIN', []), ('manager', 'MANAGER', []), ('cashier', 'CASHIER', []),
    ('c_products', 'CASHIER', ['products']), ('c_finance', 'CASHIER', ['finance']),
    ('c_hr', 'CASHIER', ['hr']), ('c_marketing', 'CASHIER', ['marketing']),
]

# Endpoint chi nhan POST/JSON (GET bi 403 do thieu CSRF) va trang chi danh cho chu so huu he thong (tenant #1)
POST_ONLY = {'pos_checkout.php', 'redeem_gift.php'}
OWNER_ONLY = {'super_admin_tenants.php'}

def expected(role, base, perms, f):
    if f in POST_ONLY:
        return None
    if f in OWNER_ONLY:
        return False
    g = guards[f]
    if g[0] in ('PUBLIC', 'LOGIN'):
        return True
    if base in g:
        return True
    if perms and 'MANAGER' in g:
        # vai tro tuy chinh chi mo rong duoc trang yeu cau co MANAGER va thuoc nhom quyen duoc tich
        return any(f in groups.get(p, ()) for p in perms)
    return False

def fetch(args):
    name, f = args
    out = subprocess.run(['curl', '-s', '-b', os.path.join(CK, name), '-o', '-', '-w', '\n__CODE__%{http_code}', '--max-time', '40', f'{A}/{f}'],
                         capture_output=True, text=True, encoding='utf-8', errors='ignore').stdout
    body, _, code = out.rpartition('\n__CODE__')
    return name, f, int(code or 0), body

jobs = [(r[0], f) for r in ROLES for f in files]
with ThreadPoolExecutor(8) as ex:
    results = list(ex.map(fetch, jobs))

bad_perm, errors, summary = [], [], {r[0]: [0, 0] for r in ROLES}
meta = {r[0]: r for r in ROLES}
for name, f, code, body in results:
    _, base, perms = meta[name]
    allowed = code not in (403, 0)
    summary[name][0 if allowed else 1] += 1
    exp = expected(name, base, perms, f)
    if exp is None:
        pass
    elif allowed and not exp:
        bad_perm.append(f'  VAO DUOC TRANG KHONG PHEP: {name} -> {f} (HTTP {code}, yeu cau {"/".join(guards[f])})')
    elif not allowed and exp and code == 403:
        bad_perm.append(f'  BI CHAN TRANG LE RA DUOC VAO: {name} -> {f} (yeu cau {"/".join(guards[f])})')
    if code >= 500 or re.search(r'(Fatal error|Parse error|Warning:|Notice:|Uncaught)', body):
        errors.append(f'  LOI: {name} -> {f} (HTTP {code})')

# Menu khong duoc dan toi trang vai tro do khong vao duoc (trang chu cua tung vai tro)
codes = {(n, f): c for n, f, c, _ in results}
menu_bad = []
for name, f, code, body in results:
    if f != 'index.php' or code != 200:
        continue
    for link in sorted(set(re.findall(r'href="([\w\-]+\.php)(?:[?#"])', body))):
        if (name, link) in codes and codes[(name, link)] == 403 and link not in POST_ONLY and link not in OWNER_ONLY:
            menu_bad.append(f'  MENU DAN TOI TRANG BI CHAN: {name} thay link {link} nhung bam vao bi 403')
print(f'Da kiem {len(files)} trang x {len(ROLES)} vai tro = {len(results)} lan mo')
print('Tom tat (vao duoc / bi chan):', ', '.join(f'{k}={v[0]}/{v[1]}' for k, v in summary.items()))
print('\n'.join(sorted(set(bad_perm))) or '  Khong lech quyen nao.')
print(chr(10).join(sorted(set(menu_bad))) or '  Menu cua moi vai tro khop quyen.')
print('\n'.join(sorted(set(errors))) or '  Khong co loi 5xx / PHP.')
sys.exit(1 if bad_perm or errors or menu_bad else 0)
