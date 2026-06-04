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

function excerptText(?string $text, int $length = 120): string
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

function sanitizeQuestionIds(PDO $pdo, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));

    if (empty($ids)) {
        return [];
    }

    $placeholders = [];
    $bindings = [];

    foreach ($ids as $index => $id) {
        $ph = ':id_' . $index;
        $placeholders[] = $ph;
        $bindings[$ph] = $id;
    }

    $sql = "SELECT id FROM question_bank WHERE status = 'approved' AND id IN (" . implode(',', $placeholders) . ")";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($bindings);

    $validIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $validIds = array_map('intval', $validIds);
    $validMap = array_flip($validIds);

    $result = [];
    foreach ($ids as $id) {
        if (isset($validMap[$id])) {
            $result[] = $id;
        }
    }

    return $result;
}

function queryQuestionBankIds(
    PDO $pdo,
    string $language,
    string $topic,
    ?string $difficulty,
    int $limit,
    array $excludeIds = []
): array {
    if ($limit <= 0) {
        return [];
    }

    $sql = "
        SELECT id
        FROM question_bank
        WHERE status = 'approved'
          AND programming_language = :language
    ";

    $bindings = [
        ':language' => $language,
    ];

    if ($topic !== '') {
        $sql .= " AND topic LIKE :topic";
        $bindings[':topic'] = '%' . $topic . '%';
    }

    if ($difficulty !== null && $difficulty !== '') {
        $sql .= " AND difficulty_level = :difficulty";
        $bindings[':difficulty'] = $difficulty;
    }

    if (!empty($excludeIds)) {
        $phs = [];
        foreach ($excludeIds as $index => $id) {
            $ph = ':ex_' . $index;
            $phs[] = $ph;
            $bindings[$ph] = (int)$id;
        }
        $sql .= " AND id NOT IN (" . implode(',', $phs) . ")";
    }

    $sql .= " ORDER BY id DESC LIMIT " . (int)$limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($bindings);

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function autoFillQuestionIds(PDO $pdo, array $exam): array
{
    $language   = trim((string)$exam['programming_language']);
    $topic      = trim((string)$exam['topic']);
    $difficulty = trim((string)$exam['difficulty_level']);
    $count      = (int)$exam['question_count'];

    if ($language === '' || $count < 1) {
        return [];
    }

    $selected = [];

    if ($difficulty === 'Mixed') {
        $base = intdiv($count, 3);
        $easyTarget   = $base;
        $mediumTarget = $base;
        $hardTarget   = $base;

        $remainder = $count - ($easyTarget + $mediumTarget + $hardTarget);

        if ($remainder > 0) {
            $mediumTarget++;
            $remainder--;
        }
        if ($remainder > 0) {
            $easyTarget++;
            $remainder--;
        }
        if ($remainder > 0) {
            $hardTarget++;
        }

        $selected = array_merge(
            $selected,
            queryQuestionBankIds($pdo, $language, $topic, 'Easy', $easyTarget, $selected)
        );

        $selected = array_merge(
            $selected,
            queryQuestionBankIds($pdo, $language, $topic, 'Medium', $mediumTarget, $selected)
        );

        $selected = array_merge(
            $selected,
            queryQuestionBankIds($pdo, $language, $topic, 'Hard', $hardTarget, $selected)
        );

        $remaining = $count - count($selected);
        if ($remaining > 0) {
            $selected = array_merge(
                $selected,
                queryQuestionBankIds($pdo, $language, $topic, null, $remaining, $selected)
            );
        }
    } else {
        $selected = queryQuestionBankIds($pdo, $language, $topic, $difficulty, $count, []);

        $remaining = $count - count($selected);
        if ($remaining > 0) {
            $selected = array_merge(
                $selected,
                queryQuestionBankIds($pdo, $language, $topic, null, $remaining, $selected)
            );
        }
    }

    return array_values(array_unique($selected));
}

function saveExamQuestionMappings(PDO $pdo, int $examId, array $questionIds, int $plannedCount): int
{
    $pdo->beginTransaction();

    try {
        $deleteStmt = $pdo->prepare("DELETE FROM exam_question_bank WHERE exam_id = :exam_id");
        $deleteStmt->execute([':exam_id' => $examId]);

        $insertStmt = $pdo->prepare("
            INSERT INTO exam_question_bank (
                exam_id,
                question_bank_id,
                question_order,
                created_at
            ) VALUES (
                :exam_id,
                :question_bank_id,
                :question_order,
                NOW()
            )
        ");

        $order = 1;
        foreach ($questionIds as $questionId) {
            $insertStmt->execute([
                ':exam_id'          => $examId,
                ':question_bank_id' => $questionId,
                ':question_order'   => $order,
            ]);
            $order++;
        }

        $savedCount = count($questionIds);

        $newStatus = 'draft';
        if ($savedCount > 0 && $savedCount < $plannedCount) {
            $newStatus = 'ready';
        } elseif ($savedCount >= $plannedCount && $plannedCount > 0) {
            $newStatus = 'generated';
        }

        $updateStmt = $pdo->prepare("
            UPDATE exams
            SET status = :status
            WHERE id = :exam_id
            LIMIT 1
        ");
        $updateStmt->execute([
            ':status'  => $newStatus,
            ':exam_id' => $examId,
        ]);

        $pdo->commit();
        return $savedCount;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

$examId = (int)($_GET['exam_id'] ?? $_POST['exam_id'] ?? 0);
if ($examId <= 0) {
    die('Invalid exam ID.');
}

$exam = fetchExamById($pdoConnection, $examId);
if (!$exam) {
    die('Exam not found.');
}

$errors = [];
$success = '';
$warning = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'save_selected') {
        $selectedQuestionIds = sanitizeQuestionIds($pdoConnection, $_POST['selected_question_ids'] ?? []);
        $plannedCount = (int)$exam['question_count'];

        if (empty($selectedQuestionIds)) {
            $errors[] = 'Please select at least one question from the Question Bank.';
        }

        if (count($selectedQuestionIds) > $plannedCount) {
            $errors[] = 'You selected more questions than the planned exam count.';
        }

        if (empty($errors)) {
            try {
                $savedCount = saveExamQuestionMappings($pdoConnection, $examId, $selectedQuestionIds, $plannedCount);
                $success = $savedCount . ' question(s) have been linked to this exam successfully.';

                if ($savedCount < $plannedCount) {
                    $warning = 'The exam is still incomplete because the linked count is less than the planned count.';
                }

                $exam = fetchExamById($pdoConnection, $examId);
            } catch (Throwable $e) {
                $errors[] = 'Save failed: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'auto_fill') {
        try {
            $autoSelectedIds = autoFillQuestionIds($pdoConnection, $exam);
            $plannedCount = (int)$exam['question_count'];

            if (empty($autoSelectedIds)) {
                $errors[] = 'No matching approved questions were found for this exam.';
            } else {
                if (count($autoSelectedIds) > $plannedCount) {
                    $autoSelectedIds = array_slice($autoSelectedIds, 0, $plannedCount);
                }

                $savedCount = saveExamQuestionMappings($pdoConnection, $examId, $autoSelectedIds, $plannedCount);
                $success = 'Auto Fill completed successfully. ' . $savedCount . ' question(s) were linked.';

                if ($savedCount < $plannedCount) {
                    $warning = 'Auto Fill could not reach the full planned count because the Question Bank does not contain enough matching questions.';
                }

                $exam = fetchExamById($pdoConnection, $examId);
            }
        } catch (Throwable $e) {
            $errors[] = 'Auto Fill failed: ' . $e->getMessage();
        }
    }

    if ($action === 'clear_build') {
        try {
            saveExamQuestionMappings($pdoConnection, $examId, [], (int)$exam['question_count']);
            $success = 'All linked questions have been removed from this exam.';
            $exam = fetchExamById($pdoConnection, $examId);
        } catch (Throwable $e) {
            $errors[] = 'Clear action failed: ' . $e->getMessage();
        }
    }
}

$currentLinkedQuestions = fetchLinkedQuestions($pdoConnection, $examId);
$currentLinkedIds = array_map(static fn($row) => (int)$row['id'], $currentLinkedQuestions);
$currentLinkedMap = array_flip($currentLinkedIds);

$searchFilter     = trim((string)($_GET['search'] ?? ''));
$languageFilter   = trim((string)($_GET['language'] ?? $exam['programming_language']));
$topicFilter      = trim((string)($_GET['topic'] ?? $exam['topic']));
$difficultyFilter = trim((string)($_GET['difficulty'] ?? ($exam['difficulty_level'] === 'Mixed' ? '' : $exam['difficulty_level'])));
$typeFilter       = trim((string)($_GET['type'] ?? ''));

$whereParts = ["qb.status = 'approved'"];
$bindings   = [];

if ($searchFilter !== '') {
    $whereParts[] = "(qb.question_text LIKE :search OR qb.model_answer LIKE :search OR qb.topic LIKE :search OR CAST(qb.id AS CHAR) LIKE :search)";
    $bindings[':search'] = '%' . $searchFilter . '%';
}

if ($languageFilter !== '') {
    $whereParts[] = "qb.programming_language = :language";
    $bindings[':language'] = $languageFilter;
}

if ($topicFilter !== '') {
    $whereParts[] = "qb.topic LIKE :topic";
    $bindings[':topic'] = '%' . $topicFilter . '%';
}

if ($difficultyFilter !== '') {
    $whereParts[] = "qb.difficulty_level = :difficulty";
    $bindings[':difficulty'] = $difficultyFilter;
}

if ($typeFilter !== '') {
    $whereParts[] = "qb.question_type = :question_type";
    $bindings[':question_type'] = $typeFilter;
}

$candidateSql = "
    SELECT qb.*
    FROM question_bank qb
    WHERE " . implode(' AND ', $whereParts) . "
    ORDER BY qb.id DESC
";

$candidateStmt = $pdoConnection->prepare($candidateSql);
$candidateStmt->execute($bindings);
$candidateQuestions = $candidateStmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle   = 'Build Exam';
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
    grid-template-columns: 2fr 1fr 1fr 1fr 1fr;
    gap: 16px;
}

.detail-grid {
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

.counter-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 96px;
    padding: 10px 14px;
    border-radius: 999px;
    background: var(--primary-light);
    color: var(--primary-dark);
    font-size: 13px;
    font-weight: 800;
}

.table-checkbox {
    width: 18px;
    height: 18px;
    accent-color: #2563eb;
}

.help-text {
    color: var(--text-muted);
    font-size: 13px;
    margin-top: 10px;
}

.detail-stack {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.detail-line {
    font-size: 13px;
    color: var(--text-soft);
}

.page-note {
    background: var(--info-light);
    color: #155e75;
    border: 1px solid rgba(8, 145, 178, 0.15);
    padding: 14px 16px;
    border-radius: 14px;
    font-weight: 600;
}

@media (max-width: 1100px) {
    .filters-grid,
    .detail-grid {
        grid-template-columns: 1fr;
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
                <ul style="margin-top: 10px; padding-left: 18px; list-style: disc;">
                    <?php foreach ($errors as $error): ?>
                        <li><?= e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="alert alert-success mt-4"><?= e($success) ?></div>
        <?php endif; ?>

        <?php if ($warning !== ''): ?>
            <div class="alert alert-warning mt-4"><?= e($warning) ?></div>
        <?php endif; ?>

        <div class="form-card mt-4">
            <div class="section-title">
                <h2 class="mb-0">Exam Information</h2>
            </div>

            <div class="detail-grid">
                <div class="detail-box">
                    <h4>Exam ID</h4>
                    <p>#<?= (int)$exam['id'] ?></p>
                </div>

                <div class="detail-box">
                    <h4>Exam Title</h4>
                    <p><?= e($exam['exam_title']) ?></p>
                </div>

                <div class="detail-box">
                    <h4>Topic</h4>
                    <p><?= e($exam['topic']) ?></p>
                </div>

                <div class="detail-box">
                    <h4>Programming Language</h4>
                    <p><?= e($exam['programming_language']) ?></p>
                </div>

                <div class="detail-box">
                    <h4>Difficulty</h4>
                    <p>
                        <span class="badge <?= difficultyBadgeClass((string)$exam['difficulty_level']) ?>">
                            <?= e($exam['difficulty_level']) ?>
                        </span>
                    </p>
                </div>

                <div class="detail-box">
                    <h4>Progress</h4>
                    <p><?= (int)$exam['linked_questions'] ?> / <?= (int)$exam['question_count'] ?> linked</p>
                </div>

                <div class="detail-box">
                    <h4>Duration</h4>
                    <p><?= (int)$exam['duration_minutes'] ?> minutes</p>
                </div>

                <div class="detail-box">
                    <h4>Created By</h4>
                    <p><?= e($exam['creator_name'] ?: 'Unknown') ?></p>
                </div>

                <div class="detail-box detail-wide">
                    <h4>Notes</h4>
                    <p><?= e($exam['notes'] ?: 'No notes added.') ?></p>
                </div>
            </div>

            <div class="toolbar mt-4">
                <div class="toolbar-left">
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="exam_id" value="<?= (int)$exam['id'] ?>">
                        <input type="hidden" name="action" value="auto_fill">
                        <button type="submit" class="btn btn-success">Auto Fill</button>
                    </form>

                    <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to clear all linked questions from this exam?');">
                        <input type="hidden" name="exam_id" value="<?= (int)$exam['id'] ?>">
                        <input type="hidden" name="action" value="clear_build">
                        <button type="submit" class="btn btn-outline-danger">Clear Build</button>
                    </form>
                </div>

                <div class="toolbar-right">
                    <span class="counter-badge">
                        Linked <?= (int)$exam['linked_questions'] ?> / <?= (int)$exam['question_count'] ?>
                    </span>
                </div>
            </div>
        </div>

        <div class="form-card mt-4">
            <div class="section-title">
                <h2 class="mb-0">Filter Question Bank</h2>
            </div>

            <p class="section-subtitle">
                Filter approved questions, then choose the items you want to attach to this exam.
            </p>

            <form method="GET" action="">
                <input type="hidden" name="exam_id" value="<?= (int)$exam['id'] ?>">

                <div class="filters-grid">
                    <div class="form-group mb-0">
                        <label class="form-label">Search</label>
                        <input
                            type="search"
                            name="search"
                            value="<?= e($searchFilter) ?>"
                            placeholder="Search by ID, topic, question text, or answer..."
                        >
                    </div>

                    <div class="form-group mb-0">
                        <label class="form-label">Language</label>
                        <input
                            type="text"
                            name="language"
                            value="<?= e($languageFilter) ?>"
                            placeholder="e.g. Python"
                        >
                    </div>

                    <div class="form-group mb-0">
                        <label class="form-label">Topic</label>
                        <input
                            type="text"
                            name="topic"
                            value="<?= e($topicFilter) ?>"
                            placeholder="e.g. OOP"
                        >
                    </div>

                    <div class="form-group mb-0">
                        <label class="form-label">Difficulty</label>
                        <select name="difficulty">
                            <option value="">All</option>
                            <option value="Easy" <?= selectedValue($difficultyFilter, 'Easy') ?>>Easy</option>
                            <option value="Medium" <?= selectedValue($difficultyFilter, 'Medium') ?>>Medium</option>
                            <option value="Hard" <?= selectedValue($difficultyFilter, 'Hard') ?>>Hard</option>
                        </select>
                    </div>

                    <div class="form-group mb-0">
                        <label class="form-label">Question Type</label>
                        <select name="type">
                            <option value="">All</option>
                            <option value="mcq" <?= selectedValue($typeFilter, 'mcq') ?>>MCQ</option>
                            <option value="true_false" <?= selectedValue($typeFilter, 'true_false') ?>>True / False</option>
                            <option value="short_answer" <?= selectedValue($typeFilter, 'short_answer') ?>>Short Answer</option>
                            <option value="code_writing" <?= selectedValue($typeFilter, 'code_writing') ?>>Code Writing</option>
                            <option value="debugging" <?= selectedValue($typeFilter, 'debugging') ?>>Debugging</option>
                            <option value="output_prediction" <?= selectedValue($typeFilter, 'output_prediction') ?>>Output Prediction</option>
                        </select>
                    </div>
                </div>

                <div class="toolbar mt-4">
                    <div class="toolbar-left">
                        <button type="submit" class="btn btn-primary">Apply Filters</button>
                        <a href="build_exam.php?exam_id=<?= (int)$exam['id'] ?>" class="btn btn-light">Reset Filters</a>
                    </div>

                    <div class="toolbar-right">
                        <span class="badge badge-primary">Candidates: <?= count($candidateQuestions) ?></span>
                    </div>
                </div>
            </form>
        </div>

        <div class="page-note mt-4">
            The selected order will follow the visible table order. You can attach up to <?= (int)$exam['question_count'] ?> questions.
        </div>

        <div class="table-card mt-4">
            <div class="table-header">
                <h3 class="table-title">Question Bank Candidates</h3>
                <p class="table-subtitle">
                    Select the questions you want to add to this exam.
                </p>
            </div>

            <div class="card-body">
                <div class="toolbar">
                    <div class="toolbar-left">
                        <button type="button" class="btn btn-light btn-sm" onclick="selectAllVisible()">Select All</button>
                        <button type="button" class="btn btn-light btn-sm" onclick="clearAllVisible()">Clear All</button>
                        <button type="button" class="btn btn-light btn-sm" onclick="autoSelectTopNeeded()">Select Top Needed</button>
                    </div>

                    <div class="toolbar-right">
                        <span class="counter-badge" id="selectedCounter">
                            Selected 0 / <?= (int)$exam['question_count'] ?>
                        </span>
                    </div>
                </div>

                <form method="POST" id="buildExamForm">
                    <input type="hidden" name="exam_id" value="<?= (int)$exam['id'] ?>">
                    <input type="hidden" name="action" value="save_selected">

                    <?php if (empty($candidateQuestions)): ?>
                        <div class="table-empty">
                            No approved question bank items matched the current filters.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="custom-table table-striped">
                                <thead>
                                    <tr>
                                        <th style="width: 70px;">Pick</th>
                                        <th>#ID</th>
                                        <th>Language</th>
                                        <th>Topic</th>
                                        <th>Difficulty</th>
                                        <th>Type</th>
                                        <th>Marks</th>
                                        <th>Question</th>
                                        <th>Answer / Correct</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($candidateQuestions as $row): ?>
                                        <?php $isChecked = isset($currentLinkedMap[(int)$row['id']]); ?>
                                        <tr>
                                            <td>
                                                <input
                                                    type="checkbox"
                                                    class="table-checkbox question-selector"
                                                    name="selected_question_ids[]"
                                                    value="<?= (int)$row['id'] ?>"
                                                    <?= $isChecked ? 'checked' : '' ?>
                                                >
                                            </td>
                                            <td><strong>#<?= (int)$row['id'] ?></strong></td>
                                            <td><?= e($row['programming_language']) ?></td>
                                            <td><?= e($row['topic']) ?></td>
                                            <td>
                                                <span class="badge <?= difficultyBadgeClass((string)$row['difficulty_level']) ?>">
                                                    <?= e($row['difficulty_level']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge badge-primary">
                                                    <?= e(questionTypeLabel((string)$row['question_type'])) ?>
                                                </span>
                                            </td>
                                            <td><?= (int)$row['marks'] ?></td>

                                            <td style="min-width:280px;">
                                                <details>
                                                    <summary style="cursor:pointer;font-weight:700;">View Question</summary>
                                                    <div style="margin-top:10px;white-space:pre-wrap;"><?= nl2br(e($row['question_text'])) ?></div>

                                                    <?php if ((string)$row['question_type'] === 'mcq'): ?>
                                                        <div class="detail-stack" style="margin-top:12px;">
                                                            <div class="detail-line"><strong>A)</strong> <?= e($row['option_a']) ?></div>
                                                            <div class="detail-line"><strong>B)</strong> <?= e($row['option_b']) ?></div>
                                                            <div class="detail-line"><strong>C)</strong> <?= e($row['option_c']) ?></div>
                                                            <div class="detail-line"><strong>D)</strong> <?= e($row['option_d']) ?></div>
                                                        </div>
                                                    <?php endif; ?>

                                                    <?php if ((string)$row['question_type'] === 'true_false'): ?>
                                                        <div class="detail-stack" style="margin-top:12px;">
                                                            <div class="detail-line"><strong>Option 1:</strong> True</div>
                                                            <div class="detail-line"><strong>Option 2:</strong> False</div>
                                                        </div>
                                                    <?php endif; ?>
                                                </details>
                                            </td>

                                            <td style="min-width:240px;">
                                                <details>
                                                    <summary style="cursor:pointer;font-weight:700;">View Answer</summary>
                                                    <?php if (!empty($row['correct_answer'])): ?>
                                                        <div style="margin-top:10px;">
                                                            <strong>Correct Answer:</strong> <?= e($row['correct_answer']) ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <div style="margin-top:10px;white-space:pre-wrap;"><?= nl2br(e($row['model_answer'])) ?></div>
                                                </details>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <p class="help-text">
                            If you select fewer questions than planned, the exam status will become <strong>ready</strong>. If you reach the full required count, it will become <strong>generated</strong>.
                        </p>

                        <div class="toolbar mt-4">
                            <div class="toolbar-left">
                                <button type="submit" class="btn btn-primary">Save Selected to Exam</button>
                            </div>
                        </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <div class="table-card mt-4">
            <div class="table-header">
                <h3 class="table-title">Currently Linked Questions</h3>
                <p class="table-subtitle">
                    These are the questions currently attached to this exam.
                </p>
            </div>

            <?php if (empty($currentLinkedQuestions)): ?>
                <div class="table-empty">
                    No questions linked yet.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="custom-table table-striped">
                        <thead>
                            <tr>
                                <th>Order</th>
                                <th>#ID</th>
                                <th>Type</th>
                                <th>Difficulty</th>
                                <th>Marks</th>
                                <th>Question</th>
                                <th>Answer / Correct</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($currentLinkedQuestions as $question): ?>
                                <tr>
                                    <td><?= (int)$question['question_order'] ?></td>
                                    <td>#<?= (int)$question['id'] ?></td>
                                    <td>
                                        <span class="badge badge-primary">
                                            <?= e(questionTypeLabel((string)$question['question_type'])) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge <?= difficultyBadgeClass((string)$question['difficulty_level']) ?>">
                                            <?= e($question['difficulty_level']) ?>
                                        </span>
                                    </td>
                                    <td><?= (int)$question['marks'] ?></td>

                                    <td style="min-width:280px;">
                                        <details>
                                            <summary style="cursor:pointer;font-weight:700;">View Question</summary>
                                            <div style="margin-top:10px;white-space:pre-wrap;"><?= nl2br(e($question['question_text'])) ?></div>

                                            <?php if ((string)$question['question_type'] === 'mcq'): ?>
                                                <div class="detail-stack" style="margin-top:12px;">
                                                    <div class="detail-line"><strong>A)</strong> <?= e($question['option_a']) ?></div>
                                                    <div class="detail-line"><strong>B)</strong> <?= e($question['option_b']) ?></div>
                                                    <div class="detail-line"><strong>C)</strong> <?= e($question['option_c']) ?></div>
                                                    <div class="detail-line"><strong>D)</strong> <?= e($question['option_d']) ?></div>
                                                </div>
                                            <?php endif; ?>

                                            <?php if ((string)$question['question_type'] === 'true_false'): ?>
                                                <div class="detail-stack" style="margin-top:12px;">
                                                    <div class="detail-line"><strong>Option 1:</strong> True</div>
                                                    <div class="detail-line"><strong>Option 2:</strong> False</div>
                                                </div>
                                            <?php endif; ?>
                                        </details>
                                    </td>

                                    <td style="min-width:240px;">
                                        <details>
                                            <summary style="cursor:pointer;font-weight:700;">View Answer</summary>
                                            <?php if (!empty($question['correct_answer'])): ?>
                                                <div style="margin-top:10px;">
                                                    <strong>Correct Answer:</strong> <?= e($question['correct_answer']) ?>
                                                </div>
                                            <?php endif; ?>
                                            <div style="margin-top:10px;white-space:pre-wrap;"><?= nl2br(e($question['model_answer'])) ?></div>
                                        </details>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
    updateSelectedCounter();

    document.querySelectorAll('.question-selector').forEach(function (checkbox) {
        checkbox.addEventListener('change', updateSelectedCounter);
    });

    const form = document.getElementById('buildExamForm');
    if (form) {
        form.addEventListener('submit', function (e) {
            const maxAllowed = <?= (int)$exam['question_count'] ?>;
            const selected = document.querySelectorAll('.question-selector:checked').length;

            if (selected > maxAllowed) {
                e.preventDefault();
                alert('You selected more questions than the exam planned count.');
            }
        });
    }
});

function updateSelectedCounter() {
    const selected = document.querySelectorAll('.question-selector:checked').length;
    const maxAllowed = <?= (int)$exam['question_count'] ?>;
    const counter = document.getElementById('selectedCounter');

    if (counter) {
        counter.textContent = 'Selected ' + selected + ' / ' + maxAllowed;
    }
}

function selectAllVisible() {
    const maxAllowed = <?= (int)$exam['question_count'] ?>;
    const checkboxes = Array.from(document.querySelectorAll('.question-selector'));
    let count = 0;

    checkboxes.forEach(function (checkbox) {
        if (count < maxAllowed) {
            checkbox.checked = true;
            count++;
        } else {
            checkbox.checked = false;
        }
    });

    updateSelectedCounter();
}

function clearAllVisible() {
    document.querySelectorAll('.question-selector').forEach(function (checkbox) {
        checkbox.checked = false;
    });

    updateSelectedCounter();
}

function autoSelectTopNeeded() {
    const maxAllowed = <?= (int)$exam['question_count'] ?>;
    const checkboxes = Array.from(document.querySelectorAll('.question-selector'));

    checkboxes.forEach(function (checkbox) {
        checkbox.checked = false;
    });

    for (let i = 0; i < checkboxes.length && i < maxAllowed; i++) {
        checkboxes[i].checked = true;
    }

    updateSelectedCounter();
}
</script>

<?php require_once __DIR__ . '/include/footer.php'; ?>