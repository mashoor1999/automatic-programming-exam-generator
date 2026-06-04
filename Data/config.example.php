<?php

declare(strict_types=1);

define('DB_HOST', 'localhost');
define('DB_NAME', 'programming_exam_generator');
define('DB_USER', 'root');
define('DB_PASS', '');

define('PASS_PEPPER', '');

// =========================
// LLM CONNECTION SETTINGS
// =========================
define('LLM_API_URL', 'YOUR_API_HERE');
define('LLM_API_KEY', '');
define('LLM_MODEL', 'google/gemma-3-4b');
define('LLM_TIMEOUT_SECONDS', 180);