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
    } catch (Exception $e) {
        return $dateTime;
    }
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
        'short_answer'      => 'Short Answer',
        'code_writing'      => 'Code Writing',
        'debugging'         => 'Debugging',
        'output_prediction' => 'Output Prediction',
        default             => ucfirst(str_replace('_', ' ', $type)),
    };
}

$examId = (int)($_GET['exam_id'] ?? $_GET['id'] ?? 0);

if ($examId <= 0) {
    die('Invalid exam ID.');
}

try {
    $examStmt = $pdoConnection->prepare("
        SELECT
            e.*,
            u.full_name AS creator_name,
            u.email AS creator_email,
            COALESCE(stats.linked_questions, 0) AS linked_questions,
            COALESCE(stats.total_marks, 0) AS total_marks
        FROM exams e
        LEFT JOIN users u
            ON u.id = e.user_id
        LEFT JOIN (
            SELECT
                eqb.exam_id,
                COUNT(*) AS linked_questions,
                SUM(COALESCE(eqb.marks_override, qb.marks)) AS total_marks
            FROM exam_question_bank eqb
            INNER JOIN question_bank qb
                ON qb.id = eqb.question_bank_id
            GROUP BY eqb.exam_id
        ) stats
            ON stats.exam_id = e.id
        WHERE e.id = :exam_id
        LIMIT 1
    ");
    $examStmt->execute([':exam_id' => $examId]);
    $exam = $examStmt->fetch(PDO::FETCH_ASSOC);

    if (!$exam) {
        die('Exam not found.');
    }

    $questionsStmt = $pdoConnection->prepare("
        SELECT
            eqb.id AS relation_id,
            eqb.question_order,
            eqb.marks_override,
            qb.id AS question_bank_id,
            qb.programming_language,
            qb.topic,
            qb.difficulty_level,
            qb.question_type,
            qb.question_text,
            qb.model_answer,
            qb.source_type,
            qb.status,
            qb.marks,
            COALESCE(eqb.marks_override, qb.marks) AS final_marks
        FROM exam_question_bank eqb
        INNER JOIN question_bank qb
            ON qb.id = eqb.question_bank_id
        WHERE eqb.exam_id = :exam_id
        ORDER BY eqb.question_order ASC, eqb.id ASC
    ");
    $questionsStmt->execute([':exam_id' => $examId]);
    $questions = $questionsStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die('Database error: ' . e($e->getMessage()));
}

$linkedCount = (int)($exam['linked_questions'] ?? 0);
$plannedCount = (int)($exam['question_count'] ?? 0);
$totalMarks = (int)($exam['total_marks'] ?? 0);
$completionPercent = ($plannedCount > 0) ? min(100, (int)round(($linkedCount / $plannedCount) * 100)) : 0;

$pageTitle   = 'Exam Preview';
$currentPage = 'view_exams';
$basePath    = '';

$pageStyles = <<<CSS
.preview-actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}

.preview-summary-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 16px;
    margin-top: 24px;
}

.preview-stat-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 18px;
    padding: 18px;
    box-shadow: var(--shadow-sm);
}

.preview-stat-card h4 {
    margin-bottom: 8px;
    font-size: 13px;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.4px;
}

.preview-stat-card .value {
    font-size: 30px;
    font-weight: 800;
    color: var(--text-main);
    line-height: 1.2;
}

.preview-detail-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px;
}

.preview-detail-box {
    background: var(--bg-card-2);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 16px 18px;
}

.preview-detail-box h4 {
    margin-bottom: 6px;
    font-size: 13px;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.4px;
}

.preview-detail-box p {
    margin: 0;
    color: var(--text-main);
    font-weight: 600;
}

.preview-detail-wide {
    grid-column: 1 / -1;
}

.exam-paper {
    margin-top: 24px;
}

.exam-paper-header {
    text-align: center;
    padding-bottom: 20px;
    margin-bottom: 20px;
    border-bottom: 2px dashed var(--border);
}

.exam-paper-title {
    margin-bottom: 8px;
    font-size: clamp(26px, 3vw, 36px);
    font-weight: 800;
    color: var(--text-main);
}

.exam-paper-subtitle {
    color: var(--text-muted);
    margin: 0;
}

.exam-paper-meta {
    display: flex;
    justify-content: center;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 14px;
}

.question-preview-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 20px;
    box-shadow: var(--shadow-sm);
    padding: 22px;
    margin-bottom: 18px;
}

.question-preview-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
    flex-wrap: wrap;
    margin-bottom: 16px;
}

.question-number-box {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 52px;
    height: 52px;
    border-radius: 16px;
    background: linear-gradient(135deg, var(--primary), var(--accent));
    color: #fff;
    font-weight: 800;
    font-size: 18px;
    box-shadow: var(--shadow-sm);
}

.question-meta-wrap {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.question-text-preview {
    color: var(--text-main);
    line-height: 1.9;
    white-space: pre-wrap;
    font-size: 15px;
    margin-bottom: 16px;
}

.answer-box {
    background: var(--bg-card-2);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 16px;
    margin-top: 14px;
}

.answer-box h5 {
    margin-bottom: 10px;
    color: var(--text-main);
}

.answer-box .answer-content {
    color: var(--text-soft);
    white-space: pre-wrap;
    line-height: 1.8;
}

.progress-wrap {
    margin-top: 16px;
}

.no-questions-box {
    padding: 28px;
    text-align: center;
    border: 1px dashed var(--border-strong);
    border-radius: 18px;
    background: var(--bg-card-2);
    color: var(--text-muted);
    font-weight: 700;
}

.print-only {
    display: none;
}

@media (max-width: 1100px) {
    .preview-summary-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .preview-detail-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 700px) {
    .preview-summary-grid {
        grid-template-columns: 1fr;
    }
}

@media print {
    body {
        background: #ffffff !important;
        color: #000 !important;
    }

    .app-topbar,
    .app-sidebar,
    .app-footer,
    .no-print {
        display: none !important;
    }

    .app-main {
        padding: 0 !important;
    }

    .container {
        width: 100% !important;
        max-width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
    }

    .hero-banner,
    .form-card,
    .question-preview-card,
    .preview-stat-card {
        background: #ffffff !important;
        color: #000 !important;
        box-shadow: none !important;
        border: 1px solid #d1d5db !important;
    }

    .question-number-box {
        background: #f3f4f6 !important;
        color: #000 !important;
        box-shadow: none !important;
        border: 1px solid #d1d5db !important;
    }

    .badge {
        border: 1px solid #d1d5db !important;
    }

    .print-only {
        display: block !important;
    }

    .answer-box {
        page-break-inside: avoid;
    }

    .question-preview-card {
        page-break-inside: avoid;
    }
}
CSS;

require_once __DIR__ . '/include/header.php';
require_once __DIR__ . '/include/menu.php';
?>

<main class="app-main">
    <div class="container">
        <div class="preview-summary-grid">
            <div class="preview-stat-card">
                <h4>Exam ID</h4>
                <div class="value">#<?= (int)$exam['id'] ?></div>
            </div>

            <div class="preview-stat-card">
                <h4>Linked Questions</h4>
                <div class="value"><?= $linkedCount ?> / <?= $plannedCount ?></div>
            </div>

            <div class="preview-stat-card">
                <h4>Total Marks</h4>
                <div class="value"><?= $totalMarks ?></div>
            </div>

            <div class="preview-stat-card">
                <h4>Status</h4>
                <div class="value" style="font-size: 20px;">
                    <span class="badge <?= statusBadgeClass((string)$exam['status']) ?>">
                        <?= e(ucfirst((string)$exam['status'])) ?>
                    </span>
                </div>
            </div>
        </div>

        <div class="form-card mt-4">
            <div class="section-title">
                <h2 class="mb-0">Exam Information</h2>
            </div>

            <div class="preview-detail-grid">
                <div class="preview-detail-box">
                    <h4>Exam Title</h4>
                    <p><?= e($exam['exam_title']) ?></p>
                </div>

                <div class="preview-detail-box">
                    <h4>Topic</h4>
                    <p><?= e($exam['topic']) ?></p>
                </div>

                <div class="preview-detail-box">
                    <h4>Programming Language</h4>
                    <p><?= e($exam['programming_language']) ?></p>
                </div>

                <div class="preview-detail-box">
                    <h4>Difficulty</h4>
                    <p>
                        <span class="badge <?= difficultyBadgeClass((string)$exam['difficulty_level']) ?>">
                            <?= e($exam['difficulty_level']) ?>
                        </span>
                    </p>
                </div>

                <div class="preview-detail-box">
                    <h4>Duration</h4>
                    <p><?= (int)$exam['duration_minutes'] ?> minutes</p>
                </div>

                <div class="preview-detail-box">
                    <h4>Created By</h4>
                    <p><?= e($exam['creator_name'] ?: 'Unknown User') ?></p>
                </div>

                <div class="preview-detail-box">
                    <h4>Created At</h4>
                    <p><?= e(formatDateTime($exam['created_at'])) ?></p>
                </div>

                <div class="preview-detail-box">
                    <h4>Completion</h4>
                    <p><?= $completionPercent ?>%</p>
                </div>

                <div class="preview-detail-box preview-detail-wide">
                    <h4>Notes</h4>
                    <p><?= e($exam['notes'] ?: 'No notes added.') ?></p>
                </div>
            </div>

            <div class="progress-wrap">
                <div class="progress">
                    <div class="progress-bar" style="width: <?= $completionPercent ?>%;"></div>
                </div>
            </div>
        </div>

        <div class="form-card exam-paper">
            <div class="exam-paper-header">
                <div class="print-only" style="margin-bottom:10px;font-weight:700;">
                    Programming Exam Paper
                </div>

                <h2 class="exam-paper-title"><?= e($exam['exam_title']) ?></h2>
                <p class="exam-paper-subtitle"><?= e($exam['topic']) ?></p>

                <div class="exam-paper-meta">
                    <span class="badge badge-primary"><?= e($exam['programming_language']) ?></span>
                    <span class="badge <?= difficultyBadgeClass((string)$exam['difficulty_level']) ?>">
                        <?= e($exam['difficulty_level']) ?>
                    </span>
                    <span class="badge badge-info"><?= (int)$exam['duration_minutes'] ?> Minutes</span>
                    <span class="badge badge-dark">Total Marks: <?= $totalMarks ?></span>
                </div>
            </div>

            <?php if (empty($questions)): ?>
                <div class="no-questions-box">
                    No questions are linked to this exam yet. Please go back to Build Exam first.
                </div>
            <?php else: ?>
                <?php foreach ($questions as $index => $question): ?>
                    <div class="question-preview-card">
                        <div class="question-preview-header">
                            <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
                                <div class="question-number-box">
                                    <?= (int)$question['question_order'] ?>
                                </div>

                                <div>
                                    <h3 class="mb-1">Question <?= (int)$question['question_order'] ?></h3>
                                    <div class="question-meta-wrap">
                                        <span class="badge badge-primary">
                                            <?= e(questionTypeLabel((string)$question['question_type'])) ?>
                                        </span>
                                        <span class="badge <?= difficultyBadgeClass((string)$question['difficulty_level']) ?>">
                                            <?= e($question['difficulty_level']) ?>
                                        </span>
                                        <span class="badge badge-info">
                                            <?= (int)$question['final_marks'] ?> Mark(s)
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="question-text-preview"><?= e($question['question_text']) ?></div>

                        <div class="answer-box question-answer">
                            <h5>Model Answer</h5>
                            <div class="answer-content"><?= e($question['model_answer'] ?: 'No model answer available.') ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const toggleBtn = document.getElementById('toggleAnswersBtn');
    const answerBoxes = document.querySelectorAll('.question-answer');
    let visible = true;

    if (toggleBtn) {
        toggleBtn.addEventListener('click', function () {
            visible = !visible;

            answerBoxes.forEach(function (box) {
                box.style.display = visible ? 'block' : 'none';
            });

            toggleBtn.textContent = visible ? 'Hide Model Answers' : 'Show Model Answers';
        });
    }
});
</script>

<?php require_once __DIR__ . '/include/footer.php'; ?>