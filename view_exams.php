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

function selectedValue(string $current, string $expected): string
{
    return $current === $expected ? 'selected' : '';
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

function excerptText(?string $text, int $length = 100): string
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

function buildQuestionModelAnswer(string $type, array $data): string
{
    $manualAnswer = trim((string)($data['model_answer'] ?? ''));
    if ($manualAnswer !== '') {
        return $manualAnswer;
    }

    if ($type === 'true_false') {
        $correct = trim((string)($data['tf_correct_answer'] ?? ''));
        return $correct !== '' ? 'Correct answer: ' . $correct : '';
    }

    if ($type === 'mcq') {
        $correct = trim((string)($data['mcq_correct_answer'] ?? ''));
        $map = [
            'A' => trim((string)($data['mcq_option_a'] ?? '')),
            'B' => trim((string)($data['mcq_option_b'] ?? '')),
            'C' => trim((string)($data['mcq_option_c'] ?? '')),
            'D' => trim((string)($data['mcq_option_d'] ?? '')),
        ];

        if ($correct !== '' && isset($map[$correct])) {
            return 'Correct answer: ' . $correct . ') ' . $map[$correct];
        }
    }

    return '';
}

function fetchExamById(PDO $pdo, int $examId): ?array
{
    $stmt = $pdo->prepare("
        SELECT
            e.*,
            u.full_name AS creator_name,
            COALESCE(eq.linked_questions, 0) AS linked_questions
        FROM exams e
        LEFT JOIN users u
            ON u.id = e.user_id
        LEFT JOIN (
            SELECT exam_id, COUNT(*) AS linked_questions
            FROM exam_question_bank
            GROUP BY exam_id
        ) eq
            ON eq.exam_id = e.id
        WHERE e.id = :exam_id
        LIMIT 1
    ");
    $stmt->execute([':exam_id' => $examId]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function fetchLinkedQuestions(PDO $pdo, int $examId): array
{
    $stmt = $pdo->prepare("
        SELECT
            eqb.id AS relation_id,
            eqb.question_order,
            eqb.marks_override,
            qb.*
        FROM exam_question_bank eqb
        INNER JOIN question_bank qb
            ON qb.id = eqb.question_bank_id
        WHERE eqb.exam_id = :exam_id
        ORDER BY eqb.question_order ASC, eqb.id ASC
    ");
    $stmt->execute([':exam_id' => $examId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function recalculateExamStatus(PDO $pdo, int $examId): void
{
    $stmt = $pdo->prepare("
        SELECT
            e.question_count,
            COALESCE(eq.linked_questions, 0) AS linked_questions
        FROM exams e
        LEFT JOIN (
            SELECT exam_id, COUNT(*) AS linked_questions
            FROM exam_question_bank
            GROUP BY exam_id
        ) eq
            ON eq.exam_id = e.id
        WHERE e.id = :exam_id
        LIMIT 1
    ");
    $stmt->execute([':exam_id' => $examId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return;
    }

    $planned = (int)$row['question_count'];
    $linked  = (int)$row['linked_questions'];

    $newStatus = 'draft';
    if ($linked > 0 && $linked < $planned) {
        $newStatus = 'ready';
    } elseif ($planned > 0 && $linked >= $planned) {
        $newStatus = 'generated';
    }

    $update = $pdo->prepare("
        UPDATE exams
        SET status = :status
        WHERE id = :exam_id
        LIMIT 1
    ");
    $update->execute([
        ':status'  => $newStatus,
        ':exam_id' => $examId,
    ]);
}

$errors = [];
$success = '';

$viewExamId = (int)($_GET['view'] ?? $_POST['view_exam_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'update_exam') {
        $examId = (int)($_POST['view_exam_id'] ?? 0);

        $examTitle           = trim((string)($_POST['exam_title'] ?? ''));
        $topic               = trim((string)($_POST['topic'] ?? ''));
        $programmingLanguage = trim((string)($_POST['programming_language'] ?? ''));
        $difficultyLevel     = trim((string)($_POST['difficulty_level'] ?? 'Medium'));
        $questionCount       = (int)($_POST['question_count'] ?? 0);
        $durationMinutes     = (int)($_POST['duration_minutes'] ?? 0);
        $notes               = trim((string)($_POST['notes'] ?? ''));

        if ($examId <= 0) {
            $errors[] = 'Invalid exam ID.';
        }

        if ($examTitle === '') {
            $errors[] = 'Exam title is required.';
        }

        if ($topic === '') {
            $errors[] = 'Topic is required.';
        }

        if ($programmingLanguage === '') {
            $errors[] = 'Programming language is required.';
        }

        if (!in_array($difficultyLevel, ['Easy', 'Medium', 'Hard', 'Mixed'], true)) {
            $errors[] = 'Invalid exam difficulty level.';
        }

        if ($questionCount < 1 || $questionCount > 20) {
            $errors[] = 'Question count must be between 1 and 20.';
        }

        if ($durationMinutes < 10 || $durationMinutes > 300) {
            $errors[] = 'Duration must be between 10 and 300 minutes.';
        }

        if (empty($errors)) {
            try {
                $stmt = $pdoConnection->prepare("
                    UPDATE exams
                    SET
                        exam_title = :exam_title,
                        topic = :topic,
                        programming_language = :programming_language,
                        difficulty_level = :difficulty_level,
                        question_count = :question_count,
                        duration_minutes = :duration_minutes,
                        notes = :notes
                    WHERE id = :exam_id
                    LIMIT 1
                ");

                $stmt->execute([
                    ':exam_title'           => $examTitle,
                    ':topic'                => $topic,
                    ':programming_language' => $programmingLanguage,
                    ':difficulty_level'     => $difficultyLevel,
                    ':question_count'       => $questionCount,
                    ':duration_minutes'     => $durationMinutes,
                    ':notes'                => $notes,
                    ':exam_id'              => $examId,
                ]);

                recalculateExamStatus($pdoConnection, $examId);
                $success = 'Exam information updated successfully.';
                $viewExamId = $examId;
            } catch (Throwable $e) {
                $errors[] = 'Exam update failed: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'update_linked_question') {
        $examId         = (int)($_POST['view_exam_id'] ?? 0);
        $questionBankId = (int)($_POST['question_bank_id'] ?? 0);

        $questionType      = trim((string)($_POST['question_type'] ?? 'short_answer'));
        $difficultyLevel   = trim((string)($_POST['difficulty_level'] ?? 'Medium'));
        $marks             = (int)($_POST['marks'] ?? 0);
        $questionText      = trim((string)($_POST['question_text'] ?? ''));
        $modelAnswer       = trim((string)($_POST['model_answer'] ?? ''));

        $mcqOptionA        = trim((string)($_POST['mcq_option_a'] ?? ''));
        $mcqOptionB        = trim((string)($_POST['mcq_option_b'] ?? ''));
        $mcqOptionC        = trim((string)($_POST['mcq_option_c'] ?? ''));
        $mcqOptionD        = trim((string)($_POST['mcq_option_d'] ?? ''));
        $mcqCorrectAnswer  = trim((string)($_POST['mcq_correct_answer'] ?? ''));

        $tfCorrectAnswer   = trim((string)($_POST['tf_correct_answer'] ?? ''));

        if ($examId <= 0 || $questionBankId <= 0) {
            $errors[] = 'Invalid exam or question ID.';
        }

        if (!in_array($questionType, ['mcq', 'true_false', 'short_answer', 'code_writing', 'debugging', 'output_prediction'], true)) {
            $errors[] = 'Invalid question type.';
        }

        if (!in_array($difficultyLevel, ['Easy', 'Medium', 'Hard'], true)) {
            $errors[] = 'Invalid question difficulty.';
        }

        if ($questionText === '') {
            $errors[] = 'Question text is required.';
        }

        if ($marks < 1 || $marks > 100) {
            $errors[] = 'Marks must be between 1 and 100.';
        }

        $verifyStmt = $pdoConnection->prepare("
            SELECT eqb.id
            FROM exam_question_bank eqb
            WHERE eqb.exam_id = :exam_id
              AND eqb.question_bank_id = :question_bank_id
            LIMIT 1
        ");
        $verifyStmt->execute([
            ':exam_id'          => $examId,
            ':question_bank_id' => $questionBankId,
        ]);

        if (!$verifyStmt->fetchColumn()) {
            $errors[] = 'This question is not linked to the selected exam.';
        }

        $optionA = null;
        $optionB = null;
        $optionC = null;
        $optionD = null;
        $correctAnswer = null;

        if ($questionType === 'mcq') {
            if ($mcqOptionA === '' || $mcqOptionB === '' || $mcqOptionC === '' || $mcqOptionD === '') {
                $errors[] = 'All MCQ options are required.';
            }

            if (!in_array($mcqCorrectAnswer, ['A', 'B', 'C', 'D'], true)) {
                $errors[] = 'MCQ correct answer must be A, B, C, or D.';
            }

            $optionA = $mcqOptionA;
            $optionB = $mcqOptionB;
            $optionC = $mcqOptionC;
            $optionD = $mcqOptionD;
            $correctAnswer = $mcqCorrectAnswer;
        }

        if ($questionType === 'true_false') {
            if (!in_array($tfCorrectAnswer, ['True', 'False'], true)) {
                $errors[] = 'True / False correct answer must be True or False.';
            }

            $optionA = 'True';
            $optionB = 'False';
            $optionC = null;
            $optionD = null;
            $correctAnswer = $tfCorrectAnswer;
        }

        if (in_array($questionType, ['short_answer', 'code_writing', 'debugging', 'output_prediction'], true)) {
            $optionA = null;
            $optionB = null;
            $optionC = null;
            $optionD = null;
            $correctAnswer = null;
        }

        if (empty($errors)) {
            try {
                $finalModelAnswer = buildQuestionModelAnswer($questionType, [
                    'model_answer'        => $modelAnswer,
                    'mcq_option_a'        => $mcqOptionA,
                    'mcq_option_b'        => $mcqOptionB,
                    'mcq_option_c'        => $mcqOptionC,
                    'mcq_option_d'        => $mcqOptionD,
                    'mcq_correct_answer'  => $mcqCorrectAnswer,
                    'tf_correct_answer'   => $tfCorrectAnswer,
                ]);

                $stmt = $pdoConnection->prepare("
                    UPDATE question_bank
                    SET
                        question_type = :question_type,
                        difficulty_level = :difficulty_level,
                        question_text = :question_text,
                        option_a = :option_a,
                        option_b = :option_b,
                        option_c = :option_c,
                        option_d = :option_d,
                        correct_answer = :correct_answer,
                        model_answer = :model_answer,
                        marks = :marks
                    WHERE id = :question_bank_id
                    LIMIT 1
                ");

                $stmt->execute([
                    ':question_type'   => $questionType,
                    ':difficulty_level'=> $difficultyLevel,
                    ':question_text'   => $questionText,
                    ':option_a'        => $optionA,
                    ':option_b'        => $optionB,
                    ':option_c'        => $optionC,
                    ':option_d'        => $optionD,
                    ':correct_answer'  => $correctAnswer,
                    ':model_answer'    => $finalModelAnswer,
                    ':marks'           => $marks,
                    ':question_bank_id'=> $questionBankId,
                ]);

                $success = 'Linked question updated successfully.';
                $viewExamId = $examId;
            } catch (Throwable $e) {
                $errors[] = 'Question update failed: ' . $e->getMessage();
            }
        }
    }
}

$search           = trim((string)($_GET['search'] ?? ''));
$statusFilter     = trim((string)($_GET['status'] ?? ''));
$difficultyFilter = trim((string)($_GET['difficulty'] ?? ''));
$languageFilter   = trim((string)($_GET['language'] ?? ''));

$whereParts = [];
$bindings   = [];

if ($search !== '') {
    $whereParts[] = "(
        e.exam_title LIKE :search
        OR e.topic LIKE :search
        OR e.programming_language LIKE :search
        OR e.notes LIKE :search
        OR CAST(e.id AS CHAR) LIKE :search
    )";
    $bindings[':search'] = '%' . $search . '%';
}

if ($statusFilter !== '') {
    $whereParts[] = "e.status = :status";
    $bindings[':status'] = $statusFilter;
}

if ($difficultyFilter !== '') {
    $whereParts[] = "e.difficulty_level = :difficulty";
    $bindings[':difficulty'] = $difficultyFilter;
}

if ($languageFilter !== '') {
    $whereParts[] = "e.programming_language = :language";
    $bindings[':language'] = $languageFilter;
}

$whereSql = '';
if (!empty($whereParts)) {
    $whereSql = 'WHERE ' . implode(' AND ', $whereParts);
}

$languagesStmt = $pdoConnection->query("
    SELECT DISTINCT programming_language
    FROM exams
    WHERE programming_language IS NOT NULL
      AND programming_language <> ''
    ORDER BY programming_language ASC
");
$languages = $languagesStmt->fetchAll(PDO::FETCH_COLUMN);

$examSql = "
    SELECT
        e.*,
        u.full_name AS creator_name,
        COALESCE(eq.linked_questions, 0) AS linked_questions
    FROM exams e
    LEFT JOIN users u
        ON u.id = e.user_id
    LEFT JOIN (
        SELECT exam_id, COUNT(*) AS linked_questions
        FROM exam_question_bank
        GROUP BY exam_id
    ) eq
        ON eq.exam_id = e.id
    $whereSql
    ORDER BY e.id DESC
";

$examStmt = $pdoConnection->prepare($examSql);
$examStmt->execute($bindings);
$exams = $examStmt->fetchAll(PDO::FETCH_ASSOC);

$selectedExam = null;
$selectedQuestions = [];
$examNotFound = false;

if ($viewExamId > 0) {
    $selectedExam = fetchExamById($pdoConnection, $viewExamId);

    if ($selectedExam) {
        $selectedQuestions = fetchLinkedQuestions($pdoConnection, $viewExamId);
    } else {
        $examNotFound = true;
    }
}

$pageTitle   = 'View Exam';
$currentPage = 'view_exams';
$basePath    = '';

$pageStyles = <<<CSS
.page-actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}

.filters-grid {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr 1fr;
    gap: 16px;
}

.exam-details-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px;
}

.detail-box {
    background: var(--bg-card-2);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 16px 18px;
}

.detail-box h4 {
    margin-bottom: 6px;
    font-size: 13px;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.4px;
}

.detail-box p {
    margin: 0;
    color: var(--text-main);
    font-weight: 600;
}

.detail-wide {
    grid-column: 1 / -1;
}

.question-editor-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 20px;
    padding: 20px;
    box-shadow: var(--shadow-sm);
    margin-bottom: 18px;
}

.question-editor-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 16px;
}

.question-editor-title {
    display: flex;
    align-items: center;
    gap: 12px;
}

.question-index-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 44px;
    height: 44px;
    border-radius: 14px;
    background: linear-gradient(135deg, var(--primary), var(--accent));
    color: #fff;
    font-weight: 800;
    box-shadow: var(--shadow-sm);
}

.choice-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
}

.type-section {
    margin-top: 12px;
    padding: 16px;
    border-radius: 16px;
    background: var(--bg-card-2);
    border: 1px dashed var(--border-strong);
}

.type-section-title {
    font-size: 13px;
    font-weight: 800;
    color: var(--text-muted);
    margin-bottom: 12px;
    text-transform: uppercase;
    letter-spacing: 0.4px;
}

.page-note {
    background: var(--warning-light);
    color: #92400e;
    border: 1px solid rgba(217, 119, 6, 0.15);
    padding: 14px 16px;
    border-radius: 14px;
    font-weight: 600;
}

.mini-meta {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

@media (max-width: 992px) {
    .filters-grid,
    .exam-details-grid,
    .choice-grid,
    .form-row {
        grid-template-columns: 1fr !important;
    }
}
CSS;

require_once __DIR__ . '/include/header.php';
require_once __DIR__ . '/include/menu.php';
?>

<main class="app-main">
    <div class="container">
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger mt-4">
                <strong>Please fix the following:</strong>
                <ul style="margin-top:10px; padding-left:18px; list-style:disc;">
                    <?php foreach ($errors as $error): ?>
                        <li><?= e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="alert alert-success mt-4"><?= e($success) ?></div>
        <?php endif; ?>

        <div class="form-card mt-4">
            <div class="section-title">
                <h2 class="mb-0">Exams List</h2>
            </div>

            <p class="section-subtitle">
                Search and open the exam you want to review or edit.
            </p>

            <form method="GET">
                <div class="filters-grid">
                    <div class="form-group mb-0">
                        <label class="form-label">Search</label>
                        <input
                            type="search"
                            name="search"
                            value="<?= e($search) ?>"
                            placeholder="Search by title, topic, language, notes, or exam ID..."
                        >
                    </div>

                    <div class="form-group mb-0">
                        <label class="form-label">Status</label>
                        <select name="status">
                            <option value="">All</option>
                            <option value="draft" <?= selectedValue($statusFilter, 'draft') ?>>Draft</option>
                            <option value="ready" <?= selectedValue($statusFilter, 'ready') ?>>Ready</option>
                            <option value="generated" <?= selectedValue($statusFilter, 'generated') ?>>Generated</option>
                            <option value="published" <?= selectedValue($statusFilter, 'published') ?>>Published</option>
                        </select>
                    </div>

                    <div class="form-group mb-0">
                        <label class="form-label">Difficulty</label>
                        <select name="difficulty">
                            <option value="">All</option>
                            <option value="Easy" <?= selectedValue($difficultyFilter, 'Easy') ?>>Easy</option>
                            <option value="Medium" <?= selectedValue($difficultyFilter, 'Medium') ?>>Medium</option>
                            <option value="Hard" <?= selectedValue($difficultyFilter, 'Hard') ?>>Hard</option>
                            <option value="Mixed" <?= selectedValue($difficultyFilter, 'Mixed') ?>>Mixed</option>
                        </select>
                    </div>

                    <div class="form-group mb-0">
                        <label class="form-label">Language</label>
                        <select name="language">
                            <option value="">All</option>
                            <?php foreach ($languages as $language): ?>
                                <option value="<?= e($language) ?>" <?= selectedValue($languageFilter, (string)$language) ?>>
                                    <?= e($language) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="toolbar mt-4">
                    <div class="toolbar-left">
                        <button type="submit" class="btn btn-primary">Apply Filters</button>
                        <a href="view_exams.php" class="btn btn-light">Reset</a>
                    </div>
                </div>
            </form>
        </div>

        <div class="table-card mt-4">
            <div class="table-header">
                <h3 class="table-title">Stored Exams</h3>
                <p class="table-subtitle"><?= count($exams) ?> exam(s) found.</p>
            </div>

            <div class="table-responsive">
                <table class="custom-table table-striped">
                    <thead>
                        <tr>
                            <th>#ID</th>
                            <th>Exam Title</th>
                            <th>Topic</th>
                            <th>Language</th>
                            <th>Difficulty</th>
                            <th>Status</th>
                            <th>Questions</th>
                            <th>Duration</th>
                            <th>Created At</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($exams)): ?>
                            <tr>
                                <td colspan="10" class="table-empty">No exams found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($exams as $exam): ?>
                                <tr>
                                    <td><strong><?= (int)$exam['id'] ?></strong></td>
                                    <td><?= e($exam['exam_title']) ?></td>
                                    <td><?= e(excerptText($exam['topic'], 70)) ?></td>
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
                                    <td><?= (int)$exam['duration_minutes'] ?> min</td>
                                    <td><?= e(formatDateTime($exam['created_at'])) ?></td>
                                    <td>
                                        <a href="view_exams.php?view=<?= (int)$exam['id'] ?>" class="btn btn-light btn-sm">
                                            Open
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($examNotFound): ?>
            <div class="alert alert-warning mt-4">The selected exam was not found.</div>
        <?php endif; ?>

        <?php if ($selectedExam): ?>
            <div class="form-card mt-4">
                <div class="toolbar">
                    <div class="toolbar-left">
                        <div>
                            <div class="section-title mb-0">
                                <h2 class="mb-0">Exam Details - #<?= (int)$selectedExam['id'] ?></h2>
                            </div>
                            <p class="section-subtitle" style="margin-top:8px;">
                                You can update the exam information directly from here.
                            </p>
                        </div>
                    </div>

                    <div class="toolbar-right">
                        <span class="badge <?= difficultyBadgeClass((string)$selectedExam['difficulty_level']) ?>">
                            <?= e($selectedExam['difficulty_level']) ?>
                        </span>
                        <span class="badge <?= statusBadgeClass((string)$selectedExam['status']) ?>">
                            <?= e(ucfirst((string)$selectedExam['status'])) ?>
                        </span>
                    </div>
                </div>

                <form method="POST">
                    <input type="hidden" name="action" value="update_exam">
                    <input type="hidden" name="view_exam_id" value="<?= (int)$selectedExam['id'] ?>">

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Exam Title</label>
                            <input type="text" name="exam_title" value="<?= e($selectedExam['exam_title']) ?>" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Topic</label>
                            <input type="text" name="topic" value="<?= e($selectedExam['topic']) ?>" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Programming Language</label>
                            <select name="programming_language" required>
                                <option value="C" <?= selectedValue((string)$selectedExam['programming_language'], 'C') ?>>C</option>
                                <option value="C++" <?= selectedValue((string)$selectedExam['programming_language'], 'C++') ?>>C++</option>
                                <option value="Java" <?= selectedValue((string)$selectedExam['programming_language'], 'Java') ?>>Java</option>
                                <option value="Python" <?= selectedValue((string)$selectedExam['programming_language'], 'Python') ?>>Python</option>
                                <option value="JavaScript" <?= selectedValue((string)$selectedExam['programming_language'], 'JavaScript') ?>>JavaScript</option>
                                <option value="PHP" <?= selectedValue((string)$selectedExam['programming_language'], 'PHP') ?>>PHP</option>
                                <option value="SQL" <?= selectedValue((string)$selectedExam['programming_language'], 'SQL') ?>>SQL</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Difficulty</label>
                            <select name="difficulty_level" required>
                                <option value="Easy" <?= selectedValue((string)$selectedExam['difficulty_level'], 'Easy') ?>>Easy</option>
                                <option value="Medium" <?= selectedValue((string)$selectedExam['difficulty_level'], 'Medium') ?>>Medium</option>
                                <option value="Hard" <?= selectedValue((string)$selectedExam['difficulty_level'], 'Hard') ?>>Hard</option>
                                <option value="Mixed" <?= selectedValue((string)$selectedExam['difficulty_level'], 'Mixed') ?>>Mixed</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Question Count</label>
                            <input type="number" min="1" max="20" name="question_count" value="<?= (int)$selectedExam['question_count'] ?>" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Duration (Minutes)</label>
                            <input type="number" min="10" max="300" name="duration_minutes" value="<?= (int)$selectedExam['duration_minutes'] ?>" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Notes</label>
                        <textarea name="notes"><?= e($selectedExam['notes'] ?? '') ?></textarea>
                    </div>

                    <div class="exam-details-grid mt-4">
                        <div class="detail-box">
                            <h4>Created By</h4>
                            <p><?= e($selectedExam['creator_name'] ?: 'Unknown') ?></p>
                        </div>

                        <div class="detail-box">
                            <h4>Created At</h4>
                            <p><?= e(formatDateTime($selectedExam['created_at'])) ?></p>
                        </div>

                        <div class="detail-box">
                            <h4>Linked Questions</h4>
                            <p><?= (int)$selectedExam['linked_questions'] ?> / <?= (int)$selectedExam['question_count'] ?></p>
                        </div>

                        <div class="detail-box">
                            <h4>Current Status</h4>
                            <p><?= e(ucfirst((string)$selectedExam['status'])) ?></p>
                        </div>
                    </div>

                    <div class="toolbar mt-4">
                        <div class="toolbar-left">
                            <button type="submit" class="btn btn-primary">Save Exam Changes</button>
                        </div>
                    </div>
                </form>
            </div>

            <div class="form-card mt-4">
                <div class="section-title">
                    <h2 class="mb-0">Linked Questions</h2>
                </div>

                <p class="section-subtitle">
                    Edit any linked question directly. Since the exam is linked to <strong>question_bank</strong>, editing here will also update that question in the bank.
                </p>

                <div class="page-note mb-4">
                    Important: if the same question is reused in another exam, editing it here will affect that reused version too.
                </div>

                <?php if (empty($selectedQuestions)): ?>
                    <div class="table-empty">No questions linked to this exam yet.</div>
                <?php else: ?>
                    <?php foreach ($selectedQuestions as $index => $question): ?>
                        <form method="POST" class="question-editor-card question-editor-item">
                            <input type="hidden" name="action" value="update_linked_question">
                            <input type="hidden" name="view_exam_id" value="<?= (int)$selectedExam['id'] ?>">
                            <input type="hidden" name="question_bank_id" value="<?= (int)$question['id'] ?>">

                            <div class="question-editor-header">
                                <div class="question-editor-title">
                                    <span class="question-index-badge"><?= (int)$question['question_order'] ?></span>
                                    <div>
                                        <h3 class="mb-0">Question #<?= (int)$question['question_order'] ?></h3>
                                        <div class="mini-meta mt-2">
                                            <span class="badge badge-primary">Bank ID #<?= (int)$question['id'] ?></span>
                                            <span class="badge badge-info"><?= e(questionTypeLabel((string)$question['question_type'])) ?></span>
                                            <span class="badge <?= difficultyBadgeClass((string)$question['difficulty_level']) ?>">
                                                <?= e($question['difficulty_level']) ?>
                                            </span>
                                            <span class="badge badge-dark"><?= e($question['source_type']) ?></span>
                                        </div>
                                    </div>
                                </div>

                                <button type="submit" class="btn btn-success btn-sm">Save Question</button>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Question Type</label>
                                    <select name="question_type" class="linked-question-type-selector">
                                        <option value="mcq" <?= selectedValue((string)$question['question_type'], 'mcq') ?>>MCQ</option>
                                        <option value="true_false" <?= selectedValue((string)$question['question_type'], 'true_false') ?>>True / False</option>
                                        <option value="short_answer" <?= selectedValue((string)$question['question_type'], 'short_answer') ?>>Short Answer</option>
                                        <option value="code_writing" <?= selectedValue((string)$question['question_type'], 'code_writing') ?>>Code Writing</option>
                                        <option value="debugging" <?= selectedValue((string)$question['question_type'], 'debugging') ?>>Debugging</option>
                                        <option value="output_prediction" <?= selectedValue((string)$question['question_type'], 'output_prediction') ?>>Output Prediction</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Difficulty</label>
                                    <select name="difficulty_level">
                                        <option value="Easy" <?= selectedValue((string)$question['difficulty_level'], 'Easy') ?>>Easy</option>
                                        <option value="Medium" <?= selectedValue((string)$question['difficulty_level'], 'Medium') ?>>Medium</option>
                                        <option value="Hard" <?= selectedValue((string)$question['difficulty_level'], 'Hard') ?>>Hard</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Marks</label>
                                    <input type="number" min="1" max="100" name="marks" value="<?= (int)$question['marks'] ?>">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Question Text</label>
                                <textarea name="question_text" required><?= e($question['question_text']) ?></textarea>
                            </div>

                            <div class="type-section linked-mcq-fields" style="<?= (string)$question['question_type'] === 'mcq' ? '' : 'display:none;' ?>">
                                <div class="type-section-title">MCQ Options</div>

                                <div class="choice-grid">
                                    <div class="form-group">
                                        <label class="form-label">Option A</label>
                                        <input type="text" name="mcq_option_a" value="<?= e($question['option_a']) ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Option B</label>
                                        <input type="text" name="mcq_option_b" value="<?= e($question['option_b']) ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Option C</label>
                                        <input type="text" name="mcq_option_c" value="<?= e($question['option_c']) ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Option D</label>
                                        <input type="text" name="mcq_option_d" value="<?= e($question['option_d']) ?>">
                                    </div>
                                </div>

                                <div class="form-group mb-0">
                                    <label class="form-label">Correct Answer</label>
                                    <select name="mcq_correct_answer">
                                        <option value="">-- Select Correct Answer --</option>
                                        <option value="A" <?= selectedValue((string)$question['correct_answer'], 'A') ?>>A</option>
                                        <option value="B" <?= selectedValue((string)$question['correct_answer'], 'B') ?>>B</option>
                                        <option value="C" <?= selectedValue((string)$question['correct_answer'], 'C') ?>>C</option>
                                        <option value="D" <?= selectedValue((string)$question['correct_answer'], 'D') ?>>D</option>
                                    </select>
                                </div>
                            </div>

                            <div class="type-section linked-tf-fields" style="<?= (string)$question['question_type'] === 'true_false' ? '' : 'display:none;' ?>">
                                <div class="type-section-title">True / False Answer</div>

                                <div class="form-group mb-0">
                                    <label class="form-label">Correct Answer</label>
                                    <select name="tf_correct_answer">
                                        <option value="">-- Select Correct Answer --</option>
                                        <option value="True" <?= selectedValue((string)$question['correct_answer'], 'True') ?>>True</option>
                                        <option value="False" <?= selectedValue((string)$question['correct_answer'], 'False') ?>>False</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group mt-3 mb-0">
                                <label class="form-label">Model Answer</label>
                                <textarea name="model_answer"><?= e($question['model_answer']) ?></textarea>
                            </div>
                        </form>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    </div>
</main>

<script>
(function () {
    function updateLinkedQuestionTypeSections(scope) {
        const root = scope || document;

        root.querySelectorAll('.question-editor-item').forEach(function (item) {
            const typeSelect = item.querySelector('.linked-question-type-selector');
            const mcqFields  = item.querySelector('.linked-mcq-fields');
            const tfFields   = item.querySelector('.linked-tf-fields');

            if (!typeSelect) return;

            const type = typeSelect.value;

            if (mcqFields) {
                mcqFields.style.display = (type === 'mcq') ? 'block' : 'none';
            }

            if (tfFields) {
                tfFields.style.display = (type === 'true_false') ? 'block' : 'none';
            }
        });
    }

    document.addEventListener('change', function (e) {
        if (e.target.classList.contains('linked-question-type-selector')) {
            updateLinkedQuestionTypeSections(document);
        }
    });

    document.addEventListener('DOMContentLoaded', function () {
        updateLinkedQuestionTypeSections(document);
    });

    updateLinkedQuestionTypeSections(document);
})();
</script>

<?php require_once __DIR__ . '/include/footer.php'; ?>