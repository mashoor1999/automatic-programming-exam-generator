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

function blankQuestionRow(): array
{
    return [
        'question_type'    => 'short_answer',
        'difficulty_level' => 'Medium',
        'marks'            => 5,
        'question_text'    => '',
        'option_a'         => '',
        'option_b'         => '',
        'option_c'         => '',
        'option_d'         => '',
        'correct_answer'   => '',
        'model_answer'     => '',
    ];
}

function normalizePostedQuestions(array $postedQuestions): array
{
    $rows = [];

    foreach ($postedQuestions as $row) {
        if (!is_array($row)) {
            continue;
        }

        $rows[] = [
            'question_type'    => trim((string)($row['question_type'] ?? 'short_answer')),
            'difficulty_level' => trim((string)($row['difficulty_level'] ?? 'Medium')),
            'marks'            => (int)($row['marks'] ?? 5),
            'question_text'    => trim((string)($row['question_text'] ?? '')),
            'option_a'         => trim((string)($row['option_a'] ?? '')),
            'option_b'         => trim((string)($row['option_b'] ?? '')),
            'option_c'         => trim((string)($row['option_c'] ?? '')),
            'option_d'         => trim((string)($row['option_d'] ?? '')),
            'correct_answer'   => trim((string)($row['correct_answer'] ?? '')),
            'model_answer'     => trim((string)($row['model_answer'] ?? '')),
        ];
    }

    if (empty($rows)) {
        $rows[] = blankQuestionRow();
    }

    return array_values($rows);
}

function isQuestionRowBlank(array $row): bool
{
    $combined = trim(
        (string)($row['question_text'] ?? '') .
        (string)($row['option_a'] ?? '') .
        (string)($row['option_b'] ?? '') .
        (string)($row['option_c'] ?? '') .
        (string)($row['option_d'] ?? '') .
        (string)($row['correct_answer'] ?? '') .
        (string)($row['model_answer'] ?? '')
    );

    return $combined === '';
}

function buildModelAnswer(array $row): string
{
    $type = (string)($row['question_type'] ?? 'short_answer');
    $modelAnswer = trim((string)($row['model_answer'] ?? ''));

    if ($modelAnswer !== '') {
        return $modelAnswer;
    }

    if ($type === 'true_false') {
        $correct = trim((string)($row['correct_answer'] ?? ''));
        return $correct !== '' ? 'Correct answer: ' . $correct : '';
    }

    if ($type === 'mcq') {
        $correct = trim((string)($row['correct_answer'] ?? ''));
        $map = [
            'A' => trim((string)($row['option_a'] ?? '')),
            'B' => trim((string)($row['option_b'] ?? '')),
            'C' => trim((string)($row['option_c'] ?? '')),
            'D' => trim((string)($row['option_d'] ?? '')),
        ];

        if ($correct !== '' && isset($map[$correct])) {
            return 'Correct answer: ' . $correct . ') ' . $map[$correct];
        }
    }

    return '';
}

function selectedValue(string $current, string $expected): string
{
    return $current === $expected ? 'selected' : '';
}

$currentUserId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 1);

$errors = [];
$success = '';
$createdExamId = null;
$linkedQuestionCount = 0;

$formData = [
    'exam_title'           => '',
    'topic'                => '',
    'programming_language' => '',
    'difficulty_level'     => 'Medium',
    'question_count'       => 5,
    'duration_minutes'     => 60,
    'notes'                => '',
];

$manualQuestions = [blankQuestionRow()];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData['exam_title']           = trim($_POST['exam_title'] ?? '');
    $formData['topic']                = trim($_POST['topic'] ?? '');
    $formData['programming_language'] = trim($_POST['programming_language'] ?? '');
    $formData['difficulty_level']     = trim($_POST['difficulty_level'] ?? 'Medium');
    $formData['question_count']       = (int)($_POST['question_count'] ?? 0);
    $formData['duration_minutes']     = (int)($_POST['duration_minutes'] ?? 0);
    $formData['notes']                = trim($_POST['notes'] ?? '');

    $manualQuestions = normalizePostedQuestions($_POST['questions'] ?? []);

    if ($formData['exam_title'] === '') {
        $errors[] = 'Exam title is required.';
    }

    if ($formData['topic'] === '') {
        $errors[] = 'Topic is required.';
    }

    if ($formData['programming_language'] === '') {
        $errors[] = 'Programming language is required.';
    }

    $allowedExamDifficulties = ['Easy', 'Medium', 'Hard', 'Mixed'];
    if (!in_array($formData['difficulty_level'], $allowedExamDifficulties, true)) {
        $errors[] = 'Invalid exam difficulty level selected.';
    }

    if ($formData['question_count'] < 1 || $formData['question_count'] > 20) {
        $errors[] = 'Question count must be between 1 and 20.';
    }

    if ($formData['duration_minutes'] < 10 || $formData['duration_minutes'] > 300) {
        $errors[] = 'Duration must be between 10 and 300 minutes.';
    }

    $allowedTypes = ['mcq', 'true_false', 'short_answer', 'code_writing', 'debugging', 'output_prediction'];
    $allowedRowDifficulties = ['Easy', 'Medium', 'Hard'];

    $usableQuestions = [];

    foreach ($manualQuestions as $index => $row) {
        if (isQuestionRowBlank($row)) {
            continue;
        }

        $rowNumber = $index + 1;

        if (!in_array($row['question_type'], $allowedTypes, true)) {
            $errors[] = "Invalid question type in row #{$rowNumber}.";
        }

        if (!in_array($row['difficulty_level'], $allowedRowDifficulties, true)) {
            $errors[] = "Invalid question difficulty in row #{$rowNumber}.";
        }

        if (trim($row['question_text']) === '') {
            $errors[] = "Question text is required in row #{$rowNumber}.";
        }

        if ((int)$row['marks'] < 1 || (int)$row['marks'] > 100) {
            $errors[] = "Marks must be between 1 and 100 in row #{$rowNumber}.";
        }

        if ($row['question_type'] === 'mcq') {
            if (
                trim($row['option_a']) === '' ||
                trim($row['option_b']) === '' ||
                trim($row['option_c']) === '' ||
                trim($row['option_d']) === ''
            ) {
                $errors[] = "All MCQ options are required in row #{$rowNumber}.";
            }

            if (!in_array($row['correct_answer'], ['A', 'B', 'C', 'D'], true)) {
                $errors[] = "Correct answer for MCQ must be A, B, C, or D in row #{$rowNumber}.";
            }
        }

        if ($row['question_type'] === 'true_false') {
            if (!in_array($row['correct_answer'], ['True', 'False'], true)) {
                $errors[] = "Correct answer for True / False must be True or False in row #{$rowNumber}.";
            }
        }

        $usableQuestions[] = $row;
    }

    if (count($usableQuestions) > $formData['question_count']) {
        $errors[] = 'The number of entered manual questions is greater than the planned exam question count.';
    }

    if (empty($errors)) {
        try {
            $pdoConnection->beginTransaction();

            $stmtExam = $pdoConnection->prepare("
                INSERT INTO exams (
                    user_id,
                    exam_title,
                    topic,
                    programming_language,
                    difficulty_level,
                    question_count,
                    duration_minutes,
                    notes,
                    status,
                    created_at
                ) VALUES (
                    :user_id,
                    :exam_title,
                    :topic,
                    :programming_language,
                    :difficulty_level,
                    :question_count,
                    :duration_minutes,
                    :notes,
                    'draft',
                    NOW()
                )
            ");

            $stmtExam->execute([
                ':user_id'              => $currentUserId,
                ':exam_title'           => $formData['exam_title'],
                ':topic'                => $formData['topic'],
                ':programming_language' => $formData['programming_language'],
                ':difficulty_level'     => $formData['difficulty_level'],
                ':question_count'       => $formData['question_count'],
                ':duration_minutes'     => $formData['duration_minutes'],
                ':notes'                => $formData['notes'],
            ]);

            $createdExamId = (int)$pdoConnection->lastInsertId();

            $stmtQuestionBank = $pdoConnection->prepare("
                INSERT INTO question_bank (
                    programming_language,
                    topic,
                    difficulty_level,
                    question_type,
                    question_text,
                    option_a,
                    option_b,
                    option_c,
                    option_d,
                    correct_answer,
                    model_answer,
                    marks,
                    source_type,
                    status,
                    generation_prompt,
                    llm_model,
                    created_by,
                    created_at
                ) VALUES (
                    :programming_language,
                    :topic,
                    :difficulty_level,
                    :question_type,
                    :question_text,
                    :option_a,
                    :option_b,
                    :option_c,
                    :option_d,
                    :correct_answer,
                    :model_answer,
                    :marks,
                    'manual',
                    'approved',
                    NULL,
                    NULL,
                    :created_by,
                    NOW()
                )
            ");

            $stmtLink = $pdoConnection->prepare("
                INSERT INTO exam_question_bank (
                    exam_id,
                    question_bank_id,
                    question_order,
                    marks_override,
                    created_at
                ) VALUES (
                    :exam_id,
                    :question_bank_id,
                    :question_order,
                    NULL,
                    NOW()
                )
            ");

            $order = 1;

            foreach ($usableQuestions as $row) {
                $questionType = $row['question_type'];

                $optionA = null;
                $optionB = null;
                $optionC = null;
                $optionD = null;
                $correctAnswer = null;

                if ($questionType === 'mcq') {
                    $optionA = $row['option_a'];
                    $optionB = $row['option_b'];
                    $optionC = $row['option_c'];
                    $optionD = $row['option_d'];
                    $correctAnswer = $row['correct_answer'];
                } elseif ($questionType === 'true_false') {
                    $optionA = 'True';
                    $optionB = 'False';
                    $optionC = null;
                    $optionD = null;
                    $correctAnswer = $row['correct_answer'];
                }

                $stmtQuestionBank->execute([
                    ':programming_language' => $formData['programming_language'],
                    ':topic'                => $formData['topic'],
                    ':difficulty_level'     => $row['difficulty_level'],
                    ':question_type'        => $questionType,
                    ':question_text'        => $row['question_text'],
                    ':option_a'             => $optionA,
                    ':option_b'             => $optionB,
                    ':option_c'             => $optionC,
                    ':option_d'             => $optionD,
                    ':correct_answer'       => $correctAnswer,
                    ':model_answer'         => buildModelAnswer($row),
                    ':marks'                => (int)$row['marks'],
                    ':created_by'           => $currentUserId,
                ]);

                $questionBankId = (int)$pdoConnection->lastInsertId();

                $stmtLink->execute([
                    ':exam_id'          => $createdExamId,
                    ':question_bank_id' => $questionBankId,
                    ':question_order'   => $order,
                ]);

                $order++;
            }

            $linkedQuestionCount = count($usableQuestions);

            $newStatus = 'draft';
            if ($linkedQuestionCount > 0 && $linkedQuestionCount < $formData['question_count']) {
                $newStatus = 'ready';
            } elseif ($linkedQuestionCount >= $formData['question_count']) {
                $newStatus = 'generated';
            }

            $stmtUpdateExam = $pdoConnection->prepare("
                UPDATE exams
                SET status = :status
                WHERE id = :exam_id
                LIMIT 1
            ");

            $stmtUpdateExam->execute([
                ':status'  => $newStatus,
                ':exam_id' => $createdExamId,
            ]);

            $pdoConnection->commit();

            $success = "Exam created successfully. Exam ID: #{$createdExamId}. Linked manual questions: {$linkedQuestionCount}.";
            $formData = [
                'exam_title'           => '',
                'topic'                => '',
                'programming_language' => '',
                'difficulty_level'     => 'Medium',
                'question_count'       => 5,
                'duration_minutes'     => 60,
                'notes'                => '',
            ];
            $manualQuestions = [blankQuestionRow()];
        } catch (Throwable $e) {
            if ($pdoConnection->inTransaction()) {
                $pdoConnection->rollBack();
            }
            $errors[] = 'Save failed: ' . $e->getMessage();
        }
    }
}

$pageTitle   = 'Create Exam';
$currentPage = 'generate_exam';
$basePath    = '';

$pageStyles = <<<CSS
.page-actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}

.manual-question-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 20px;
    padding: 20px;
    box-shadow: var(--shadow-sm);
    margin-bottom: 18px;
}

.manual-question-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 16px;
}

.manual-question-index {
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

.manual-question-title-wrap {
    display: flex;
    align-items: center;
    gap: 12px;
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

.simple-muted {
    color: var(--text-muted);
    font-size: 13px;
}

@media (max-width: 992px) {
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
            <div class="alert alert-success mt-4">
                <?= e($success) ?>
                <?php if ($createdExamId): ?>
                    <div style="margin-top:10px;">
                        <a href="view_exams.php?view=<?= (int)$createdExamId ?>" class="btn btn-light btn-sm">Open Exam</a>
                        <a href="question_bank.php" class="btn btn-outline-primary btn-sm">Open Question Bank</a>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form method="POST" id="createExamForm">
            <div class="form-card mt-4">
                <div class="section-title">
                    <h2 class="mb-0">Exam Information</h2>
                </div>

                <p class="section-subtitle">
                    Enter the main exam details first, then add the questions manually below.
                </p>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Exam Title <span class="required">*</span></label>
                        <input
                            type="text"
                            name="exam_title"
                            value="<?= e($formData['exam_title']) ?>"
                            placeholder="e.g. Java OOP Midterm"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label class="form-label">Topic <span class="required">*</span></label>
                        <input
                            type="text"
                            name="topic"
                            value="<?= e($formData['topic']) ?>"
                            placeholder="e.g. Inheritance, Polymorphism, Interface"
                            required
                        >
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Programming Language <span class="required">*</span></label>
                        <select name="programming_language" required>
                            <option value="">-- Select Language --</option>
                            <option value="C" <?= selectedValue($formData['programming_language'], 'C') ?>>C</option>
                            <option value="C++" <?= selectedValue($formData['programming_language'], 'C++') ?>>C++</option>
                            <option value="Java" <?= selectedValue($formData['programming_language'], 'Java') ?>>Java</option>
                            <option value="Python" <?= selectedValue($formData['programming_language'], 'Python') ?>>Python</option>
                            <option value="JavaScript" <?= selectedValue($formData['programming_language'], 'JavaScript') ?>>JavaScript</option>
                            <option value="PHP" <?= selectedValue($formData['programming_language'], 'PHP') ?>>PHP</option>
                            <option value="SQL" <?= selectedValue($formData['programming_language'], 'SQL') ?>>SQL</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Exam Difficulty <span class="required">*</span></label>
                        <select name="difficulty_level" required>
                            <option value="Easy" <?= selectedValue($formData['difficulty_level'], 'Easy') ?>>Easy</option>
                            <option value="Medium" <?= selectedValue($formData['difficulty_level'], 'Medium') ?>>Medium</option>
                            <option value="Hard" <?= selectedValue($formData['difficulty_level'], 'Hard') ?>>Hard</option>
                            <option value="Mixed" <?= selectedValue($formData['difficulty_level'], 'Mixed') ?>>Mixed</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Question Count <span class="required">*</span></label>
                        <input
                            type="number"
                            name="question_count"
                            min="1"
                            max="20"
                            value="<?= e($formData['question_count']) ?>"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label class="form-label">Duration (Minutes) <span class="required">*</span></label>
                        <input
                            type="number"
                            name="duration_minutes"
                            min="10"
                            max="300"
                            value="<?= e($formData['duration_minutes']) ?>"
                            required
                        >
                    </div>
                </div>

                <div class="form-group mb-0">
                    <label class="form-label">Notes</label>
                    <textarea
                        name="notes"
                        placeholder="Optional notes for this exam."
                    ><?= e($formData['notes']) ?></textarea>
                </div>
            </div>

            <div class="form-card mt-4">
                <div class="section-title">
                    <h2 class="mb-0">Manual Questions</h2>
                </div>

                <p class="section-subtitle">
                    Add the questions manually one by one. Supported types: MCQ, True / False, Short Answer, Code Writing, Debugging, Output Prediction.
                </p>

                <div class="page-note mb-4">
                    This page saves manual questions directly into the Question Bank with source type <strong>manual</strong>, then links them to the created exam.
                </div>

                <div id="questions-wrapper">
                    <?php foreach ($manualQuestions as $index => $row): ?>
                        <div class="manual-question-card question-item">
                            <div class="manual-question-header">
                                <div class="manual-question-title-wrap">
                                    <span class="manual-question-index question-number"><?= $index + 1 ?></span>
                                    <div>
                                        <h3 class="mb-0">Question #<?= $index + 1 ?></h3>
                                        <p class="simple-muted mb-0">Manual exam question</p>
                                    </div>
                                </div>

                                <button type="button" class="btn btn-outline-danger btn-sm remove-question-btn">
                                    Remove
                                </button>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Question Type</label>
                                    <select name="questions[<?= $index ?>][question_type]" class="question-type-selector">
                                        <option value="mcq" <?= selectedValue($row['question_type'], 'mcq') ?>>MCQ / Circle the correct answer</option>
                                        <option value="true_false" <?= selectedValue($row['question_type'], 'true_false') ?>>True / False</option>
                                        <option value="short_answer" <?= selectedValue($row['question_type'], 'short_answer') ?>>Short Answer</option>
                                        <option value="code_writing" <?= selectedValue($row['question_type'], 'code_writing') ?>>Code Writing</option>
                                        <option value="debugging" <?= selectedValue($row['question_type'], 'debugging') ?>>Debugging</option>
                                        <option value="output_prediction" <?= selectedValue($row['question_type'], 'output_prediction') ?>>Output Prediction</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Question Difficulty</label>
                                    <select name="questions[<?= $index ?>][difficulty_level]">
                                        <option value="Easy" <?= selectedValue($row['difficulty_level'], 'Easy') ?>>Easy</option>
                                        <option value="Medium" <?= selectedValue($row['difficulty_level'], 'Medium') ?>>Medium</option>
                                        <option value="Hard" <?= selectedValue($row['difficulty_level'], 'Hard') ?>>Hard</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Marks</label>
                                    <input
                                        type="number"
                                        min="1"
                                        max="100"
                                        name="questions[<?= $index ?>][marks]"
                                        value="<?= e($row['marks']) ?>"
                                    >
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Question Text <span class="required">*</span></label>
                                <textarea
                                    name="questions[<?= $index ?>][question_text]"
                                    placeholder="Write the full question here..."
                                ><?= e($row['question_text']) ?></textarea>
                            </div>

                            <div class="type-section mcq-fields" style="<?= $row['question_type'] === 'mcq' ? '' : 'display:none;' ?>">
                                <div class="type-section-title">MCQ Options</div>

                                <div class="choice-grid">
                                    <div class="form-group">
                                        <label class="form-label">Option A</label>
                                        <input type="text" name="questions[<?= $index ?>][option_a]" value="<?= e($row['option_a']) ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Option B</label>
                                        <input type="text" name="questions[<?= $index ?>][option_b]" value="<?= e($row['option_b']) ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Option C</label>
                                        <input type="text" name="questions[<?= $index ?>][option_c]" value="<?= e($row['option_c']) ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Option D</label>
                                        <input type="text" name="questions[<?= $index ?>][option_d]" value="<?= e($row['option_d']) ?>">
                                    </div>
                                </div>

                                <div class="form-group mb-0">
                                    <label class="form-label">Correct Answer</label>
                                    <select name="questions[<?= $index ?>][correct_answer]">
                                        <option value="">-- Select Correct Answer --</option>
                                        <option value="A" <?= selectedValue($row['correct_answer'], 'A') ?>>A</option>
                                        <option value="B" <?= selectedValue($row['correct_answer'], 'B') ?>>B</option>
                                        <option value="C" <?= selectedValue($row['correct_answer'], 'C') ?>>C</option>
                                        <option value="D" <?= selectedValue($row['correct_answer'], 'D') ?>>D</option>
                                    </select>
                                </div>
                            </div>

                            <div class="type-section tf-fields" style="<?= $row['question_type'] === 'true_false' ? '' : 'display:none;' ?>">
                                <div class="type-section-title">True / False Answer</div>

                                <div class="form-group mb-0">
                                    <label class="form-label">Correct Answer</label>
                                    <select name="questions[<?= $index ?>][correct_answer]">
                                        <option value="">-- Select Correct Answer --</option>
                                        <option value="True" <?= selectedValue($row['correct_answer'], 'True') ?>>True</option>
                                        <option value="False" <?= selectedValue($row['correct_answer'], 'False') ?>>False</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group mt-3 mb-0">
                                <label class="form-label">Model Answer</label>
                                <textarea
                                    name="questions[<?= $index ?>][model_answer]"
                                    placeholder="Optional. If left empty for MCQ or True / False, the system will build a basic model answer automatically."
                                ><?= e($row['model_answer']) ?></textarea>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="toolbar mt-4">
                    <div class="toolbar-left">
                        <button type="button" id="add-question-btn" class="btn btn-light">+ Add Question</button>
                        <button type="submit" class="btn btn-primary">Save Exam</button>
                        <button type="reset" class="btn btn-light">Reset</button>
                    </div>
                </div>
            </div>
        </form>

    </div>
</main>

<script>
(function () {
    const wrapper = document.getElementById('questions-wrapper');
    const addBtn = document.getElementById('add-question-btn');

    function setSectionState(section, enabled) {
        if (!section) return;

        section.style.display = enabled ? 'block' : 'none';

        section.querySelectorAll('input, select, textarea').forEach((field) => {
            field.disabled = !enabled;
        });
    }

    function updateQuestionTypeSections(scope) {
        const root = scope || document;

        root.querySelectorAll('.question-item').forEach(function (item) {
            const typeSelect = item.querySelector('.question-type-selector');
            const mcqFields  = item.querySelector('.mcq-fields');
            const tfFields   = item.querySelector('.tf-fields');

            if (!typeSelect) return;

            const type = typeSelect.value;

            setSectionState(mcqFields, type === 'mcq');
            setSectionState(tfFields, type === 'true_false');
        });
    }

    function refreshQuestionIndices() {
        const items = wrapper.querySelectorAll('.question-item');

        items.forEach((item, index) => {
            const number = item.querySelector('.question-number');
            const title  = item.querySelector('h3');

            if (number) number.textContent = index + 1;
            if (title) title.textContent = 'Question #' + (index + 1);

            item.querySelectorAll('textarea, input, select').forEach((field) => {
                const name = field.getAttribute('name');
                if (!name) return;

                field.setAttribute(
                    'name',
                    name.replace(/questions\[\d+\]/, 'questions[' + index + ']')
                );
            });
        });
    }

    function buildQuestionCard(index) {
        const div = document.createElement('div');
        div.className = 'manual-question-card question-item';

        div.innerHTML = `
            <div class="manual-question-header">
                <div class="manual-question-title-wrap">
                    <span class="manual-question-index question-number">${index + 1}</span>
                    <div>
                        <h3 class="mb-0">Question #${index + 1}</h3>
                        <p class="simple-muted mb-0">Manual exam question</p>
                    </div>
                </div>

                <button type="button" class="btn btn-outline-danger btn-sm remove-question-btn">
                    Remove
                </button>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Question Type</label>
                    <select name="questions[${index}][question_type]" class="question-type-selector">
                        <option value="mcq">MCQ / Circle the correct answer</option>
                        <option value="true_false">True / False</option>
                        <option value="short_answer" selected>Short Answer</option>
                        <option value="code_writing">Code Writing</option>
                        <option value="debugging">Debugging</option>
                        <option value="output_prediction">Output Prediction</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Question Difficulty</label>
                    <select name="questions[${index}][difficulty_level]">
                        <option value="Easy">Easy</option>
                        <option value="Medium" selected>Medium</option>
                        <option value="Hard">Hard</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Marks</label>
                    <input type="number" min="1" max="100" name="questions[${index}][marks]" value="5">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Question Text <span class="required">*</span></label>
                <textarea name="questions[${index}][question_text]" placeholder="Write the full question here..."></textarea>
            </div>

            <div class="type-section mcq-fields" style="display:none;">
                <div class="type-section-title">MCQ Options</div>

                <div class="choice-grid">
                    <div class="form-group">
                        <label class="form-label">Option A</label>
                        <input type="text" name="questions[${index}][option_a]">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Option B</label>
                        <input type="text" name="questions[${index}][option_b]">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Option C</label>
                        <input type="text" name="questions[${index}][option_c]">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Option D</label>
                        <input type="text" name="questions[${index}][option_d]">
                    </div>
                </div>

                <div class="form-group mb-0">
                    <label class="form-label">Correct Answer</label>
                    <select name="questions[${index}][correct_answer]">
                        <option value="">-- Select Correct Answer --</option>
                        <option value="A">A</option>
                        <option value="B">B</option>
                        <option value="C">C</option>
                        <option value="D">D</option>
                    </select>
                </div>
            </div>

            <div class="type-section tf-fields" style="display:none;">
                <div class="type-section-title">True / False Answer</div>

                <div class="form-group mb-0">
                    <label class="form-label">Correct Answer</label>
                    <select name="questions[${index}][correct_answer]">
                        <option value="">-- Select Correct Answer --</option>
                        <option value="True">True</option>
                        <option value="False">False</option>
                    </select>
                </div>
            </div>

            <div class="form-group mt-3 mb-0">
                <label class="form-label">Model Answer</label>
                <textarea name="questions[${index}][model_answer]" placeholder="Optional. If left empty for MCQ or True / False, the system will build a basic model answer automatically."></textarea>
            </div>
        `;

        return div;
    }

    if (addBtn && wrapper) {
        addBtn.addEventListener('click', function () {
            const index = wrapper.querySelectorAll('.question-item').length;
            wrapper.appendChild(buildQuestionCard(index));
            refreshQuestionIndices();
            updateQuestionTypeSections(wrapper);
        });

        wrapper.addEventListener('change', function (e) {
            if (e.target.classList.contains('question-type-selector')) {
                updateQuestionTypeSections(wrapper);
            }
        });

        wrapper.addEventListener('click', function (e) {
            const btn = e.target.closest('.remove-question-btn');
            if (!btn) return;

            const item = btn.closest('.question-item');
            if (!item) return;

            const total = wrapper.querySelectorAll('.question-item').length;
            if (total <= 1) {
                alert('At least one question card must remain visible.');
                return;
            }

            item.remove();
            refreshQuestionIndices();
            updateQuestionTypeSections(wrapper);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        updateQuestionTypeSections(document);
    });

    updateQuestionTypeSections(document);
})();
</script>

<?php require_once __DIR__ . '/include/footer.php'; ?>