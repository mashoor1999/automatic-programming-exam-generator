<?php
$currentPage = $currentPage ?? '';
$basePath    = $basePath ?? '';

$userName = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User';
$userRole = strtolower((string)($_SESSION['role'] ?? 'teacher'));

$displayRole = ucfirst($userRole);

$trimmedUserName = trim((string)$userName);
$avatarLetter = 'A';

if ($trimmedUserName !== '') {
    if (function_exists('mb_substr') && function_exists('mb_strtoupper')) {
        $avatarLetter = mb_strtoupper(mb_substr($trimmedUserName, 0, 1, 'UTF-8'), 'UTF-8');
    } else {
        $avatarLetter = strtoupper(substr($trimmedUserName, 0, 1));
    }
}

/*
|--------------------------------------------------------------------------
| Role Based Menu
|--------------------------------------------------------------------------
| Admin: Dashboard + Accounts + Team of Work + Logout
| Teacher: Dashboard + Create Exam + View Exam + AI Generate + Question Bank + Team of Work + Logout
*/
if ($userRole === 'admin') {
    $menuItems = [
        [
            'key'   => 'dashboard',
            'title' => 'Dashboard',
            'icon'  => '🏠',
            'link'  => 'dashboard.php'
        ],
        [
            'key'   => 'accounts',
            'title' => 'Accounts',
            'icon'  => '👥',
            'link'  => 'accounts.php'
        ],
        [
            'key'   => 'team_work',
            'title' => 'Team of Work',
            'icon'  => '🤝',
            'link'  => 'team_work.php'
        ],
    ];
} else {
    $menuItems = [
        [
            'key'   => 'dashboard',
            'title' => 'Dashboard',
            'icon'  => '🏠',
            'link'  => 'dashboard.php'
        ],
        [
            'key'   => 'generate_exam',
            'title' => 'Create Exam',
            'icon'  => '📝',
            'link'  => 'generate_exam.php'
        ],
        [
            'key'   => 'view_exams',
            'title' => 'View Exam',
            'icon'  => '📘',
            'link'  => 'view_exams.php'
        ],
        [
            'key'   => 'ai_generate_questions',
            'title' => 'AI Generate',
            'icon'  => '🤖',
            'link'  => 'ai_generate_questions.php'
        ],
        [
            'key'   => 'question_bank',
            'title' => 'Question Bank',
            'icon'  => '🗂️',
            'link'  => 'question_bank.php'
        ],
        [
            'key'   => 'team_work',
            'title' => 'Team of Work',
            'icon'  => '🤝',
            'link'  => 'team_work.php'
        ],
    ];
}
?>

<aside class="app-sidebar">
    <div class="sidebar-brand-card">
        <div class="sidebar-brand-icon">AG</div>
        <div class="sidebar-brand-text">
            <h3>Exam Generator</h3>
            <p>Programming Exam System</p>
        </div>
    </div>

    <div class="sidebar-user-card sidebar-user-card-luxury">
        <div class="sidebar-avatar-wrap">
            <div class="sidebar-avatar">
                <?= htmlspecialchars($avatarLetter, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <span class="sidebar-status-dot"></span>
        </div>

        <div class="sidebar-user-info">
            <h4><?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?></h4>
            <p><?= htmlspecialchars($displayRole, ENT_QUOTES, 'UTF-8') ?></p>
        </div>
    </div>

    <div class="sidebar-section-label">MAIN MENU</div>

    <nav class="sidebar-nav">
        <?php foreach ($menuItems as $item): ?>
            <a href="<?= htmlspecialchars($basePath . $item['link'], ENT_QUOTES, 'UTF-8') ?>"
               class="sidebar-link sidebar-link-fancy <?= $currentPage === $item['key'] ? 'active' : '' ?>">
                <span class="sidebar-link-icon"><?= $item['icon'] ?></span>
                <span class="sidebar-link-text"><?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?></span>
                <span class="sidebar-link-arrow">›</span>
            </a>
        <?php endforeach; ?>

        <a href="<?= htmlspecialchars($basePath . 'logout.php', ENT_QUOTES, 'UTF-8') ?>"
           class="sidebar-link sidebar-link-fancy sidebar-link-danger">
            <span class="sidebar-link-icon">🚪</span>
            <span class="sidebar-link-text">Logout</span>
            <span class="sidebar-link-arrow">›</span>
        </a>
    </nav>
</aside>