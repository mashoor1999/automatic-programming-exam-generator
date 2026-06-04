<?php
declare(strict_types=1);

session_start();

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

$examId = (int)($_GET['exam_id'] ?? 0);

if ($examId <= 0) {
    die('Invalid exam ID.');
}

try {
    $examStmt = $pdoConnection->prepare("
        SELECT
            e.*,
            u.full_name AS creator_name
        FROM exams e
        LEFT JOIN users u
            ON u.id = e.user_id
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
            eqb.question_order,
            COALESCE(eqb.marks_override, qb.marks) AS final_marks,
            qb.question_type,
            qb.difficulty_level,
            qb.question_text,
            qb.option_a,
            qb.option_b,
            qb.option_c,
            qb.option_d,
            qb.correct_answer,
            qb.model_answer
        FROM exam_question_bank eqb
        INNER JOIN question_bank qb
            ON qb.id = eqb.question_bank_id
        WHERE eqb.exam_id = :exam_id
        ORDER BY eqb.question_order ASC, eqb.id ASC
    ");
    $questionsStmt->execute([':exam_id' => $examId]);
    $questions = $questionsStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    die('Export failed: ' . $e->getMessage());
}

$safeTitle = preg_replace('/[^A-Za-z0-9_\-]/', '_', (string)$exam['exam_title']);
if (!$safeTitle) {
    $safeTitle = 'exam_' . $examId;
}

$filename = $safeTitle . '_export_' . date('Y-m-d_H-i-s') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');

if ($output === false) {
    die('Failed to open output stream.');
}

/* UTF-8 BOM for Excel */
fwrite($output, "\xEF\xBB\xBF");

/* Exam info */
fputcsv($output, ['Exam Information']);
fputcsv($output, ['Exam ID', $exam['id']]);
fputcsv($output, ['Exam Title', $exam['exam_title']]);
fputcsv($output, ['Topic', $exam['topic']]);
fputcsv($output, ['Programming Language', $exam['programming_language']]);
fputcsv($output, ['Difficulty', $exam['difficulty_level']]);
fputcsv($output, ['Planned Questions', $exam['question_count']]);
fputcsv($output, ['Duration Minutes', $exam['duration_minutes']]);
fputcsv($output, ['Status', ucfirst((string)$exam['status'])]);
fputcsv($output, ['Created By', $exam['creator_name'] ?: 'Unknown User']);
fputcsv($output, ['Created At', $exam['created_at']]);
fputcsv($output, []);
fputcsv($output, ['Questions']);

/* Questions header */
fputcsv($output, [
    'Order',
    'Question Type',
    'Difficulty',
    'Question Text',
    'Option A',
    'Option B',
    'Option C',
    'Option D',
    'Correct Answer',
    'Model Answer',
    'Marks'
]);

/* Questions data */
foreach ($questions as $row) {
    fputcsv($output, [
        $row['question_order'],
        $row['question_type'],
        $row['difficulty_level'],
        $row['question_text'],
        $row['option_a'],
        $row['option_b'],
        $row['option_c'],
        $row['option_d'],
        $row['correct_answer'],
        $row['model_answer'],
        $row['final_marks'],
    ]);
}

fclose($output);
exit;