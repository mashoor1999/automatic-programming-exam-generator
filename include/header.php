<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pageTitle  = $pageTitle ?? 'Automatic Exam Generator';
$basePath   = $basePath ?? '';
$pageStyles = $pageStyles ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>

<link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>CSS/style.css?v=<?= time() ?>">

    <?php if (!empty($pageStyles)): ?>
        <style>
            <?= $pageStyles ?>
        </style>
    <?php endif; ?>
</head>
<body>

<div class="app-shell">
    <header class="app-topbar">
        <div class="topbar-left">
            <div class="topbar-brand-badge">AG</div>

            <div>
                <h2 class="topbar-brand-title">Automatic Exam Generator</h2>
                <p class="topbar-brand-subtitle">
                    <?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?>
                </p>
            </div>
        </div>

        <div class="topbar-right">
            <button type="button" class="theme-toggle-btn" id="themeToggle" title="Toggle theme">
                🌙
            </button>
        </div>
    </header>

    <div class="app-body">