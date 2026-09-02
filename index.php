<?php
declare(strict_types=1);

session_start();
date_default_timezone_set('America/New_York');

const DB_FILE = __DIR__ . '/../private_html/cafeteria.sqlite';

function db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON; PRAGMA journal_mode = WAL; PRAGMA busy_timeout = 5000;');
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS students (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_name TEXT NOT NULL,
            grade TEXT NOT NULL,
            lunch_time TEXT NOT NULL,
            meal_preferences TEXT NOT NULL DEFAULT '',
            parent_name TEXT NOT NULL,
            contact_email TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS products (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT '',
            price_cents INTEGER NOT NULL DEFAULT 0 CHECK(price_cents >= 0),
            active INTEGER NOT NULL DEFAULT 1 CHECK(active IN (0, 1)),
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS sales (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_id INTEGER NOT NULL,
            total_cents INTEGER NOT NULL CHECK(total_cents >= 0),
            note TEXT NOT NULL DEFAULT '',
            served_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(student_id) REFERENCES students(id)
        );
        CREATE TABLE IF NOT EXISTS sale_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sale_id INTEGER NOT NULL,
            product_id INTEGER,
            product_name TEXT NOT NULL,
            unit_price_cents INTEGER NOT NULL,
            quantity INTEGER NOT NULL DEFAULT 1 CHECK(quantity > 0),
            FOREIGN KEY(sale_id) REFERENCES sales(id) ON DELETE CASCADE,
            FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE SET NULL
        );
        CREATE INDEX IF NOT EXISTS idx_sales_served_at ON sales(served_at);
        CREATE INDEX IF NOT EXISTS idx_sales_student ON sales(student_id);
    SQL);

    $count = (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
    if ($count === 0) {
        $seed = $pdo->prepare('INSERT INTO products (name, description, price_cents) VALUES (?, ?, ?)');
        $seed->execute(['Daily Lunch', 'The cafeteria meal of the day', 450]);
        $seed->execute(['Fresh Fruit', 'Seasonal fruit', 125]);
        $seed->execute(['Milk', 'Cold milk', 100]);
    }

    return $pdo;
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function money(int $cents): string
{
    return '$' . number_format($cents / 100, 2);
}

function flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function go(string $view): never
{
    header('Location: ?view=' . rawurlencode($view));
    exit;
}

function csrf(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf'];
}

function requireCsrf(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(419);
        exit('This form expired. Please go back and try again.');
    }
}

function posted(string $key): string
{
    return trim((string) ($_POST[$key] ?? ''));
}

try {
    $pdo = db();
} catch (Throwable $error) {
    http_response_code(500);
    exit('<h1>Cafeteria setup needs attention</h1><p>The database could not be opened. Confirm that private_html is writable by PHP.</p>');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = posted('action');

    try {
        if ($action === 'add_student') {
            $name = posted('student_name');
            $grade = posted('grade');
            $lunchTime = posted('lunch_time');
            $preferences = posted('meal_preferences');
            $parentName = posted('parent_name');
            $email = posted('contact_email');

            if ($name === '' || $grade === '' || $lunchTime === '' || $parentName === '') {
                throw new RuntimeException('Please complete the student name, grade, lunch time, and parent or guardian name.');
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Please enter a valid email address or leave it blank.');
            }

            $stmt = $pdo->prepare('INSERT INTO students (student_name, grade, lunch_time, meal_preferences, parent_name, contact_email) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$name, $grade, $lunchTime, $preferences, $parentName, $email]);
            flash($name . ' is ready for lunch!');
            go('student-success');
        }

        if ($action === 'add_product') {
            $name = posted('name');
            $description = posted('description');
            $price = posted('price');
            if ($name === '' || $price === '' || !is_numeric($price) || (float) $price < 0) {
                throw new RuntimeException('Enter a product name and a valid non-negative price.');
            }
            $cents = (int) round((float) $price * 100);
            $stmt = $pdo->prepare('INSERT INTO products (name, description, price_cents) VALUES (?, ?, ?)');
            $stmt->execute([$name, $description, $cents]);
            flash($name . ' was added to the menu.');
            go('menu');
        }

        if ($action === 'toggle_product') {
            $id = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT);
            if (!$id) {
                throw new RuntimeException('That menu item could not be found.');
            }
            $stmt = $pdo->prepare('UPDATE products SET active = CASE active WHEN 1 THEN 0 ELSE 1 END WHERE id = ?');
            $stmt->execute([$id]);
            flash('Menu availability updated.');
            go('menu');
        }

        if ($action === 'record_sale') {
            $studentId = filter_input(INPUT_POST, 'student_id', FILTER_VALIDATE_INT);
            $productIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['products'] ?? [])))));
            $note = posted('note');
            if (!$studentId) {
                throw new RuntimeException('Choose a student.');
            }
            if ($productIds === []) {
                throw new RuntimeException('Choose at least one menu item.');
            }

            $studentStmt = $pdo->prepare('SELECT student_name FROM students WHERE id = ?');
            $studentStmt->execute([$studentId]);
            $studentName = $studentStmt->fetchColumn();
            if (!$studentName) {
                throw new RuntimeException('That student could not be found.');
            }

            $placeholders = implode(',', array_fill(0, count($productIds), '?'));
            $productStmt = $pdo->prepare("SELECT id, name, price_cents FROM products WHERE active = 1 AND id IN ($placeholders)");
            $productStmt->execute($productIds);
            $items = $productStmt->fetchAll();
            if ($items === []) {
                throw new RuntimeException('The selected menu items are no longer available.');
            }
            $total = array_sum(array_column($items, 'price_cents'));

            $pdo->beginTransaction();
            $saleStmt = $pdo->prepare('INSERT INTO sales (student_id, total_cents, note) VALUES (?, ?, ?)');
            $saleStmt->execute([$studentId, $total, $note]);
            $saleId = (int) $pdo->lastInsertId();
            $itemStmt = $pdo->prepare('INSERT INTO sale_items (sale_id, product_id, product_name, unit_price_cents) VALUES (?, ?, ?, ?)');
            foreach ($items as $item) {
                $itemStmt->execute([$saleId, $item['id'], $item['name'], $item['price_cents']]);
            }
            $pdo->commit();
            flash('Meal recorded for ' . $studentName . ' — ' . money((int) $total));
            go('pos');
        }
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash($error instanceof RuntimeException ? $error->getMessage() : 'Something went wrong. Please try again.', 'error');
        $fallback = match ($action) {
            'add_student' => 'register',
            'add_product', 'toggle_product' => 'menu',
            default => 'pos',
        };
        go($fallback);
    }
}

$allowedViews = ['home', 'register', 'student-success', 'pos', 'menu', 'dashboard', 'students'];
$view = (string) ($_GET['view'] ?? 'home');
if (!in_array($view, $allowedViews, true)) {
    $view = 'home';
}
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$products = $pdo->query('SELECT * FROM products ORDER BY active DESC, name')->fetchAll();
$students = $pdo->query('SELECT * FROM students ORDER BY student_name')->fetchAll();
$monthStart = date('Y-m-01 00:00:00');
$nextMonth = date('Y-m-01 00:00:00', strtotime('+1 month'));
$summaryStmt = $pdo->prepare('SELECT COUNT(*) AS meals, COALESCE(SUM(total_cents), 0) AS total FROM sales WHERE served_at >= ? AND served_at < ?');
$summaryStmt->execute([$monthStart, $nextMonth]);
$summary = $summaryStmt->fetch();
$recentSales = $pdo->query('SELECT sales.*, students.student_name FROM sales JOIN students ON students.id = sales.student_id ORDER BY sales.served_at DESC LIMIT 20')->fetchAll();
$monthlySales = $pdo->query("SELECT strftime('%Y-%m', served_at) AS month, COUNT(*) AS meals, SUM(total_cents) AS total FROM sales GROUP BY month ORDER BY month DESC LIMIT 12")->fetchAll();
$topItemsStmt = $pdo->prepare('SELECT sale_items.product_name, SUM(sale_items.quantity) AS quantity FROM sale_items JOIN sales ON sales.id = sale_items.sale_id WHERE sales.served_at >= ? AND sales.served_at < ? GROUP BY sale_items.product_name ORDER BY quantity DESC, sale_items.product_name LIMIT 5');
$topItemsStmt->execute([$monthStart, $nextMonth]);
$topItems = $topItemsStmt->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0a0a0b">
    <title>Cafeteria</title>
    <style>
        :root { color-scheme: dark; --bg:#09090b; --panel:#141416; --panel2:#1c1c20; --line:#303036; --text:#fafafa; --muted:#a1a1aa; --brand:#a3e635; --brand-dark:#1a2e05; --danger:#f87171; }
        * { box-sizing:border-box; }
        body { margin:0; min-height:100vh; background:var(--bg); color:var(--text); font:16px/1.5 Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif; }
        a { color:inherit; text-decoration:none; }
        button,input,select,textarea { font:inherit; }
        .shell { width:min(1180px,calc(100% - 28px)); margin:auto; }
        header { position:sticky; top:0; z-index:10; border-bottom:1px solid var(--line); background:rgba(9,9,11,.94); backdrop-filter:blur(12px); }
        .topbar { min-height:72px; display:flex; align-items:center; gap:12px; }
        .logo { display:flex; align-items:center; gap:10px; font-weight:850; letter-spacing:-.02em; margin-right:auto; }
        .logo-mark { width:38px; height:38px; display:grid; place-items:center; border-radius:11px; background:var(--brand); color:#101504; }
        nav { display:flex; flex-wrap:wrap; gap:8px; }
        .nav-link,.button { display:inline-flex; align-items:center; justify-content:center; gap:9px; min-height:48px; padding:10px 16px; border:1px solid var(--line); border-radius:12px; background:var(--panel); color:var(--text); font-weight:750; cursor:pointer; }
        .nav-link:hover,.button:hover { border-color:#52525b; transform:translateY(-1px); }
        .nav-link.active,.button.primary { background:var(--brand); border-color:var(--brand); color:#111704; }
        .button.danger { color:var(--danger); }
        main { padding:38px 0 72px; }
        .eyebrow { color:var(--brand); font-size:.78rem; font-weight:850; letter-spacing:.13em; text-transform:uppercase; }
        h1 { margin:6px 0 10px; font-size:clamp(2rem,6vw,4.2rem); line-height:1; letter-spacing:-.055em; }
        h2 { margin:0 0 16px; font-size:1.5rem; letter-spacing:-.025em; }
        h3 { margin:0 0 5px; }
        .lede { max-width:700px; margin:0; color:var(--muted); font-size:1.08rem; }
        .page-head { margin-bottom:30px; }
        .action-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:16px; margin-top:32px; }
        .action-card { min-height:220px; padding:28px; display:flex; flex-direction:column; justify-content:space-between; border:1px solid var(--line); border-radius:20px; background:linear-gradient(145deg,var(--panel2),var(--panel)); }
        .action-card:hover { border-color:var(--brand); }
        .big-icon { font-size:2.2rem; }
        .action-card p,.muted { color:var(--muted); }
        .card { padding:24px; border:1px solid var(--line); border-radius:18px; background:var(--panel); }
        .grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
        .stats { display:grid; grid-template-columns:repeat(3,1fr); gap:14px; margin-bottom:22px; }
        .stat { padding:22px; border:1px solid var(--line); border-radius:16px; background:var(--panel); }
        .stat strong { display:block; margin-top:5px; font-size:2rem; letter-spacing:-.04em; }
        form.stack { display:grid; gap:18px; }
        label { display:grid; gap:7px; font-weight:700; }
        label span small { color:var(--muted); font-weight:500; }
        input,select,textarea { width:100%; min-height:52px; padding:12px 14px; border:1px solid var(--line); border-radius:11px; outline:none; background:#0f0f11; color:var(--text); }
        textarea { min-height:110px; resize:vertical; }
        input:focus,select:focus,textarea:focus { border-color:var(--brand); box-shadow:0 0 0 3px rgba(163,230,53,.12); }
        .form-actions { display:flex; gap:10px; flex-wrap:wrap; }
        .form-actions .button { min-height:56px; padding-inline:22px; }
        .menu-picker { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; }
        .pick { position:relative; display:block; cursor:pointer; }
        .pick input { position:absolute; opacity:0; pointer-events:none; }
        .pick-body { min-height:120px; padding:17px; display:flex; flex-direction:column; justify-content:space-between; border:2px solid var(--line); border-radius:14px; background:var(--panel2); }
        .pick input:checked + .pick-body { border-color:var(--brand); background:var(--brand-dark); box-shadow:inset 0 0 0 1px var(--brand); }
        .pick-price { color:var(--brand); font-weight:850; }
        .flash { margin-bottom:22px; padding:15px 18px; border:1px solid #3f6212; border-radius:12px; background:#152306; color:#d9f99d; font-weight:700; }
        .flash.error { border-color:#7f1d1d; background:#2b0c0c; color:#fecaca; }
        .list { display:grid; gap:10px; }
        .list-row { padding:16px; display:flex; align-items:center; gap:14px; border:1px solid var(--line); border-radius:13px; background:var(--panel); }
        .list-row .grow { flex:1; min-width:0; }
        .list-row p { margin:2px 0 0; color:var(--muted); }
        .pill { display:inline-flex; padding:4px 9px; border-radius:999px; background:#27272a; color:#d4d4d8; font-size:.78rem; font-weight:800; }
        .pill.live { background:var(--brand-dark); color:#bef264; }
        table { width:100%; border-collapse:collapse; }
        th,td { padding:13px 10px; border-bottom:1px solid var(--line); text-align:left; }
        th { color:var(--muted); font-size:.78rem; text-transform:uppercase; letter-spacing:.07em; }
        td:last-child,th:last-child { text-align:right; }
        .empty { padding:35px; text-align:center; border:1px dashed var(--line); border-radius:14px; color:var(--muted); }
        .success-panel { max-width:650px; margin:7vh auto; padding:40px; text-align:center; border:1px solid var(--line); border-radius:24px; background:var(--panel); }
        .success-panel .big-icon { font-size:4rem; }
        @media (max-width:850px) { .action-grid,.menu-picker { grid-template-columns:1fr 1fr; } .grid-2 { grid-template-columns:1fr; } .topbar { align-items:flex-start; padding:12px 0; } nav { justify-content:flex-end; } .nav-link span.label { display:none; } }
        @media (max-width:560px) { .shell { width:min(100% - 20px,1180px); } .action-grid,.menu-picker,.stats { grid-template-columns:1fr; } .action-card { min-height:170px; } .card { padding:18px; } .list-row { align-items:flex-start; flex-wrap:wrap; } h1 { font-size:2.6rem; } }
    </style>
</head>
<body>
<header>
    <div class="shell topbar">
        <a class="logo" href="?view=home"><span class="logo-mark">C</span><span>Cafeteria</span></a>
        <nav aria-label="Main navigation">
            <a class="nav-link <?= $view === 'register' ? 'active' : '' ?>" href="?view=register"><span>＋</span><span class="label">Add student</span></a>
            <a class="nav-link <?= $view === 'pos' ? 'active' : '' ?>" href="?view=pos"><span>🍽️</span><span class="label">Serve lunch</span></a>
            <a class="nav-link <?= in_array($view, ['dashboard','menu','students'], true) ? 'active' : '' ?>" href="?view=dashboard"><span>▥</span><span class="label">Business</span></a>
        </nav>
    </div>
</header>
<main class="shell">
    <?php if ($flash): ?><div class="flash <?= $flash['type'] === 'error' ? 'error' : '' ?>" role="status"><?= e($flash['message']) ?></div><?php endif; ?>

    <?php if ($view === 'home'): ?>
        <section class="page-head"><div class="eyebrow">School lunch, simplified</div><h1>What would you like to do?</h1><p class="lede">Choose a task and get right to it.</p></section>
        <div class="action-grid">
            <a class="action-card" href="?view=register"><span class="big-icon">👤＋</span><div><h2>Add a student</h2><p>Parent or guardian setup for a child’s cafeteria profile.</p></div></a>
            <a class="action-card" href="?view=pos"><span class="big-icon">🍽️</span><div><h2>Serve lunch</h2><p>Pick a student and tap the items being served.</p></div></a>
            <a class="action-card" href="?view=dashboard"><span class="big-icon">📊</span><div><h2>Run the cafeteria</h2><p>View monthly sales, manage the menu, and see students.</p></div></a>
        </div>

    <?php elseif ($view === 'register'): ?>
        <section class="page-head"><div class="eyebrow">Parent & guardian setup</div><h1>Add your child</h1><p class="lede">This profile helps cafeteria staff serve the right meal at the right time.</p></section>
        <form class="stack card" method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="add_student">
            <div class="grid-2">
                <label><span>Student name</span><input name="student_name" autocomplete="name" required placeholder="Jordan Lee"></label>
                <label><span>Grade</span><select name="grade" required><option value="">Choose grade</option><option>Pre-K</option><option>Kindergarten</option><?php for ($g=1;$g<=12;$g++): ?><option>Grade <?= $g ?></option><?php endfor; ?></select></label>
                <label><span>Lunch time</span><input type="time" name="lunch_time" required></label>
                <label><span>Parent or guardian name</span><input name="parent_name" required placeholder="Taylor Lee"></label>
            </div>
            <label><span>Meal preferences <small>— free form</small></span><textarea name="meal_preferences" placeholder="Examples: vegetarian, no pork, prefers fruit, texture sensitivities…"></textarea></label>
            <label><span>Contact email <small>— optional</small></span><input type="email" name="contact_email" autocomplete="email" placeholder="parent@example.com"></label>
            <div class="form-actions"><button class="button primary" type="submit"><span>✓</span> Create student profile</button><a class="button" href="?view=home">← Cancel</a></div>
        </form>

    <?php elseif ($view === 'student-success'): ?>
        <section class="success-panel"><div class="big-icon">✅</div><h1>All set!</h1><p class="lede" style="margin:0 auto 28px">The student profile has been created and is ready for cafeteria staff.</p><div class="form-actions" style="justify-content:center"><a class="button primary" href="?view=register">＋ Add another student</a><a class="button" href="?view=home">⌂ Home</a></div></section>

    <?php elseif ($view === 'pos'): ?>
        <section class="page-head"><div class="eyebrow">Cafeteria counter</div><h1>Serve lunch</h1><p class="lede">Choose the student, tap each item, then record the meal.</p></section>
        <?php if (!$students): ?><div class="empty"><h2>No students yet</h2><p>Add the first student before recording a meal.</p><a class="button primary" href="?view=register">＋ Add student</a></div>
        <?php else: ?><form class="stack" method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="record_sale">
            <div class="card"><label><span>1. Choose student</span><select name="student_id" required><option value="">Select a student</option><?php foreach ($students as $student): ?><option value="<?= (int)$student['id'] ?>"><?= e($student['student_name']) ?> · <?= e($student['grade']) ?> · <?= e(date('g:i A', strtotime($student['lunch_time']))) ?></option><?php endforeach; ?></select></label></div>
            <div class="card"><h2>2. Tap menu items</h2><div class="menu-picker"><?php foreach ($products as $product): if (!(int)$product['active']) continue; ?><label class="pick"><input type="checkbox" name="products[]" value="<?= (int)$product['id'] ?>"><span class="pick-body"><strong><?= e($product['name']) ?></strong><span class="pick-price"><?= money((int)$product['price_cents']) ?></span></span></label><?php endforeach; ?></div></div>
            <div class="card"><label><span>Meal note <small>— optional</small></span><input name="note" placeholder="Anything staff should remember about this meal"></label></div>
            <div class="form-actions"><button class="button primary" type="submit">✓ Record meal</button><a class="button" href="?view=home">← Cancel</a></div>
        </form><?php endif; ?>

    <?php elseif ($view === 'dashboard'): ?>
        <section class="page-head"><div class="eyebrow">Business overview</div><h1><?= e(date('F Y')) ?></h1><p class="lede">A quick view of cafeteria activity.</p></section>
        <div class="stats"><div class="stat"><span class="muted">Sales this month</span><strong><?= money((int)$summary['total']) ?></strong></div><div class="stat"><span class="muted">Meals served</span><strong><?= (int)$summary['meals'] ?></strong></div><div class="stat"><span class="muted">Students</span><strong><?= count($students) ?></strong></div></div>
        <div class="action-grid" style="margin:0 0 22px"><a class="action-card" style="min-height:150px" href="?view=pos"><span class="big-icon">🍽️</span><div><h2>Serve lunch</h2></div></a><a class="action-card" style="min-height:150px" href="?view=menu"><span class="big-icon">✏️</span><div><h2>Manage menu</h2></div></a><a class="action-card" style="min-height:150px" href="?view=students"><span class="big-icon">👥</span><div><h2>View students</h2></div></a></div>
        <div class="grid-2"><section class="card"><h2>Recent sales</h2><?php if (!$recentSales): ?><div class="empty">No meals recorded yet.</div><?php else: ?><div style="overflow:auto"><table><thead><tr><th>Student</th><th>When</th><th>Total</th></tr></thead><tbody><?php foreach ($recentSales as $sale): ?><tr><td><?= e($sale['student_name']) ?></td><td><?= e(date('M j, g:i A', strtotime($sale['served_at']))) ?></td><td><?= money((int)$sale['total_cents']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
        <section class="card"><h2>Popular this month</h2><?php if (!$topItems): ?><div class="empty">Items appear here after meals are recorded.</div><?php else: ?><div class="list"><?php foreach ($topItems as $item): ?><div class="list-row"><div class="grow"><strong><?= e($item['product_name']) ?></strong></div><span class="pill live"><?= (int)$item['quantity'] ?> served</span></div><?php endforeach; ?></div><?php endif; ?></section></div>
        <?php if ($monthlySales): ?><section class="card" style="margin-top:18px"><h2>Sales by month</h2><div style="overflow:auto"><table><thead><tr><th>Month</th><th>Meals</th><th>Sales</th></tr></thead><tbody><?php foreach ($monthlySales as $month): ?><tr><td><?= e(date('F Y', strtotime($month['month'].'-01'))) ?></td><td><?= (int)$month['meals'] ?></td><td><?= money((int)$month['total']) ?></td></tr><?php endforeach; ?></tbody></table></div></section><?php endif; ?>

    <?php elseif ($view === 'menu'): ?>
        <section class="page-head"><div class="eyebrow">Business · Menu</div><h1>Manage menu</h1><p class="lede">Add items and control what appears at the cafeteria counter.</p></section>
        <div class="grid-2"><form class="stack card" method="post"><h2>Add a menu item</h2><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="add_product"><label><span>Item name</span><input name="name" required placeholder="Turkey sandwich"></label><label><span>Description <small>— optional</small></span><textarea name="description" placeholder="Short description"></textarea></label><label><span>Price</span><input name="price" type="number" required min="0" step="0.01" inputmode="decimal" placeholder="4.50"></label><button class="button primary" type="submit">＋ Add to menu</button></form>
        <section><div class="list"><?php foreach ($products as $product): ?><div class="list-row"><div class="grow"><h3><?= e($product['name']) ?> <span class="pill <?= (int)$product['active'] ? 'live' : '' ?>"><?= (int)$product['active'] ? 'Available' : 'Hidden' ?></span></h3><p><?= money((int)$product['price_cents']) ?><?= $product['description'] ? ' · '.e($product['description']) : '' ?></p></div><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="toggle_product"><input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>"><button class="button" type="submit"><?= (int)$product['active'] ? '◉ Hide' : '○ Show' ?></button></form></div><?php endforeach; ?></div></section></div>

    <?php elseif ($view === 'students'): ?>
        <section class="page-head"><div class="eyebrow">Business · Students</div><h1>Students</h1><p class="lede"><?= count($students) ?> cafeteria profile<?= count($students) === 1 ? '' : 's' ?>.</p></section>
        <div class="form-actions" style="margin-bottom:20px"><a class="button primary" href="?view=register">＋ Add student</a><a class="button" href="?view=dashboard">← Business overview</a></div>
        <?php if (!$students): ?><div class="empty">No students yet.</div><?php else: ?><div class="list"><?php foreach ($students as $student): ?><article class="list-row"><div class="grow"><h3><?= e($student['student_name']) ?></h3><p><?= e($student['grade']) ?> · Lunch at <?= e(date('g:i A', strtotime($student['lunch_time']))) ?> · Guardian: <?= e($student['parent_name']) ?></p><?php if ($student['meal_preferences']): ?><p><strong>Meal preferences:</strong> <?= nl2br(e($student['meal_preferences'])) ?></p><?php endif; ?></div><span class="pill live">Active</span></article><?php endforeach; ?></div><?php endif; ?>
    <?php endif; ?>
</main>
</body>
</html>
