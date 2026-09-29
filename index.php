<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
    session_start();
}
function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function go(string $path): never { header('Location: ' . $path, true, 303); exit; }
function user(): ?array { return $_SESSION['user'] ?? null; }
function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function valid_csrf(): void {
    if (!hash_equals(csrf(), (string)($_POST['csrf'] ?? ''))) { http_response_code(403); exit('Invalid form token. Reload and try again.'); }
}
function login_required(): void { if (!user()) go('/login'); }
function page(string $heading, string $content): void {
    $auth = user(); $token = h(csrf());
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . h($heading) . ' | Tasks for Today</title><style>body{font:16px system-ui;max-width:850px;margin:32px auto;padding:0 18px;color:#233}nav{display:flex;gap:15px;align-items:center;flex-wrap:wrap}main{margin-top:32px}a{color:#075ba9}input,textarea{display:block;width:100%;max-width:520px;padding:9px;margin:6px 0 16px;box-sizing:border-box}button{padding:8px 13px;cursor:pointer}article{border:1px solid #ddd;padding:14px;margin:12px 0;border-radius:8px}.error{color:#a11}.inline{display:inline}.inline button{margin-left:10px}</style></head><body><nav><a href="/">Welcome</a><a href="/tasks">Task List</a><a href="/profile">Profile</a><a href="/about">About</a>';
    if ($auth) echo '<a href="/tasks/new">New Task</a><span>Signed in as ' . h($auth['name']) . '</span><form class="inline" method="post" action="/logout"><input type="hidden" name="csrf" value="' . $token . '"><button>Log out</button></form>';
    else echo '<a href="/login">Log in</a>';
    echo '</nav><main><h1>' . h($heading) . '</h1>' . $content . '</main></body></html>';
}
function task_form(string $action, array $values, array $errors = []): string {
    $out = '<form method="post" action="' . h($action) . '"><input type="hidden" name="csrf" value="' . h(csrf()) . '">';
    foreach ($errors as $error) $out .= '<p class="error">' . h($error) . '</p>';
    $out .= '<label>Title *<input name="title" maxlength="200" required value="' . h($values['title'] ?? '') . '"></label>';
    $out .= '<label>Date *<input type="date" name="task_date" required value="' . h($values['task_date'] ?? '') . '"></label>';
    $out .= '<label>Description<textarea name="description">' . h($values['description'] ?? '') . '</textarea></label><button>Save task</button></form>';
    return $out;
}
function task_values(): array {
    return ['title' => trim((string)($_POST['title'] ?? '')), 'task_date' => trim((string)($_POST['task_date'] ?? '')), 'description' => trim((string)($_POST['description'] ?? ''))];
}
function errors(array $v): array {
    $e = [];
    if ($v['title'] === '') $e[] = 'Title is required.';
    if (mb_strlen($v['title']) > 200) $e[] = 'Title must be 200 characters or fewer.';
    if ($v['task_date'] === '') $e[] = 'Date is required.';
    else { $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v['task_date']); if (!$d || $d->format('Y-m-d') !== $v['task_date']) $e[] = 'Enter a valid date.'; }
    return $e;
}
function owned_task(int $id): array {
    $q = db()->prepare('SELECT * FROM tasks WHERE id = ? AND user_id = ? AND is_archived = 0');
    $q->execute([$id, user()['id']]); $task = $q->fetch();
    if (!$task) { http_response_code(404); page('Not found', '<p>Task not found.</p>'); exit; }
    return $task;
}
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = rtrim($path, '/') ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($path === '/login' && $method === 'GET') {
    page('Log in', '<form method="post"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><label>Email<input type="email" name="email" required></label><label>Password<input type="password" name="password" required></label><button>Log in</button></form>'); exit;
}
if ($path === '/login' && $method === 'POST') {
    valid_csrf(); $q = db()->prepare('SELECT id, name, email, password FROM users WHERE email = ?'); $q->execute([trim((string)($_POST['email'] ?? ''))]); $found = $q->fetch();
    if (!$found || !password_verify((string)($_POST['password'] ?? ''), $found['password'])) { http_response_code(401); page('Log in', '<p class="error">Incorrect email or password.</p><p><a href="/login">Try again</a></p>'); exit; }
    session_regenerate_id(true); $_SESSION['user'] = ['id' => (int)$found['id'], 'name' => $found['name']]; go('/tasks');
}
if ($path === '/logout' && $method === 'POST') { valid_csrf(); $_SESSION = []; session_regenerate_id(true); go('/'); }
if ($path === '/tasks/new' && $method === 'GET') { login_required(); page('New Task', task_form('/tasks/new', [])); exit; }
if ($path === '/tasks/new' && $method === 'POST') {
    login_required(); valid_csrf(); $v = task_values(); $e = errors($v);
    if ($e) { http_response_code(422); page('New Task', task_form('/tasks/new', $v, $e)); exit; }
    $q = db()->prepare('INSERT INTO tasks (user_id, title, task_date, description) VALUES (?, ?, ?, ?)');
    $q->execute([user()['id'], $v['title'], $v['task_date'], $v['description']]); go('/tasks');
}
if (preg_match('~^/tasks/([1-9][0-9]*)/edit$~', $path, $m)) {
    login_required(); $task = owned_task((int)$m[1]);
    if ($method === 'GET') { page('Edit Task', task_form($path, $task)); exit; }
    if ($method === 'POST') {
        valid_csrf(); $v = task_values(); $e = errors($v);
        if ($e) { http_response_code(422); page('Edit Task', task_form($path, $v, $e)); exit; }
        $q = db()->prepare('UPDATE tasks SET title = ?, task_date = ?, description = ? WHERE id = ? AND user_id = ? AND is_archived = 0');
        $q->execute([$v['title'], $v['task_date'], $v['description'], $task['id'], user()['id']]); go('/tasks');
    }
}
if (preg_match('~^/tasks/([1-9][0-9]*)/delete$~', $path, $m) && $method === 'POST') {
    login_required(); valid_csrf(); $task = owned_task((int)$m[1]);
    $q = db()->prepare('UPDATE tasks SET is_archived = 1 WHERE id = ? AND user_id = ? AND is_archived = 0');
    $q->execute([$task['id'], user()['id']]); go('/tasks');
}
if (($path === '/' || $path === '/tasks') && $method === 'GET') {
    $q = db()->query('SELECT tasks.id, tasks.title, tasks.description, tasks.task_date, tasks.user_id FROM tasks WHERE tasks.is_archived = 0 ORDER BY tasks.task_date ASC, tasks.id ASC');
    $items = $q->fetchAll(); $out = $path === '/' ? '<p>See what is coming up.</p>' : '<p>All active tasks.</p>';
    foreach ($items as $t) {
        $out .= '<article><h2>' . h($t['title']) . '</h2><p>Date: ' . h($t['task_date']) . '</p><p>' . nl2br(h($t['description'])) . '</p>';
        if (user() && user()['id'] === (int)$t['user_id']) $out .= '<a href="/tasks/' . (int)$t['id'] . '/edit">Edit</a><form class="inline" method="post" action="/tasks/' . (int)$t['id'] . '/delete" onsubmit="return confirm(\'Archive this task?\')"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><button>Delete</button></form>';
        $out .= '</article>';
    }
    if (!$items) $out .= '<p>No active tasks yet.</p>';
    page($path === '/' ? 'Welcome' : 'Task List', $out); exit;
}
if ($path === '/profile' && $method === 'GET') { page('Profile', '<p>This is the public profile page. Log in to manage your tasks.</p>'); exit; }
if ($path === '/about' && $method === 'GET') { page('About', '<p>Tasks for Today helps you organize and track your tasks.</p>'); exit; }
http_response_code(404); page('Not found', '<p>Page not found.</p>');
