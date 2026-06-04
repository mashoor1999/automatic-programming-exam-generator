<?php
declare(strict_types=1);

require_once __DIR__ . '/session_check.php';
require_once __DIR__ . '/Data/db.php';
require_once __DIR__ . '/Data/llm_client.php';

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

function normalizePostedGeneratedRows(): array
{
    $questionTexts      = $_POST['question_text'] ?? [];
    $questionTypes      = $_POST['question_type'] ?? [];
    $difficultyRows     = $_POST['row_difficulty_level'] ?? [];
    $modelAnswers       = $_POST['model_answer'] ?? [];
    $marksRows          = $_POST['marks'] ?? [];

    $optionAs           = $_POST['option_a'] ?? [];
    $optionBs           = $_POST['option_b'] ?? [];
    $optionCs           = $_POST['option_c'] ?? [];
    $optionDs           = $_POST['option_d'] ?? [];

    $mcqCorrectAnswers  = $_POST['mcq_correct_answer'] ?? [];
    $tfCorrectAnswers   = $_POST['tf_correct_answer'] ?? [];

    $rows = [];

    foreach ($questionTexts as $i => $questionText) {
        $questionType = trim((string)($questionTypes[$i] ?? 'short_answer'));

        $correctAnswer = '';
        if ($questionType === 'mcq') {
            $correctAnswer = trim((string)($mcqCorrectAnswers[$i] ?? ''));
        } elseif ($questionType === 'true_false') {
            $correctAnswer = trim((string)($tfCorrectAnswers[$i] ?? ''));
        }

        $rows[$i] = [
            'question_text'    => trim((string)$questionText),
            'question_type'    => $questionType,
            'difficulty_level' => trim((string)($difficultyRows[$i] ?? 'Medium')),
            'model_answer'     => trim((string)($modelAnswers[$i] ?? '')),
            'marks'            => (int)($marksRows[$i] ?? 5),
            'option_a'         => trim((string)($optionAs[$i] ?? '')),
            'option_b'         => trim((string)($optionBs[$i] ?? '')),
            'option_c'         => trim((string)($optionCs[$i] ?? '')),
            'option_d'         => trim((string)($optionDs[$i] ?? '')),
            'correct_answer'   => $correctAnswer,
        ];
    }

    return $rows;
}

function finalizeQuestionRowForSave(array $row): array
{
    $type = (string)($row['question_type'] ?? 'short_answer');

    if ($type === 'true_false') {
        $row['option_a'] = 'True';
        $row['option_b'] = 'False';
        $row['option_c'] = '';
        $row['option_d'] = '';
    }

    if (in_array($type, ['short_answer', 'code_writing', 'debugging', 'output_prediction'], true)) {
        $row['option_a'] = '';
        $row['option_b'] = '';
        $row['option_c'] = '';
        $row['option_d'] = '';
        $row['correct_answer'] = '';
    }

    return $row;
}

$currentUserId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 1);

$errors        = [];
$success       = '';
$generatedRows = [];
$debugJson     = '';
$usedModel     = '';

$formData = [
    'programming_language' => 'Python',
    'topic'                => '',
    'difficulty_level'     => 'Medium',
    'question_count'       => 5,
    'notes'                => '',
    'prompt_text'          => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? 'generate_ai'));

    if ($action === 'generate_ai') {
        $formData['programming_language'] = trim((string)($_POST['programming_language'] ?? 'Python'));
        $formData['topic']                = trim((string)($_POST['topic'] ?? ''));
        $formData['difficulty_level']     = trim((string)($_POST['difficulty_level'] ?? 'Medium'));
        $formData['question_count']       = (int)($_POST['question_count'] ?? 5);
        $formData['notes']                = trim((string)($_POST['notes'] ?? ''));
        $formData['prompt_text']          = trim((string)($_POST['prompt_text'] ?? ''));

        $allowedDifficulties = ['Easy', 'Medium', 'Hard', 'Mixed'];

        if (!in_array($formData['difficulty_level'], $allowedDifficulties, true)) {
            $errors[] = 'Invalid difficulty level selected.';
        }

        if ($formData['topic'] === '') {
            $errors[] = 'Topic is required.';
        }

        if ($formData['programming_language'] === '') {
            $errors[] = 'Programming language is required.';
        }

        if ($formData['question_count'] < 1 || $formData['question_count'] > 20) {
            $errors[] = 'Question count must be between 1 and 20.';
        }

        if (empty($errors)) {
            try {
                $result = llm_generate_questions([
                    'programming_language' => $formData['programming_language'],
                    'topic'                => $formData['topic'],
                    'difficulty_level'     => $formData['difficulty_level'],
                    'question_count'       => $formData['question_count'],
                    'notes'                => $formData['notes'],
                ], $formData['prompt_text']);

                $generatedRows = $result['questions'] ?? [];
                $debugJson     = (string)($result['json_text'] ?? '');
                $usedModel     = (string)($result['model'] ?? '');

                if (empty($generatedRows)) {
                    $errors[] = 'The AI returned no usable questions.';
                }
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
    }

    if ($action === 'save_to_bank') {
        $formData['programming_language'] = trim((string)($_POST['programming_language'] ?? ''));
        $formData['topic']                = trim((string)($_POST['topic'] ?? ''));
        $formData['difficulty_level']     = trim((string)($_POST['generation_difficulty'] ?? 'Medium'));
        $formData['question_count']       = (int)($_POST['question_count'] ?? 0);
        $formData['notes']                = trim((string)($_POST['notes'] ?? ''));
        $formData['prompt_text']          = trim((string)($_POST['prompt_text'] ?? ''));

        $debugJson = trim((string)($_POST['debug_json'] ?? ''));
        $usedModel = trim((string)($_POST['llm_model'] ?? ''));

        $approvedIndices = array_map('intval', $_POST['approved_indices'] ?? []);
        $generatedRows   = normalizePostedGeneratedRows();

        if ($formData['programming_language'] === '' || $formData['topic'] === '') {
            $errors[] = 'Programming language and topic are required.';
        }

        if (empty($approvedIndices)) {
            $errors[] = 'Please select at least one question to approve and save.';
        }

        $allowedTypes        = ['mcq', 'true_false', 'short_answer', 'code_writing', 'debugging', 'output_prediction'];
        $allowedDifficulties = ['Easy', 'Medium', 'Hard'];

        foreach ($approvedIndices as $index) {
            if (!isset($generatedRows[$index])) {
                continue;
            }

            $row = $generatedRows[$index];
            $rowNumber = $index + 1;

            if (trim($row['question_text']) === '') {
                $errors[] = "Question text is required in row #{$rowNumber}.";
            }

            if (!in_array($row['question_type'], $allowedTypes, true)) {
                $errors[] = "Invalid question type in row #{$rowNumber}.";
            }

            if (!in_array($row['difficulty_level'], $allowedDifficulties, true)) {
                $errors[] = "Invalid difficulty level in row #{$rowNumber}.";
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
        }

        if (empty($errors)) {
            try {
                $pdoConnection->beginTransaction();

                $sql = "
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
                        'ai',
                        'approved',
                        :generation_prompt,
                        :llm_model,
                        :created_by,
                        NOW()
                    )
                ";

                $stmt = $pdoConnection->prepare($sql);
                $savedCount = 0;

                foreach ($approvedIndices as $index) {
                    if (!isset($generatedRows[$index])) {
                        continue;
                    }

                    $row = finalizeQuestionRowForSave($generatedRows[$index]);

                    $stmt->execute([
                        ':programming_language' => $formData['programming_language'],
                        ':topic'                => $formData['topic'],
                        ':difficulty_level'     => $row['difficulty_level'],
                        ':question_type'        => $row['question_type'],
                        ':question_text'        => $row['question_text'],
                        ':option_a'             => $row['option_a'] !== '' ? $row['option_a'] : null,
                        ':option_b'             => $row['option_b'] !== '' ? $row['option_b'] : null,
                        ':option_c'             => $row['option_c'] !== '' ? $row['option_c'] : null,
                        ':option_d'             => $row['option_d'] !== '' ? $row['option_d'] : null,
                        ':correct_answer'       => $row['correct_answer'] !== '' ? $row['correct_answer'] : null,
                        ':model_answer'         => $row['model_answer'],
                        ':marks'                => (int)$row['marks'],
                        ':generation_prompt'    => $formData['prompt_text'] !== '' ? $formData['prompt_text'] : $formData['notes'],
                        ':llm_model'            => $usedModel !== '' ? $usedModel : null,
                        ':created_by'           => $currentUserId,
                    ]);

                    $savedCount++;
                }

                $pdoConnection->commit();

                $success = "{$savedCount} approved question(s) saved to Question Bank successfully.";
                $generatedRows = [];
                $debugJson = '';
                $usedModel = '';
            } catch (Throwable $e) {
                if ($pdoConnection->inTransaction()) {
                    $pdoConnection->rollBack();
                }
                $errors[] = 'Save failed: ' . $e->getMessage();
            }
        }
    }
}

$pageTitle   = 'AI Generate';
$currentPage = 'ai_generate_questions';
$basePath    = '';

$pageStyles = <<<CSS
.page-actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}

.generated-question-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 20px;
    padding: 20px;
    box-shadow: var(--shadow-sm);
    margin-bottom: 18px;
}

.generated-question-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 16px;
}

.generated-question-title-wrap {
    display: flex;
    align-items: center;
    gap: 12px;
}

.generated-question-index {
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
    background: var(--info-light);
    color: #155e75;
    border: 1px solid rgba(8, 145, 178, 0.15);
    padding: 14px 16px;
    border-radius: 14px;
    font-weight: 600;
}

.debug-box {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 18px;
    padding: 18px;
    box-shadow: var(--shadow-sm);
}

.debug-pre {
    background: var(--bg-card-2);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 14px;
    overflow-x: auto;
    white-space: pre-wrap;
    word-break: break-word;
    color: var(--text-main);
    font-size: 13px;
    line-height: 1.7;
}

.simple-muted {
    color: var(--text-muted);
    font-size: 13px;
}

.ai-loading-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.55);
    backdrop-filter: blur(4px);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    padding: 20px;
}

.ai-loading-overlay.show {
    display: flex;
}

.ai-loading-box {
    width: min(460px, 100%);
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 24px;
    box-shadow: var(--shadow-lg);
    padding: 28px 24px;
    text-align: center;
}

.ai-loading-spinner {
    width: 66px;
    height: 66px;
    margin: 0 auto 18px;
    border-radius: 50%;
    border: 5px solid rgba(37, 99, 235, 0.14);
    border-top-color: var(--primary);
    animation: aiSpin 0.9s linear infinite;
}

@keyframes aiSpin {
    to {
        transform: rotate(360deg);
    }
}

.ai-loading-title {
    margin: 0 0 10px;
    font-size: 22px;
    font-weight: 800;
    color: var(--text-main);
}

.ai-loading-text {
    margin: 0;
    color: var(--text-soft);
    line-height: 1.8;
    font-size: 14px;
}

.ai-loading-dots::after {
    content: "";
    animation: aiDots 1.4s infinite;
}

@keyframes aiDots {
    0%   { content: ""; }
    25%  { content: "."; }
    50%  { content: ".."; }
    75%  { content: "..."; }
    100% { content: ""; }
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
                <ul style="margin-top:10px;padding-left:18px;list-style:disc;">
                    <?php foreach ($errors as $error): ?>
                        <li><?= e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="alert alert-success mt-4">
                <?= e($success) ?>
            </div>
        <?php endif; ?>

        <div class="form-card mt-4">
            <div class="section-title">
                <h2 class="mb-0">Generation Settings</h2>
            </div>

            <p class="section-subtitle">
                Define the language, topic, difficulty, and question count. Then review every generated row before saving.
            </p>

            <form method="POST" id="aiGenerateForm">
                <input type="hidden" name="action" value="generate_ai">

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Programming Language <span class="required">*</span></label>
                        <select name="programming_language" required>
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
                        <label class="form-label">Topic <span class="required">*</span></label>
                        <input
                            type="text"
                            name="topic"
                            value="<?= e($formData['topic']) ?>"
                            placeholder="e.g. OOP, Arrays, SQL Joins, Functions"
                            required
                        >
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Difficulty Mode <span class="required">*</span></label>
                        <select name="difficulty_level" required>
                            <option value="Easy" <?= selectedValue($formData['difficulty_level'], 'Easy') ?>>Easy</option>
                            <option value="Medium" <?= selectedValue($formData['difficulty_level'], 'Medium') ?>>Medium</option>
                            <option value="Hard" <?= selectedValue($formData['difficulty_level'], 'Hard') ?>>Hard</option>
                            <option value="Mixed" <?= selectedValue($formData['difficulty_level'], 'Mixed') ?>>Mixed</option>
                        </select>
                    </div>

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
                </div>

                <div class="form-group">
                    <label class="form-label">Notes</label>
                    <textarea
                        name="notes"
                        placeholder="Optional notes. Example: include practical debugging and one MCQ."
                    ><?= e($formData['notes']) ?></textarea>
                </div>

                <div class="form-group mb-0">
                    <label class="form-label">Custom Prompt (Optional)</label>
                    <textarea
                        name="prompt_text"
                        placeholder="Leave this empty to let the system build the prompt automatically."
                    ><?= e($formData['prompt_text']) ?></textarea>
                </div>

                <div class="toolbar mt-4">
                    <div class="toolbar-left">
                        <button type="submit" class="btn btn-primary" id="generateAiBtn">
                            <span class="btn-text">Generate by AI</span>
                            <span class="btn-loading-text" style="display:none;">Generating...</span>
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <?php if (!empty($generatedRows)): ?>
            <div class="form-card mt-4">
                <div class="section-title">
                    <h2 class="mb-0">Review Generated Questions</h2>
                </div>

                <p class="section-subtitle">
                    Edit everything you need, uncheck unwanted rows, then save the approved questions into the Question Bank.
                </p>

                <div class="page-note mb-4">
                    Questions saved from this page will be stored in <strong>question_bank</strong> with source type <strong>ai</strong>.
                </div>

                <form method="POST" id="aiSaveForm">
                    <input type="hidden" name="action" value="save_to_bank">
                    <input type="hidden" name="programming_language" value="<?= e($formData['programming_language']) ?>">
                    <input type="hidden" name="topic" value="<?= e($formData['topic']) ?>">
                    <input type="hidden" name="generation_difficulty" value="<?= e($formData['difficulty_level']) ?>">
                    <input type="hidden" name="question_count" value="<?= e($formData['question_count']) ?>">
                    <input type="hidden" name="notes" value="<?= e($formData['notes']) ?>">
                    <input type="hidden" name="prompt_text" value="<?= e($formData['prompt_text']) ?>">
                    <input type="hidden" name="debug_json" value="<?= e($debugJson) ?>">
                    <input type="hidden" name="llm_model" value="<?= e($usedModel) ?>">

                    <?php foreach ($generatedRows as $index => $row): ?>
                        <div class="generated-question-card generated-question-item">
                            <div class="generated-question-header">
                                <div class="generated-question-title-wrap">
                                    <span class="generated-question-index"><?= $index + 1 ?></span>
                                    <div>
                                        <h3 class="mb-0">Question #<?= $index + 1 ?></h3>
                                        <p class="simple-muted mb-0">Generated by AI</p>
                                    </div>
                                </div>

                                <div>
                                    <label class="check-item">
                                        <input type="checkbox" name="approved_indices[]" value="<?= $index ?>" checked>
                                        <span>Approve this question</span>
                                    </label>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Question Type</label>
                                    <select name="question_type[<?= $index ?>]" class="generated-question-type-selector">
                                        <option value="mcq" <?= selectedValue((string)$row['question_type'], 'mcq') ?>>MCQ</option>
                                        <option value="true_false" <?= selectedValue((string)$row['question_type'], 'true_false') ?>>True / False</option>
                                        <option value="short_answer" <?= selectedValue((string)$row['question_type'], 'short_answer') ?>>Short Answer</option>
                                        <option value="code_writing" <?= selectedValue((string)$row['question_type'], 'code_writing') ?>>Code Writing</option>
                                        <option value="debugging" <?= selectedValue((string)$row['question_type'], 'debugging') ?>>Debugging</option>
                                        <option value="output_prediction" <?= selectedValue((string)$row['question_type'], 'output_prediction') ?>>Output Prediction</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Difficulty</label>
                                    <select name="row_difficulty_level[<?= $index ?>]">
                                        <option value="Easy" <?= selectedValue((string)$row['difficulty_level'], 'Easy') ?>>Easy</option>
                                        <option value="Medium" <?= selectedValue((string)$row['difficulty_level'], 'Medium') ?>>Medium</option>
                                        <option value="Hard" <?= selectedValue((string)$row['difficulty_level'], 'Hard') ?>>Hard</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Marks</label>
                                    <input type="number" min="1" max="100" name="marks[<?= $index ?>]" value="<?= e($row['marks']) ?>">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Question Text</label>
                                <textarea name="question_text[<?= $index ?>]" required><?= e($row['question_text']) ?></textarea>
                            </div>

                            <div class="type-section generated-mcq-fields" style="<?= (string)$row['question_type'] === 'mcq' ? '' : 'display:none;' ?>">
                                <div class="type-section-title">MCQ Options</div>

                                <div class="choice-grid">
                                    <div class="form-group">
                                        <label class="form-label">Option A</label>
                                        <input type="text" name="option_a[<?= $index ?>]" value="<?= e($row['option_a'] ?? '') ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Option B</label>
                                        <input type="text" name="option_b[<?= $index ?>]" value="<?= e($row['option_b'] ?? '') ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Option C</label>
                                        <input type="text" name="option_c[<?= $index ?>]" value="<?= e($row['option_c'] ?? '') ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Option D</label>
                                        <input type="text" name="option_d[<?= $index ?>]" value="<?= e($row['option_d'] ?? '') ?>">
                                    </div>
                                </div>

                                <div class="form-group mb-0">
                                    <label class="form-label">Correct Answer</label>
                                    <select name="mcq_correct_answer[<?= $index ?>]">
                                        <option value="">-- Select Correct Answer --</option>
                                        <option value="A" <?= selectedValue((string)($row['correct_answer'] ?? ''), 'A') ?>>A</option>
                                        <option value="B" <?= selectedValue((string)($row['correct_answer'] ?? ''), 'B') ?>>B</option>
                                        <option value="C" <?= selectedValue((string)($row['correct_answer'] ?? ''), 'C') ?>>C</option>
                                        <option value="D" <?= selectedValue((string)($row['correct_answer'] ?? ''), 'D') ?>>D</option>
                                    </select>
                                </div>
                            </div>

                            <div class="type-section generated-tf-fields" style="<?= (string)$row['question_type'] === 'true_false' ? '' : 'display:none;' ?>">
                                <div class="type-section-title">True / False Answer</div>

                                <div class="form-group mb-0">
                                    <label class="form-label">Correct Answer</label>
                                    <select name="tf_correct_answer[<?= $index ?>]">
                                        <option value="">-- Select Correct Answer --</option>
                                        <option value="True" <?= selectedValue((string)($row['correct_answer'] ?? ''), 'True') ?>>True</option>
                                        <option value="False" <?= selectedValue((string)($row['correct_answer'] ?? ''), 'False') ?>>False</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group mt-3 mb-0">
                                <label class="form-label">Model Answer</label>
                                <textarea name="model_answer[<?= $index ?>]"><?= e($row['model_answer']) ?></textarea>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <div class="toolbar mt-4">
                        <div class="toolbar-left">
                            <button type="submit" class="btn btn-success">Save Approved Questions to Bank</button>
                        </div>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>
</main>

<div class="ai-loading-overlay" id="aiLoadingOverlay" aria-hidden="true">
    <div class="ai-loading-box">
        <div class="ai-loading-spinner"></div>
        <h3 class="ai-loading-title">Generating Questions</h3>
        <p class="ai-loading-text">
            Please wait while the AI creates your programming questions
            <span class="ai-loading-dots"></span>
        </p>
    </div>
</div>

<script>
(function () {
    function setSectionState(section, enabled) {
        if (!section) return;

        section.style.display = enabled ? 'block' : 'none';

        section.querySelectorAll('input, select, textarea').forEach(function (field) {
            field.disabled = !enabled;
        });
    }

    function updateGeneratedTypeSections(scope) {
        const root = scope || document;

        root.querySelectorAll('.generated-question-item').forEach(function (item) {
            const typeSelect = item.querySelector('.generated-question-type-selector');
            const mcqFields  = item.querySelector('.generated-mcq-fields');
            const tfFields   = item.querySelector('.generated-tf-fields');

            if (!typeSelect) return;

            const type = typeSelect.value;

            setSectionState(mcqFields, type === 'mcq');
            setSectionState(tfFields, type === 'true_false');
        });
    }

    function showAiLoading() {
        const overlay = document.getElementById('aiLoadingOverlay');
        const btn = document.getElementById('generateAiBtn');

        if (overlay) {
            overlay.classList.add('show');
            overlay.setAttribute('aria-hidden', 'false');
        }

        if (btn) {
            btn.disabled = true;

            const text = btn.querySelector('.btn-text');
            const loadingText = btn.querySelector('.btn-loading-text');

            if (text) {
                text.style.display = 'none';
            }

            if (loadingText) {
                loadingText.style.display = 'inline';
            }
        }
    }

    document.addEventListener('change', function (e) {
        if (e.target.classList.contains('generated-question-type-selector')) {
            updateGeneratedTypeSections(document);
        }
    });

    document.addEventListener('DOMContentLoaded', function () {
        updateGeneratedTypeSections(document);

        const generateForm = document.getElementById('aiGenerateForm');
        if (generateForm) {
            generateForm.addEventListener('submit', function () {
                showAiLoading();
            });
        }
    });

    updateGeneratedTypeSections(document);
})();
</script>

<?php require_once __DIR__ . '/include/footer.php'; ?>