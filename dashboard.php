<?php
declare(strict_types=1);

require_once __DIR__ . '/session_check.php';
require_once __DIR__ . '/Data/db.php';

$pdoConnection = null;

if (isset($pdo) && $pdo instanceof PDO) {
    $pdoConnection = $pdo;
} elseif (isset($conn) && $conn instanceof PDO) {
    $pdoConnection = $conn;
} elseif (isset($db) && $db instanceof PDO) {
    $pdoConnection = $db;
}

if (!$pdoConnection) {
    die('Database connection error: PDO connection was not found in db.php');
}

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function formatDateTime(?string $dateTime): string
{
    if (!$dateTime) {
        return '-';
    }

    try {
        $dt = new DateTime($dateTime);
        return $dt->format('d M Y - h:i A');
    } catch (Throwable $e) {
        return (string)$dateTime;
    }
}

function excerptText(?string $text, int $length = 90): string
{
    $text = trim((string)$text);

    if ($text === '') {
        return '-';
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($text, 'UTF-8') > $length
            ? mb_substr($text, 0, $length, 'UTF-8') . '...'
            : $text;
    }

    return strlen($text) > $length
        ? substr($text, 0, $length) . '...'
        : $text;
}

function statusBadgeClass(string $status): string
{
    return match ($status) {
        'draft'     => 'badge-warning',
        'ready'     => 'badge-info',
        'generated' => 'badge-success',
        'published' => 'badge-dark',
        default     => 'badge-primary',
    };
}

function difficultyBadgeClass(string $difficulty): string
{
    return match ($difficulty) {
        'Easy'   => 'badge-success',
        'Medium' => 'badge-info',
        'Hard'   => 'badge-danger',
        'Mixed'  => 'badge-warning',
        default  => 'badge-primary',
    };
}

function questionTypeLabel(string $type): string
{
    return match ($type) {
        'mcq'               => 'MCQ',
        'true_false'        => 'True / False',
        'short_answer'      => 'Short Answer',
        'code_writing'      => 'Code Writing',
        'debugging'         => 'Debugging',
        'output_prediction' => 'Output Prediction',
        default             => ucfirst(str_replace('_', ' ', $type)),
    };
}

$isAdmin = strtolower((string)($_SESSION['role'] ?? '')) === 'admin';

try {
    $examStatsStmt = $pdoConnection->query("\n        SELECT\n            COUNT(*) AS total_exams,\n            SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) AS draft_count,\n            SUM(CASE WHEN status = 'ready' THEN 1 ELSE 0 END) AS ready_count,\n            SUM(CASE WHEN status = 'generated' THEN 1 ELSE 0 END) AS generated_count,\n            SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) AS published_count\n        FROM exams\n    ");
    $examStats = $examStatsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $questionStatsStmt = $pdoConnection->query("\n        SELECT\n            COUNT(*) AS total_questions,\n            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved_questions,\n            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_questions,\n            SUM(CASE WHEN status = 'archived' THEN 1 ELSE 0 END) AS archived_questions,\n            SUM(CASE WHEN source_type = 'ai' THEN 1 ELSE 0 END) AS ai_questions,\n            SUM(CASE WHEN source_type = 'manual' THEN 1 ELSE 0 END) AS manual_questions\n        FROM question_bank\n    ");
    $questionStats = $questionStatsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $linkedQuestionsCount = (int)$pdoConnection->query("\n        SELECT COUNT(*)\n        FROM exam_question_bank\n    ")->fetchColumn();

    $userStats = [
        'total_users' => 0,
        'admin_count' => 0,
        'teacher_count' => 0,
    ];

    if ($isAdmin) {
        $userStatsStmt = $pdoConnection->query("\n            SELECT\n                COUNT(*) AS total_users,\n                SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) AS admin_count,\n                SUM(CASE WHEN role = 'teacher' THEN 1 ELSE 0 END) AS teacher_count\n            FROM users\n        ");
        $userStats = $userStatsStmt->fetch(PDO::FETCH_ASSOC) ?: $userStats;
    }

    /* Teacher-only detailed data */
    $recentExams = [];
    $recentQuestions = [];

    if (!$isAdmin) {
        $recentExamsStmt = $pdoConnection->query("\n            SELECT\n                e.*,\n                u.full_name AS creator_name,\n                COALESCE(eq.linked_questions, 0) AS linked_questions\n            FROM exams e\n            LEFT JOIN users u\n                ON u.id = e.user_id\n            LEFT JOIN (\n                SELECT exam_id, COUNT(*) AS linked_questions\n                FROM exam_question_bank\n                GROUP BY exam_id\n            ) eq\n                ON eq.exam_id = e.id\n            ORDER BY e.id DESC\n            LIMIT 6\n        ");
        $recentExams = $recentExamsStmt->fetchAll(PDO::FETCH_ASSOC);

        $recentQuestionsStmt = $pdoConnection->query("\n            SELECT\n                qb.*,\n                u.full_name AS created_by_name\n            FROM question_bank qb\n            LEFT JOIN users u\n                ON u.id = qb.created_by\n            ORDER BY qb.id DESC\n            LIMIT 6\n        ");
        $recentQuestions = $recentQuestionsStmt->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (Throwable $e) {
    die('Failed to load dashboard data: ' . e($e->getMessage()));
}

$pageTitle   = 'Dashboard';
$currentPage = 'dashboard';
$basePath    = '';

$pageStyles = <<<CSS
.quick-links-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 18px;
    margin-top: 24px;
}

.quick-link-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 20px;
    padding: 22px;
    box-shadow: var(--shadow-sm);
    transition: var(--transition);
    height: 100%;
}

.quick-link-card:hover {
    transform: translateY(-3px);
    box-shadow: var(--shadow-md);
}

.quick-link-icon {
    width: 54px;
    height: 54px;
    border-radius: 16px;
    display: grid;
    place-items: center;
    font-size: 24px;
    color: #fff;
    background: linear-gradient(135deg, var(--primary), var(--accent));
    margin-bottom: 14px;
    box-shadow: var(--shadow-sm);
}

.quick-link-card h3 {
    margin-bottom: 8px;
}

.quick-link-card p {
    margin-bottom: 16px;
}

.dashboard-stat-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 18px;
    margin-top: 24px;
}

.dashboard-section-grid {
    display: grid;
    grid-template-columns: 1.4fr 1fr;
    gap: 20px;
    margin-top: 24px;
}

.small-stat-row {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
    margin-top: 18px;
}

.small-stat-box {
    background: var(--bg-card-2);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 16px;
}

.small-stat-box h4 {
    margin-bottom: 6px;
    font-size: 13px;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.4px;
}

.small-stat-box .value {
    font-size: 24px;
    font-weight: 800;
    color: var(--text-main);
    line-height: 1.2;
}

.note-box {
    background: var(--info-light);
    border: 1px solid rgba(8, 145, 178, 0.15);
    color: #155e75;
    padding: 16px 18px;
    border-radius: 16px;
    margin-top: 20px;
    font-weight: 600;
}

.recent-list {
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.recent-item {
    background: var(--bg-card-2);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 16px;
}

.recent-item-top {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 10px;
}

.recent-item h4 {
    margin-bottom: 6px;
    font-size: 16px;
}

.recent-item p {
    margin-bottom: 0;
}

.meta-inline {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.table-compact .custom-table {
    min-width: 980px;
}

.exam-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.exam-actions .btn {
    min-width: 84px;
}

.admin-stats-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 18px;
    margin-top: 24px;
}

.admin-stat-card {
    background: linear-gradient(135deg, var(--bg-card), var(--bg-card-2));
    border: 1px solid var(--border);
    border-radius: 22px;
    padding: 22px;
    box-shadow: var(--shadow-sm);
    position: relative;
    overflow: hidden;
}

.admin-stat-card::after {
    content: "";
    position: absolute;
    inset-inline-end: -32px;
    top: -32px;
    width: 120px;
    height: 120px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(37,99,235,0.12), transparent 70%);
}

.admin-stat-icon {
    width: 52px;
    height: 52px;
    border-radius: 16px;
    display: grid;
    place-items: center;
    margin-bottom: 14px;
    background: linear-gradient(135deg, var(--primary), var(--accent));
    color: #fff;
    font-size: 22px;
    box-shadow: var(--shadow-sm);
}

.admin-stat-label {
    color: var(--text-muted);
    font-size: 14px;
    margin-bottom: 8px;
    font-weight: 700;
}

.admin-stat-number {
    font-size: 34px;
    line-height: 1.1;
    font-weight: 900;
    color: var(--text-main);
}

.admin-stat-note {
    margin-top: 8px;
    color: var(--text-soft);
    font-size: 13px;
}

.admin-only-note {
    margin-top: 24px;
    background: var(--info-light);
    color: #155e75;
    border: 1px solid rgba(8, 145, 178, 0.18);
    border-radius: 18px;
    padding: 18px 20px;
    font-weight: 700;
}

@media (max-width: 1200px) {
    .quick-links-grid,
    .dashboard-stat-grid,
    .small-stat-row,
    .admin-stats-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .dashboard-section-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .quick-links-grid,
    .dashboard-stat-grid,
    .small-stat-row,
    .admin-stats-grid {
        grid-template-columns: 1fr;
    }
}
CSS;

require_once __DIR__ . '/include/header.php';
require_once __DIR__ . '/include/menu.php';
?>

<main class="app-main">
    <div class="container">

        <?php if ($isAdmin): ?>

            <section class="hero-banner">
                <h1 class="page-title">Admin Dashboard</h1>
                <p class="page-subtitle text-white">
                    General statistics only. The admin can monitor the system without opening, building, previewing, or exporting exams.
                </p>

                <div class="note-box" style="background: rgba(255,255,255,0.14); border-color: rgba(255,255,255,0.18); color:#fff;">
                    Admin access is limited to statistics and account management.
                </div>
            </section>

            <div class="admin-stats-grid">
                <div class="admin-stat-card">
                    <div class="admin-stat-icon">👥</div>
                    <div class="admin-stat-label">Total Accounts</div>
                    <div class="admin-stat-number"><?= (int)($userStats['total_users'] ?? 0) ?></div>
                    <div class="admin-stat-note">All registered users</div>
                </div>

                <div class="admin-stat-card">
                    <div class="admin-stat-icon">🛡️</div>
                    <div class="admin-stat-label">Admin Accounts</div>
                    <div class="admin-stat-number"><?= (int)($userStats['admin_count'] ?? 0) ?></div>
                    <div class="admin-stat-note">System administrators</div>
                </div>

                <div class="admin-stat-card">
                    <div class="admin-stat-icon">👨‍🏫</div>
                    <div class="admin-stat-label">Teacher Accounts</div>
                    <div class="admin-stat-number"><?= (int)($userStats['teacher_count'] ?? 0) ?></div>
                    <div class="admin-stat-note">Teachers using the exam system</div>
                </div>

                <div class="admin-stat-card">
                    <div class="admin-stat-icon">📘</div>
                    <div class="admin-stat-label">Total Exams</div>
                    <div class="admin-stat-number"><?= (int)($examStats['total_exams'] ?? 0) ?></div>
                    <div class="admin-stat-note">All created exams</div>
                </div>

                <div class="admin-stat-card">
                    <div class="admin-stat-icon">📝</div>
                    <div class="admin-stat-label">Draft Exams</div>
                    <div class="admin-stat-number"><?= (int)($examStats['draft_count'] ?? 0) ?></div>
                    <div class="admin-stat-note">Exams still in draft mode</div>
                </div>

                <div class="admin-stat-card">
                    <div class="admin-stat-icon">⏳</div>
                    <div class="admin-stat-label">Ready Exams</div>
                    <div class="admin-stat-number"><?= (int)($examStats['ready_count'] ?? 0) ?></div>
                    <div class="admin-stat-note">Partially built exams</div>
                </div>

                <div class="admin-stat-card">
                    <div class="admin-stat-icon">✅</div>
                    <div class="admin-stat-label">Generated Exams</div>
                    <div class="admin-stat-number"><?= (int)($examStats['generated_count'] ?? 0) ?></div>
                    <div class="admin-stat-note">Exams that reached planned count</div>
                </div>

                <div class="admin-stat-card">
                    <div class="admin-stat-icon">📢</div>
                    <div class="admin-stat-label">Published Exams</div>
                    <div class="admin-stat-number"><?= (int)($examStats['published_count'] ?? 0) ?></div>
                    <div class="admin-stat-note">Published exams</div>
                </div>

                <div class="admin-stat-card">
                    <div class="admin-stat-icon">🗂️</div>
                    <div class="admin-stat-label">Total Questions</div>
                    <div class="admin-stat-number"><?= (int)($questionStats['total_questions'] ?? 0) ?></div>
                    <div class="admin-stat-note">All question bank items</div>
                </div>

                <div class="admin-stat-card">
                    <div class="admin-stat-icon">👍</div>
                    <div class="admin-stat-label">Approved Questions</div>
                    <div class="admin-stat-number"><?= (int)($questionStats['approved_questions'] ?? 0) ?></div>
                    <div class="admin-stat-note">Ready for exam building</div>
                </div>

                <div class="admin-stat-card">
                    <div class="admin-stat-icon">🤖</div>
                    <div class="admin-stat-label">AI Questions</div>
                    <div class="admin-stat-number"><?= (int)($questionStats['ai_questions'] ?? 0) ?></div>
                    <div class="admin-stat-note">Questions generated using LLM</div>
                </div>

                <div class="admin-stat-card">
                    <div class="admin-stat-icon">✍️</div>
                    <div class="admin-stat-label">Manual Questions</div>
                    <div class="admin-stat-number"><?= (int)($questionStats['manual_questions'] ?? 0) ?></div>
                    <div class="admin-stat-note">Questions created manually</div>
                </div>

                <div class="admin-stat-card">
                    <div class="admin-stat-icon">🔗</div>
                    <div class="admin-stat-label">Linked Questions</div>
                    <div class="admin-stat-number"><?= $linkedQuestionsCount ?></div>
                    <div class="admin-stat-note">Question links inside built exams</div>
                </div>

                <div class="admin-stat-card">
                    <div class="admin-stat-icon">🕓</div>
                    <div class="admin-stat-label">Pending Questions</div>
                    <div class="admin-stat-number"><?= (int)($questionStats['pending_questions'] ?? 0) ?></div>
                    <div class="admin-stat-note">Questions waiting for approval</div>
                </div>

                <div class="admin-stat-card">
                    <div class="admin-stat-icon">📦</div>
                    <div class="admin-stat-label">Archived Questions</div>
                    <div class="admin-stat-number"><?= (int)($questionStats['archived_questions'] ?? 0) ?></div>
                    <div class="admin-stat-note">Archived bank items</div>
                </div>
            </div>

            <div class="admin-only-note">
                This dashboard intentionally hides exam actions and detailed exam lists for admin users.
            </div>

        <?php else: ?>

            <section class="hero-banner">
                <h1 class="page-title">Dashboard</h1>
                <p class="page-subtitle text-white">
                    Welcome to the programming exam generator. Create exams, manage the question bank, generate AI questions, and build final exams from approved items.
                </p>

                <div class="note-box" style="background: rgba(255,255,255,0.14); border-color: rgba(255,255,255,0.18); color:#fff;">
                    Recommended workflow: Create Exam → AI Generate or Manual Questions → Review Question Bank → Build Exam → Preview / Print.
                </div>
            </section>

            <div class="quick-links-grid">
                <div class="quick-link-card">
                    <div class="quick-link-icon">📝</div>
                    <h3>Create Exam</h3>
                    <p>Create a new exam and add manual questions directly from the same page.</p>
                    <a href="generate_exam.php" class="btn btn-primary btn-sm">Open</a>
                </div>

                <div class="quick-link-card">
                    <div class="quick-link-icon">🤖</div>
                    <h3>AI Generate</h3>
                    <p>Generate reusable questions using AI, then review and save only approved rows.</p>
                    <a href="ai_generate_questions.php" class="btn btn-primary btn-sm">Open</a>
                </div>

                <div class="quick-link-card">
                    <div class="quick-link-icon">🗂️</div>
                    <h3>Question Bank</h3>
                    <p>Browse, filter, edit, and manage all stored questions in one place.</p>
                    <a href="question_bank.php" class="btn btn-primary btn-sm">Open</a>
                </div>

                <div class="quick-link-card">
                    <div class="quick-link-icon">📚</div>
                    <h3>View Exams</h3>
                    <p>Review created exams, open details, build them from the bank, and preview them.</p>
                    <a href="view_exams.php" class="btn btn-primary btn-sm">Open</a>
                </div>
            </div>

            <div class="dashboard-stat-grid">
                <div class="stat-card">
                    <div class="stat-label">Total Exams</div>
                    <div class="stat-number"><?= (int)($examStats['total_exams'] ?? 0) ?></div>
                    <div class="stat-meta">All created exams in the system</div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Generated Exams</div>
                    <div class="stat-number"><?= (int)($examStats['generated_count'] ?? 0) ?></div>
                    <div class="stat-meta">Exams that reached the full planned count</div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Approved Questions</div>
                    <div class="stat-number"><?= (int)($questionStats['approved_questions'] ?? 0) ?></div>
                    <div class="stat-meta">Reusable approved questions in the bank</div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Linked Questions</div>
                    <div class="stat-number"><?= $linkedQuestionsCount ?></div>
                    <div class="stat-meta">Total question links inside built exams</div>
                </div>
            </div>

            <div class="dashboard-section-grid">
                <div class="form-card">
                    <div class="section-title">
                        <h2 class="mb-0">Exam Overview</h2>
                    </div>

                    <p class="section-subtitle">
                        Quick status summary for the current exams.
                    </p>

                    <div class="small-stat-row">
                        <div class="small-stat-box">
                            <h4>Draft</h4>
                            <div class="value"><?= (int)($examStats['draft_count'] ?? 0) ?></div>
                        </div>

                        <div class="small-stat-box">
                            <h4>Ready</h4>
                            <div class="value"><?= (int)($examStats['ready_count'] ?? 0) ?></div>
                        </div>

                        <div class="small-stat-box">
                            <h4>Published</h4>
                            <div class="value"><?= (int)($examStats['published_count'] ?? 0) ?></div>
                        </div>
                    </div>

                    <div class="table-card mt-4 table-compact">
                        <div class="table-header">
                            <h3 class="table-title">Recent Exams</h3>
                            <p class="table-subtitle">Latest created exams with quick actions.</p>
                        </div>

                        <div class="table-responsive">
                            <table class="custom-table table-striped">
                                <thead>
                                    <tr>
                                        <th>#ID</th>
                                        <th>Title</th>
                                        <th>Language</th>
                                        <th>Difficulty</th>
                                        <th>Status</th>
                                        <th>Progress</th>
                                        <th>Created</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($recentExams)): ?>
                                        <tr>
                                            <td colspan="8" class="table-empty">No exams found.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($recentExams as $exam): ?>
                                            <tr>
                                                <td><strong><?= (int)$exam['id'] ?></strong></td>
                                                <td><?= e(excerptText($exam['exam_title'], 50)) ?></td>
                                                <td><?= e($exam['programming_language']) ?></td>
                                                <td>
                                                    <span class="badge <?= difficultyBadgeClass((string)$exam['difficulty_level']) ?>">
                                                        <?= e($exam['difficulty_level']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge <?= statusBadgeClass((string)$exam['status']) ?>">
                                                        <?= e(ucfirst((string)$exam['status'])) ?>
                                                    </span>
                                                </td>
                                                <td><?= (int)$exam['linked_questions'] ?> / <?= (int)$exam['question_count'] ?></td>
                                                <td><?= e(formatDateTime($exam['created_at'])) ?></td>
                                                <td>
                                                    <div class="exam-actions">
                                                        <a href="view_exams.php?view=<?= (int)$exam['id'] ?>" class="btn btn-light btn-sm">Open</a>
                                                        <a href="build_exam.php?exam_id=<?= (int)$exam['id'] ?>" class="btn btn-outline-primary btn-sm">Build</a>
                                                        <a href="exam_preview.php?exam_id=<?= (int)$exam['id'] ?>" class="btn btn-info btn-sm">Preview</a>
                                                        <a href="export_exam_excel.php?exam_id=<?= (int)$exam['id'] ?>" class="btn btn-success btn-sm">Export</a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="form-card">
                    <div class="section-title">
                        <h2 class="mb-0">Question Bank Overview</h2>
                    </div>

                    <p class="section-subtitle">
                        Current health of the reusable question bank.
                    </p>

                    <div class="small-stat-row" style="grid-template-columns: 1fr;">
                        <div class="small-stat-box">
                            <h4>Total Questions</h4>
                            <div class="value"><?= (int)($questionStats['total_questions'] ?? 0) ?></div>
                        </div>

                        <div class="small-stat-box">
                            <h4>AI Questions</h4>
                            <div class="value"><?= (int)($questionStats['ai_questions'] ?? 0) ?></div>
                        </div>

                        <div class="small-stat-box">
                            <h4>Manual Questions</h4>
                            <div class="value"><?= (int)($questionStats['manual_questions'] ?? 0) ?></div>
                        </div>

                        <div class="small-stat-box">
                            <h4>Pending</h4>
                            <div class="value"><?= (int)($questionStats['pending_questions'] ?? 0) ?></div>
                        </div>

                        <div class="small-stat-box">
                            <h4>Archived</h4>
                            <div class="value"><?= (int)($questionStats['archived_questions'] ?? 0) ?></div>
                        </div>
                    </div>

                    <div class="recent-list mt-4">
                        <?php if (empty($recentQuestions)): ?>
                            <div class="recent-item">
                                <p>No questions found in the bank yet.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($recentQuestions as $question): ?>
                                <div class="recent-item">
                                    <div class="recent-item-top">
                                        <div>
                                            <h4>#<?= (int)$question['id'] ?> - <?= e(questionTypeLabel((string)$question['question_type'])) ?></h4>
                                            <p><?= e(excerptText($question['question_text'], 90)) ?></p>
                                        </div>

                                        <div class="meta-inline">
                                            <span class="badge <?= difficultyBadgeClass((string)$question['difficulty_level']) ?>">
                                                <?= e($question['difficulty_level']) ?>
                                            </span>

                                            <span class="badge <?= ((string)$question['source_type'] === 'ai') ? 'badge-primary' : 'badge-dark' ?>">
                                                <?= e($question['source_type']) ?>
                                            </span>
                                        </div>
                                    </div>

                                    <div class="meta-inline">
                                        <span class="badge badge-light"><?= e($question['programming_language']) ?></span>
                                        <span class="badge badge-light"><?= e(excerptText($question['topic'], 28)) ?></span>
                                        <span class="badge badge-light"><?= (int)$question['marks'] ?> marks</span>
                                    </div>

                                    <div style="margin-top:12px;">
                                        <a href="question_bank.php?edit=<?= (int)$question['id'] ?>" class="btn btn-light btn-sm">Edit Question</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="form-card mt-4">
                <div class="section-title">
                    <h2 class="mb-0">Workflow Reminder</h2>
                </div>

                <p class="section-subtitle">
                    Keep the project simple and consistent.
                </p>

                <div class="grid grid-4">
                    <div class="card">
                        <div class="card-body">
                            <h4>1. Create Exam</h4>
                            <p class="mb-0">Create exam info and optionally add manual questions.</p>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-body">
                            <h4>2. Generate / Review</h4>
                            <p class="mb-0">Use AI Generate and save only approved questions to the bank.</p>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-body">
                            <h4>3. Build Exam</h4>
                            <p class="mb-0">Attach approved bank questions to the selected exam.</p>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-body">
                            <h4>4. Preview / Export</h4>
                            <p class="mb-0">Preview the final exam or export it directly to Excel.</p>
                        </div>
                    </div>
                </div>
            </div>

        <?php endif; ?>

    </div>
</main>

<?php require_once __DIR__ . '/include/footer.php'; ?>
