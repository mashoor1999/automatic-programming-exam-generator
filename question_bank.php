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

function badgeClassByDifficulty(string $difficulty): string
{
    return match ($difficulty) {
        'Easy'   => 'badge-success',
        'Medium' => 'badge-info',
        'Hard'   => 'badge-danger',
        default  => 'badge-primary',
    };
}

function badgeClassBySource(string $source): string
{
    return match ($source) {
        'ai'     => 'badge-primary',
        'manual' => 'badge-dark',
        default  => 'badge-info',
    };
}

function badgeClassByStatus(string $status): string
{
    return match ($status) {
        'approved' => 'badge-success',
        'pending'  => 'badge-warning',
        'archived' => 'badge-dark',
        default    => 'badge-info',
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

function normalizeRowForSave(array $data): array
{
    $row = [
        'programming_language' => trim((string)($data['programming_language'] ?? '')),
        'topic'                => trim((string)($data['topic'] ?? '')),
        'difficulty_level'     => trim((string)($data['difficulty_level'] ?? 'Medium')),
        'question_type'        => trim((string)($data['question_type'] ?? 'short_answer')),
        'question_text'        => trim((string)($data['question_text'] ?? '')),
        'option_a'             => trim((string)($data['option_a'] ?? '')),
        'option_b'             => trim((string)($data['option_b'] ?? '')),
        'option_c'             => trim((string)($data['option_c'] ?? '')),
        'option_d'             => trim((string)($data['option_d'] ?? '')),
        'correct_answer'       => trim((string)($data['correct_answer'] ?? '')),
        'model_answer'         => trim((string)($data['model_answer'] ?? '')),
        'marks'                => (int)($data['marks'] ?? 5),
        'status'               => trim((string)($data['status'] ?? 'approved')),
    ];

    if ($row['question_type'] === 'true_false') {
        $row['option_a'] = 'True';
        $row['option_b'] = 'False';
        $row['option_c'] = '';
        $row['option_d'] = '';
    }

    if (in_array($row['question_type'], ['short_answer', 'code_writing', 'debugging', 'output_prediction'], true)) {
        $row['option_a'] = '';
        $row['option_b'] = '';
        $row['option_c'] = '';
        $row['option_d'] = '';
        $row['correct_answer'] = '';
    }

    return $row;
}

$errors = [];
$success = '';

$editQuestionId = (int)($_GET['edit'] ?? $_POST['edit_question_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'update_question') {
        $questionId = (int)($_POST['edit_question_id'] ?? 0);
        $row = normalizeRowForSave($_POST);

        $allowedTypes = ['mcq', 'true_false', 'short_answer', 'code_writing', 'debugging', 'output_prediction'];
        $allowedDifficulties = ['Easy', 'Medium', 'Hard'];
        $allowedStatuses = ['approved', 'pending', 'archived'];

        if ($questionId <= 0) {
            $errors[] = 'Invalid question ID.';
        }

        if ($row['programming_language'] === '') {
            $errors[] = 'Programming language is required.';
        }

        if ($row['topic'] === '') {
            $errors[] = 'Topic is required.';
        }

        if ($row['question_text'] === '') {
            $errors[] = 'Question text is required.';
        }

        if (!in_array($row['question_type'], $allowedTypes, true)) {
            $errors[] = 'Invalid question type.';
        }

        if (!in_array($row['difficulty_level'], $allowedDifficulties, true)) {
            $errors[] = 'Invalid difficulty level.';
        }

        if (!in_array($row['status'], $allowedStatuses, true)) {
            $errors[] = 'Invalid status.';
        }

        if ($row['marks'] < 1 || $row['marks'] > 100) {
            $errors[] = 'Marks must be between 1 and 100.';
        }

        if ($row['question_type'] === 'mcq') {
            if ($row['option_a'] === '' || $row['option_b'] === '' || $row['option_c'] === '' || $row['option_d'] === '') {
                $errors[] = 'All MCQ options are required.';
            }

            if (!in_array($row['correct_answer'], ['A', 'B', 'C', 'D'], true)) {
                $errors[] = 'MCQ correct answer must be A, B, C, or D.';
            }
        }

        if ($row['question_type'] === 'true_false') {
            if (!in_array($row['correct_answer'], ['True', 'False'], true)) {
                $errors[] = 'True / False correct answer must be True or False.';
            }
        }

        if (empty($errors)) {
            try {
                $stmt = $pdoConnection->prepare("
                    UPDATE question_bank
                    SET
                        programming_language = :programming_language,
                        topic = :topic,
                        difficulty_level = :difficulty_level,
                        question_type = :question_type,
                        question_text = :question_text,
                        option_a = :option_a,
                        option_b = :option_b,
                        option_c = :option_c,
                        option_d = :option_d,
                        correct_answer = :correct_answer,
                        model_answer = :model_answer,
                        marks = :marks,
                        status = :status
                    WHERE id = :id
                    LIMIT 1
                ");

                $stmt->execute([
                    ':programming_language' => $row['programming_language'],
                    ':topic'                => $row['topic'],
                    ':difficulty_level'     => $row['difficulty_level'],
                    ':question_type'        => $row['question_type'],
                    ':question_text'        => $row['question_text'],
                    ':option_a'             => $row['option_a'] !== '' ? $row['option_a'] : null,
                    ':option_b'             => $row['option_b'] !== '' ? $row['option_b'] : null,
                    ':option_c'             => $row['option_c'] !== '' ? $row['option_c'] : null,
                    ':option_d'             => $row['option_d'] !== '' ? $row['option_d'] : null,
                    ':correct_answer'       => $row['correct_answer'] !== '' ? $row['correct_answer'] : null,
                    ':model_answer'         => $row['model_answer'],
                    ':marks'                => $row['marks'],
                    ':status'               => $row['status'],
                    ':id'                   => $questionId,
                ]);

                $success = 'Question updated successfully.';
                $editQuestionId = $questionId;
            } catch (Throwable $e) {
                $errors[] = 'Update failed: ' . $e->getMessage();
            }
        }
    }
}

$filters = [
    'search'               => trim((string)($_GET['search'] ?? '')),
    'programming_language' => trim((string)($_GET['programming_language'] ?? '')),
    'difficulty_level'     => trim((string)($_GET['difficulty_level'] ?? '')),
    'question_type'        => trim((string)($_GET['question_type'] ?? '')),
    'source_type'          => trim((string)($_GET['source_type'] ?? '')),
    'status'               => trim((string)($_GET['status'] ?? 'approved')),
];

$where  = [];
$params = [];

if ($filters['search'] !== '') {
    $where[] = '(qb.question_text LIKE :search OR qb.model_answer LIKE :search OR qb.topic LIKE :search OR CAST(qb.id AS CHAR) LIKE :search)';
    $params[':search'] = '%' . $filters['search'] . '%';
}

if ($filters['programming_language'] !== '') {
    $where[] = 'qb.programming_language = :programming_language';
    $params[':programming_language'] = $filters['programming_language'];
}

if ($filters['difficulty_level'] !== '') {
    $where[] = 'qb.difficulty_level = :difficulty_level';
    $params[':difficulty_level'] = $filters['difficulty_level'];
}

if ($filters['question_type'] !== '') {
    $where[] = 'qb.question_type = :question_type';
    $params[':question_type'] = $filters['question_type'];
}

if ($filters['source_type'] !== '') {
    $where[] = 'qb.source_type = :source_type';
    $params[':source_type'] = $filters['source_type'];
}

if ($filters['status'] !== '') {
    $where[] = 'qb.status = :status';
    $params[':status'] = $filters['status'];
}

$sql = "
    SELECT
        qb.*,
        u.full_name AS created_by_name
    FROM question_bank qb
    LEFT JOIN users u
        ON u.id = qb.created_by
";

if (!empty($where)) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY qb.id DESC';

$stmt = $pdoConnection->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$selectedQuestion = null;
if ($editQuestionId > 0) {
    $editStmt = $pdoConnection->prepare("
        SELECT
            qb.*,
            u.full_name AS created_by_name
        FROM question_bank qb
        LEFT JOIN users u
            ON u.id = qb.created_by
        WHERE qb.id = :id
        LIMIT 1
    ");
    $editStmt->execute([':id' => $editQuestionId]);
    $selectedQuestion = $editStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$pageTitle   = 'Question Bank';
$currentPage = 'question_bank';
$basePath    = '';

$pageStyles = <<<CSS
.page-actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}

.filters-grid {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr 1fr 1fr 1fr;
    gap: 16px;
}

.question-editor-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 20px;
    padding: 20px;
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
    background: var(--info-light);
    color: #155e75;
    border: 1px solid rgba(8, 145, 178, 0.15);
    padding: 14px 16px;
    border-radius: 14px;
    font-weight: 600;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 16px;
}

.info-box {
    background: var(--bg-card-2);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 16px 18px;
}

.info-box h4 {
    margin-bottom: 6px;
    font-size: 13px;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.4px;
}

.info-box p {
    margin: 0;
    color: var(--text-main);
    font-weight: 600;
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

@media (max-width: 1100px) {
    .filters-grid,
    .info-grid,
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
                <ul style="margin-top:10px;padding-left:18px;list-style:disc;">
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
                <h2 class="mb-0">Filters</h2>
            </div>

            <p class="section-subtitle">
                Search and narrow the question list by language, difficulty, type, source, or status.
            </p>

            <form method="GET">
                <div class="filters-grid">
                    <div class="form-group mb-0">
                        <label class="form-label">Search</label>
                        <input
                            type="search"
                            name="search"
                            value="<?= e($filters['search']) ?>"
                            placeholder="Search by ID, topic, question text, or answer..."
                        >
                    </div>

                    <div class="form-group mb-0">
                        <label class="form-label">Language</label>
                        <select name="programming_language">
                            <option value="">All</option>
                            <option value="C" <?= selectedValue($filters['programming_language'], 'C') ?>>C</option>
                            <option value="C++" <?= selectedValue($filters['programming_language'], 'C++') ?>>C++</option>
                            <option value="Java" <?= selectedValue($filters['programming_language'], 'Java') ?>>Java</option>
                            <option value="Python" <?= selectedValue($filters['programming_language'], 'Python') ?>>Python</option>
                            <option value="JavaScript" <?= selectedValue($filters['programming_language'], 'JavaScript') ?>>JavaScript</option>
                            <option value="PHP" <?= selectedValue($filters['programming_language'], 'PHP') ?>>PHP</option>
                            <option value="SQL" <?= selectedValue($filters['programming_language'], 'SQL') ?>>SQL</option>
                        </select>
                    </div>

                    <div class="form-group mb-0">
                        <label class="form-label">Difficulty</label>
                        <select name="difficulty_level">
                            <option value="">All</option>
                            <option value="Easy" <?= selectedValue($filters['difficulty_level'], 'Easy') ?>>Easy</option>
                            <option value="Medium" <?= selectedValue($filters['difficulty_level'], 'Medium') ?>>Medium</option>
                            <option value="Hard" <?= selectedValue($filters['difficulty_level'], 'Hard') ?>>Hard</option>
                        </select>
                    </div>

                    <div class="form-group mb-0">
                        <label class="form-label">Type</label>
                        <select name="question_type">
                            <option value="">All</option>
                            <option value="mcq" <?= selectedValue($filters['question_type'], 'mcq') ?>>MCQ</option>
                            <option value="true_false" <?= selectedValue($filters['question_type'], 'true_false') ?>>True / False</option>
                            <option value="short_answer" <?= selectedValue($filters['question_type'], 'short_answer') ?>>Short Answer</option>
                            <option value="code_writing" <?= selectedValue($filters['question_type'], 'code_writing') ?>>Code Writing</option>
                            <option value="debugging" <?= selectedValue($filters['question_type'], 'debugging') ?>>Debugging</option>
                            <option value="output_prediction" <?= selectedValue($filters['question_type'], 'output_prediction') ?>>Output Prediction</option>
                        </select>
                    </div>

                    <div class="form-group mb-0">
                        <label class="form-label">Source</label>
                        <select name="source_type">
                            <option value="">All</option>
                            <option value="manual" <?= selectedValue($filters['source_type'], 'manual') ?>>manual</option>
                            <option value="ai" <?= selectedValue($filters['source_type'], 'ai') ?>>ai</option>
                        </select>
                    </div>

                    <div class="form-group mb-0">
                        <label class="form-label">Status</label>
                        <select name="status">
                            <option value="">All</option>
                            <option value="approved" <?= selectedValue($filters['status'], 'approved') ?>>approved</option>
                            <option value="pending" <?= selectedValue($filters['status'], 'pending') ?>>pending</option>
                            <option value="archived" <?= selectedValue($filters['status'], 'archived') ?>>archived</option>
                        </select>
                    </div>
                </div>

                <div class="toolbar mt-4">
                    <div class="toolbar-left">
                        <button type="submit" class="btn btn-primary">Apply Filters</button>
                        <a href="question_bank.php" class="btn btn-light">Reset</a>
                    </div>
                </div>
            </form>
        </div>

        <div class="table-card mt-4">
            <div class="table-header">
                <h3 class="table-title">Stored Questions</h3>
                <p class="table-subtitle"><?= count($rows) ?> question(s) matched the current filters.</p>
            </div>

            <div class="table-responsive">
                <table class="custom-table table-striped">
                    <thead>
                        <tr>
                            <th>#ID</th>
                            <th>Language</th>
                            <th>Topic</th>
                            <th>Difficulty</th>
                            <th>Type</th>
                            <th>Marks</th>
                            <th>Source</th>
                            <th>Status</th>
                            <th>Question</th>
                            <th>Answer / Correct</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr>
                                <td colspan="11" class="table-empty">No questions found in the Question Bank.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($rows as $row): ?>
                                <tr>
                                    <td><strong><?= (int)$row['id'] ?></strong></td>
                                    <td><?= e($row['programming_language']) ?></td>
                                    <td><?= e($row['topic']) ?></td>
                                    <td>
                                        <span class="badge <?= badgeClassByDifficulty((string)$row['difficulty_level']) ?>">
                                            <?= e($row['difficulty_level']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge badge-primary"><?= e(questionTypeLabel((string)$row['question_type'])) ?></span>
                                    </td>
                                    <td><?= (int)$row['marks'] ?></td>
                                    <td>
                                        <span class="badge <?= badgeClassBySource((string)$row['source_type']) ?>">
                                            <?= e($row['source_type']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge <?= badgeClassByStatus((string)$row['status']) ?>">
                                            <?= e($row['status']) ?>
                                        </span>
                                    </td>
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
                                    <td style="min-width:280px;">
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
                                    <td>
                                        <a href="question_bank.php?edit=<?= (int)$row['id'] ?>" class="btn btn-light btn-sm">
                                            Edit
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($selectedQuestion): ?>
            <div class="form-card mt-4">
                <div class="section-title">
                    <h2 class="mb-0">Edit Question - #<?= (int)$selectedQuestion['id'] ?></h2>
                </div>

                <p class="section-subtitle">
                    Update this question directly from the Question Bank.
                </p>

                <div class="page-note mb-4">
                    If this question is already linked to one or more exams, editing it here will affect those linked uses too.
                </div>

                <form method="POST" class="question-editor-card">
                    <input type="hidden" name="action" value="update_question">
                    <input type="hidden" name="edit_question_id" value="<?= (int)$selectedQuestion['id'] ?>">

                    <div class="info-grid mb-4">
                        <div class="info-box">
                            <h4>Question ID</h4>
                            <p>#<?= (int)$selectedQuestion['id'] ?></p>
                        </div>

                        <div class="info-box">
                            <h4>Source</h4>
                            <p><?= e($selectedQuestion['source_type']) ?></p>
                        </div>

                        <div class="info-box">
                            <h4>Created By</h4>
                            <p><?= e($selectedQuestion['created_by_name'] ?: 'Unknown') ?></p>
                        </div>

                        <div class="info-box">
                            <h4>Created At</h4>
                            <p><?= e($selectedQuestion['created_at']) ?></p>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Programming Language</label>
                            <select name="programming_language" required>
                                <option value="C" <?= selectedValue((string)$selectedQuestion['programming_language'], 'C') ?>>C</option>
                                <option value="C++" <?= selectedValue((string)$selectedQuestion['programming_language'], 'C++') ?>>C++</option>
                                <option value="Java" <?= selectedValue((string)$selectedQuestion['programming_language'], 'Java') ?>>Java</option>
                                <option value="Python" <?= selectedValue((string)$selectedQuestion['programming_language'], 'Python') ?>>Python</option>
                                <option value="JavaScript" <?= selectedValue((string)$selectedQuestion['programming_language'], 'JavaScript') ?>>JavaScript</option>
                                <option value="PHP" <?= selectedValue((string)$selectedQuestion['programming_language'], 'PHP') ?>>PHP</option>
                                <option value="SQL" <?= selectedValue((string)$selectedQuestion['programming_language'], 'SQL') ?>>SQL</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Topic</label>
                            <input type="text" name="topic" value="<?= e($selectedQuestion['topic']) ?>" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Question Type</label>
                            <select name="question_type" class="qb-question-type-selector">
                                <option value="mcq" <?= selectedValue((string)$selectedQuestion['question_type'], 'mcq') ?>>MCQ</option>
                                <option value="true_false" <?= selectedValue((string)$selectedQuestion['question_type'], 'true_false') ?>>True / False</option>
                                <option value="short_answer" <?= selectedValue((string)$selectedQuestion['question_type'], 'short_answer') ?>>Short Answer</option>
                                <option value="code_writing" <?= selectedValue((string)$selectedQuestion['question_type'], 'code_writing') ?>>Code Writing</option>
                                <option value="debugging" <?= selectedValue((string)$selectedQuestion['question_type'], 'debugging') ?>>Debugging</option>
                                <option value="output_prediction" <?= selectedValue((string)$selectedQuestion['question_type'], 'output_prediction') ?>>Output Prediction</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Difficulty</label>
                            <select name="difficulty_level">
                                <option value="Easy" <?= selectedValue((string)$selectedQuestion['difficulty_level'], 'Easy') ?>>Easy</option>
                                <option value="Medium" <?= selectedValue((string)$selectedQuestion['difficulty_level'], 'Medium') ?>>Medium</option>
                                <option value="Hard" <?= selectedValue((string)$selectedQuestion['difficulty_level'], 'Hard') ?>>Hard</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Marks</label>
                            <input type="number" min="1" max="100" name="marks" value="<?= (int)$selectedQuestion['marks'] ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Status</label>
                            <select name="status">
                                <option value="approved" <?= selectedValue((string)$selectedQuestion['status'], 'approved') ?>>approved</option>
                                <option value="pending" <?= selectedValue((string)$selectedQuestion['status'], 'pending') ?>>pending</option>
                                <option value="archived" <?= selectedValue((string)$selectedQuestion['status'], 'archived') ?>>archived</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Question Text</label>
                        <textarea name="question_text" required><?= e($selectedQuestion['question_text']) ?></textarea>
                    </div>

                    <div class="type-section qb-mcq-fields" style="<?= (string)$selectedQuestion['question_type'] === 'mcq' ? '' : 'display:none;' ?>">
                        <div class="type-section-title">MCQ Options</div>

                        <div class="choice-grid">
                            <div class="form-group">
                                <label class="form-label">Option A</label>
                                <input type="text" name="option_a" value="<?= e($selectedQuestion['option_a']) ?>">
                            </div>

                            <div class="form-group">
                                <label class="form-label">Option B</label>
                                <input type="text" name="option_b" value="<?= e($selectedQuestion['option_b']) ?>">
                            </div>

                            <div class="form-group">
                                <label class="form-label">Option C</label>
                                <input type="text" name="option_c" value="<?= e($selectedQuestion['option_c']) ?>">
                            </div>

                            <div class="form-group">
                                <label class="form-label">Option D</label>
                                <input type="text" name="option_d" value="<?= e($selectedQuestion['option_d']) ?>">
                            </div>
                        </div>

                        <div class="form-group mb-0">
                            <label class="form-label">Correct Answer</label>
                            <select name="correct_answer">
                                <option value="">-- Select Correct Answer --</option>
                                <option value="A" <?= selectedValue((string)$selectedQuestion['correct_answer'], 'A') ?>>A</option>
                                <option value="B" <?= selectedValue((string)$selectedQuestion['correct_answer'], 'B') ?>>B</option>
                                <option value="C" <?= selectedValue((string)$selectedQuestion['correct_answer'], 'C') ?>>C</option>
                                <option value="D" <?= selectedValue((string)$selectedQuestion['correct_answer'], 'D') ?>>D</option>
                            </select>
                        </div>
                    </div>

                    <div class="type-section qb-tf-fields" style="<?= (string)$selectedQuestion['question_type'] === 'true_false' ? '' : 'display:none;' ?>">
                        <div class="type-section-title">True / False Answer</div>

                        <div class="form-group mb-0">
                            <label class="form-label">Correct Answer</label>
                            <select name="correct_answer">
                                <option value="">-- Select Correct Answer --</option>
                                <option value="True" <?= selectedValue((string)$selectedQuestion['correct_answer'], 'True') ?>>True</option>
                                <option value="False" <?= selectedValue((string)$selectedQuestion['correct_answer'], 'False') ?>>False</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group mt-3 mb-0">
                        <label class="form-label">Model Answer</label>
                        <textarea name="model_answer"><?= e($selectedQuestion['model_answer']) ?></textarea>
                    </div>

                    <div class="toolbar mt-4">
                        <div class="toolbar-left">
                            <button type="submit" class="btn btn-primary">Save Question Changes</button>
                            <a href="question_bank.php" class="btn btn-light">Close Editor</a>
                        </div>
                    </div>
                </form>
            </div>
        <?php endif; ?>

    </div>
</main>

<script>
(function () {
    function updateQuestionBankTypeSections(scope) {
        const root = scope || document;

        root.querySelectorAll('.question-editor-card').forEach(function (item) {
            const typeSelect = item.querySelector('.qb-question-type-selector');
            const mcqFields  = item.querySelector('.qb-mcq-fields');
            const tfFields   = item.querySelector('.qb-tf-fields');

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
        if (e.target.classList.contains('qb-question-type-selector')) {
            updateQuestionBankTypeSections(document);
        }
    });

    document.addEventListener('DOMContentLoaded', function () {
        updateQuestionBankTypeSections(document);
    });

    updateQuestionBankTypeSections(document);
})();
</script>

<?php require_once __DIR__ . '/include/footer.php'; ?>