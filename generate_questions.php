<?php
declare(strict_types=1);

session_start();

$examId = isset($_GET['exam_id']) ? (int)$_GET['exam_id'] : 0;

if ($examId > 0) {
    header('Location: view_exams.php?view=' . $examId, true, 302);
    exit;
}

header('Location: dashboard.php', true, 302);
exit;