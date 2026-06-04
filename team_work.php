<?php
declare(strict_types=1);

require_once __DIR__ . '/session_check.php';

$pageTitle   = 'Team of Work';
$currentPage = 'team_work';
$basePath    = '';

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$teamMembers = [
    [
        'name'        => 'First person',
        'age'         => 24,
        'role'        => 'Main Programmer',
        'job'         => 'Responsible for developing the core PHP system, database connection, exam creation, question bank, and AI generation workflow.',
        'image'       => 'images/Test1.jpg',
        'icon'        => '💻',
        'tag'         => 'Backend & System Logic'
    ],
    [
        'name'        => 'Second person',
        'age'         => 23,
        'role'        => 'Assistant Programmer',
        'job'         => 'Assists in writing code, testing system functions, fixing errors, and supporting the integration between pages.',
        'image'       => 'images/Test2.jpg',
        'icon'        => '🧠',
        'tag'         => 'Testing & Integration'
    ],
    [
        'name'        => 'Third person',
        'age'         => 23,
        'role'        => 'Designer',
        'job'         => 'Responsible for designing the user interface, improving the visual layout, colors, cards, buttons, and overall user experience.',
        'image'       => 'images/Test3.jpg',
        'icon'        => '🎨',
        'tag'         => 'UI / UX Design'
    ],
];

$pageStyles = <<<CSS
.team-hero {
    position: relative;
    overflow: hidden;
    border-radius: 28px;
    padding: 42px;
    margin-top: 28px;
    margin-bottom: 28px;
    color: #fff;
    background:
        radial-gradient(circle at top left, rgba(255,255,255,0.24), transparent 28%),
        radial-gradient(circle at bottom right, rgba(6,182,212,0.35), transparent 30%),
        linear-gradient(135deg, #2563eb 0%, #0891b2 48%, #0f172a 100%);
    box-shadow: var(--shadow-lg);
}

.team-hero::before {
    content: "";
    position: absolute;
    width: 260px;
    height: 260px;
    right: -70px;
    top: -70px;
    border-radius: 50%;
    background: rgba(255,255,255,0.12);
}

.team-hero::after {
    content: "";
    position: absolute;
    width: 180px;
    height: 180px;
    left: -55px;
    bottom: -55px;
    border-radius: 50%;
    background: rgba(255,255,255,0.10);
}

.team-hero-content {
    position: relative;
    z-index: 2;
    max-width: 760px;
}

.team-kicker {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 9px 15px;
    border-radius: 999px;
    background: rgba(255,255,255,0.16);
    border: 1px solid rgba(255,255,255,0.20);
    font-size: 13px;
    font-weight: 800;
    margin-bottom: 18px;
}

.team-hero h1 {
    margin: 0 0 12px;
    color: #fff;
    font-size: clamp(30px, 4vw, 48px);
    font-weight: 900;
    letter-spacing: -0.03em;
}

.team-hero p {
    margin: 0;
    color: rgba(255,255,255,0.92);
    font-size: 15px;
    line-height: 1.9;
}

.team-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 24px;
    margin-bottom: 28px;
}

.team-card {
    position: relative;
    overflow: hidden;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 28px;
    box-shadow: var(--shadow-sm);
    transition: all 0.30s ease;
}

.team-card:hover {
    transform: translateY(-8px);
    box-shadow: var(--shadow-lg);
    border-color: rgba(37, 99, 235, 0.35);
}

.team-card-top {
    position: relative;
    height: 220px;
    overflow: hidden;
    background:
        radial-gradient(circle at top left, rgba(37,99,235,0.22), transparent 35%),
        linear-gradient(135deg, var(--primary-light), var(--accent-light));
}

.team-card-top img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.35s ease;
}

.team-card:hover .team-card-top img {
    transform: scale(1.08);
}

.team-floating-icon {
    position: absolute;
    right: 18px;
    bottom: -28px;
    width: 64px;
    height: 64px;
    border-radius: 20px;
    display: grid;
    place-items: center;
    font-size: 27px;
    background: linear-gradient(135deg, var(--primary), var(--accent));
    color: #fff;
    border: 5px solid var(--bg-card);
    box-shadow: var(--shadow-md);
    z-index: 3;
}

.team-card-body {
    padding: 34px 22px 24px;
}

.team-role {
    display: inline-flex;
    align-items: center;
    padding: 8px 12px;
    border-radius: 999px;
    background: var(--primary-light);
    color: var(--primary-dark);
    font-size: 12px;
    font-weight: 900;
    margin-bottom: 12px;
}

.team-name {
    margin: 0 0 8px;
    font-size: 22px;
    font-weight: 900;
    color: var(--text-main);
}

.team-age {
    display: flex;
    align-items: center;
    gap: 8px;
    color: var(--text-muted);
    font-size: 14px;
    font-weight: 700;
    margin-bottom: 14px;
}

.team-job {
    color: var(--text-soft);
    font-size: 14px;
    line-height: 1.85;
    min-height: 128px;
    margin-bottom: 18px;
}

.team-tag {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 12px 14px;
    border-radius: 16px;
    background: var(--bg-card-2);
    border: 1px dashed var(--border-strong);
    color: var(--text-main);
    font-size: 13px;
    font-weight: 900;
}

.team-summary {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 18px;
    margin-bottom: 28px;
}

.team-summary-box {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 22px;
    padding: 22px;
    box-shadow: var(--shadow-sm);
}

.team-summary-box h3 {
    margin: 0 0 8px;
    font-size: 18px;
    font-weight: 900;
}

.team-summary-box p {
    margin: 0;
    color: var(--text-muted);
    font-size: 14px;
    line-height: 1.8;
}

@media (max-width: 1100px) {
    .team-grid,
    .team-summary {
        grid-template-columns: 1fr;
    }

    .team-card-top {
        height: 280px;
    }
}

@media (max-width: 700px) {
    .team-hero {
        padding: 28px 22px;
    }

    .team-card-top {
        height: 230px;
    }
}
CSS;

require_once __DIR__ . '/include/header.php';
require_once __DIR__ . '/include/menu.php';
?>

<main class="app-main">
    <div class="container">

        <section class="team-hero">
            <div class="team-hero-content">
                <div class="team-kicker">🚀 Project Development Team</div>
                <h1>Team of Work</h1>
                <p>
                    This page presents the project team members who contributed to the development of the
                    Automatic Exam Generator system, including programming, system integration, testing,
                    and user interface design.
                </p>
            </div>
        </section>

        <section class="team-grid">
            <?php foreach ($teamMembers as $member): ?>
                <article class="team-card">
                    <div class="team-card-top">
                        <img src="<?= e($member['image']) ?>" alt="<?= e($member['name']) ?>">
                        <div class="team-floating-icon"><?= e($member['icon']) ?></div>
                    </div>

                    <div class="team-card-body">
                        <div class="team-role"><?= e($member['role']) ?></div>

                        <h2 class="team-name"><?= e($member['name']) ?></h2>

                        <div class="team-age">
                            <span>🎂</span>
                            <span>Age: <?= e($member['age']) ?> years</span>
                        </div>

                        <p class="team-job">
                            <?= e($member['job']) ?>
                        </p>

                        <div class="team-tag">
                            <?= e($member['tag']) ?>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>

        <section class="team-summary">
            <div class="team-summary-box">
                <h3>💻 Development</h3>
                <p>
                    Building the main system pages, database operations, authentication, and exam-generation logic.
                </p>
            </div>

            <div class="team-summary-box">
                <h3>🧪 Testing</h3>
                <p>
                    Checking system functions, reviewing page behavior, and ensuring that the workflow runs correctly.
                </p>
            </div>

            <div class="team-summary-box">
                <h3>🎨 Design</h3>
                <p>
                    Creating a clean, modern, and user-friendly interface for instructors and system users.
                </p>
            </div>
        </section>

    </div>
</main>

<?php require_once __DIR__ . '/include/footer.php'; ?>