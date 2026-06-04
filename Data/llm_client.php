<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function llm_get_config(): array
{
    return [
        'api_url' => defined('LLM_API_URL') ? trim((string)LLM_API_URL) : '',
        'api_key' => defined('LLM_API_KEY') ? trim((string)LLM_API_KEY) : '',
        'model'   => defined('LLM_MODEL') ? trim((string)LLM_MODEL) : '',
        'timeout' => defined('LLM_TIMEOUT_SECONDS') ? (int) LLM_TIMEOUT_SECONDS : 90,
    ];
}

/**
 * Main reusable generator for Question Bank items.
 */
function llm_generate_questions(array $filters, string $promptText = ''): array
{
    $config = llm_get_config();

    if ($config['api_url'] === '') {
        throw new RuntimeException('LLM_API_URL is empty in Data/config.php');
    }

    if ($config['model'] === '') {
        throw new RuntimeException('LLM_MODEL is empty in Data/config.php');
    }

    $programmingLanguage = trim((string)($filters['programming_language'] ?? ''));
    $topic               = trim((string)($filters['topic'] ?? ''));
    $difficultyLevel     = trim((string)($filters['difficulty_level'] ?? 'Medium'));
    $questionCount       = (int)($filters['question_count'] ?? 5);

    if ($programmingLanguage === '') {
        throw new RuntimeException('Programming language is required.');
    }

    if ($topic === '') {
        throw new RuntimeException('Topic is required.');
    }

    $allowedDifficulties = ['Easy', 'Medium', 'Hard', 'Mixed'];
    if (!in_array($difficultyLevel, $allowedDifficulties, true)) {
        $difficultyLevel = 'Medium';
    }

    if ($questionCount < 1 || $questionCount > 20) {
        throw new RuntimeException('Question count must be between 1 and 20.');
    }

    $systemMessage = implode("\n", [
        'You are a professional programming question generator for a reusable question bank.',
        'Return ONLY valid JSON.',
        'Do not add explanations, markdown, comments, or code fences.',
        'The JSON root must be an object with one key named "questions".',
        'The value of "questions" must be an array.',
        'Generate exactly ' . $questionCount . ' questions.',
        'Each question object must contain exactly these keys:',
        'question_text, question_type, difficulty_level, option_a, option_b, option_c, option_d, correct_answer, model_answer, marks.',
        'Allowed question_type values: mcq, true_false, short_answer, code_writing, debugging, output_prediction.',
        'Allowed difficulty_level values: Easy, Medium, Hard.',
        'Marks must be positive integers.',
        'For mcq questions:',
        '- provide four options in option_a, option_b, option_c, option_d',
        '- correct_answer must be one of: A, B, C, D',
        '- model_answer should clearly explain the correct answer',
        'For true_false questions:',
        '- set option_a to "True"',
        '- set option_b to "False"',
        '- set option_c and option_d to empty strings',
        '- correct_answer must be either "True" or "False"',
        '- model_answer should explain why',
        'For short_answer, code_writing, debugging, and output_prediction questions:',
        '- leave option_a, option_b, option_c, option_d empty strings',
        '- leave correct_answer empty string unless truly needed',
        '- provide the real model answer in model_answer',
        'Each question must be academically clear and useful in a programming exam context.',
        'When overall difficulty is Mixed, distribute the questions across Easy, Medium, and Hard, but each question must still have its own difficulty_level.',
        'Prefer practical and realistic programming questions.',
    ]);

    $userMessage = trim($promptText);
    if ($userMessage === '') {
        $userMessage = llm_build_prompt_from_filters([
            'programming_language' => $programmingLanguage,
            'topic'                => $topic,
            'difficulty_level'     => $difficultyLevel,
            'question_count'       => $questionCount,
            'notes'                => (string)($filters['notes'] ?? ''),
        ]);
    } else {
        $userMessage .= "\n\n";
        $userMessage .= "Use these exact requirements:\n";
        $userMessage .= "- Programming language: {$programmingLanguage}\n";
        $userMessage .= "- Topic: {$topic}\n";
        $userMessage .= "- Overall difficulty request: {$difficultyLevel}\n";
        $userMessage .= "- Question count: {$questionCount}\n";
    }

    $userMessage .= "\n";
    $userMessage .= 'Strict JSON format example:' . "\n";
    $userMessage .= '{"questions":[{"question_text":"...","question_type":"mcq","difficulty_level":"Medium","option_a":"...","option_b":"...","option_c":"...","option_d":"...","correct_answer":"A","model_answer":"...","marks":5}]}' . "\n";
    $userMessage .= 'Return JSON only.';

    $payload = [
        'model' => $config['model'],
        'messages' => [
            ['role' => 'system', 'content' => $systemMessage],
            ['role' => 'user', 'content' => $userMessage],
        ],
        'temperature' => 0.10,
    ];

    $apiResponse   = llm_send_request($config, $payload);
    $assistantText = llm_extract_assistant_text($apiResponse);
    $jsonText      = llm_extract_json_text($assistantText);
    $decoded       = json_decode($jsonText, true);

    if (!is_array($decoded)) {
        throw new RuntimeException('The LLM returned invalid JSON that could not be decoded.');
    }

    $questions = llm_normalize_questions($decoded, $questionCount);

    return [
        'raw_text'  => $assistantText,
        'json_text' => $jsonText,
        'questions' => $questions,
        'model'     => $config['model'],
    ];
}
function llm_build_prompt_from_filters(array $filters): string
{
    $language   = trim((string)($filters['programming_language'] ?? ''));
    $topic      = trim((string)($filters['topic'] ?? ''));
    $difficulty = trim((string)($filters['difficulty_level'] ?? 'Medium'));
    $count      = (int)($filters['question_count'] ?? 5);
    $notes      = trim((string)($filters['notes'] ?? ''));

    $lines = [];
    $lines[] = "Generate {$count} programming questions for a reusable question bank.";
    $lines[] = "Programming language: {$language}.";
    $lines[] = "Topic: {$topic}.";

    if ($difficulty === 'Mixed') {
        $lines[] = "Use a balanced mix of Easy, Medium, and Hard questions.";
        $lines[] = "Every question must include its own difficulty_level as Easy, Medium, or Hard.";
    } else {
        $lines[] = "All questions should target {$difficulty} difficulty.";
    }

    $lines[] = "Allowed question types are: mcq, true_false, short_answer, code_writing, debugging, output_prediction.";
    $lines[] = "Include a good mix when appropriate.";
    $lines[] = "For MCQ questions, always provide 4 options and a correct answer letter.";
    $lines[] = "For true_false questions, use True / False clearly and provide the correct answer.";
    $lines[] = "For other question types, provide a clear model answer.";
    $lines[] = "Use integer marks only.";
    $lines[] = "The questions must be useful for academic exams and suitable for storing in a reusable question bank.";

    if ($notes !== '') {
        $lines[] = "Additional notes: {$notes}";
    }

    return implode("\n", $lines);
}

function llm_send_request(array $config, array $payload): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL extension is not enabled. Enable cURL in php.ini first.');
    }

    $headers = ['Content-Type: application/json'];

    if ($config['api_key'] !== '') {
        $headers[] = 'Authorization: Bearer ' . $config['api_key'];
    }

    $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($jsonPayload === false) {
        throw new RuntimeException('Failed to encode the LLM request payload as JSON.');
    }

    $ch = curl_init($config['api_url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => $jsonPayload,
        CURLOPT_TIMEOUT        => max(10, (int)$config['timeout']),
    ]);

    $responseBody = curl_exec($ch);
    $curlError    = curl_error($ch);
    $httpCode     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($responseBody === false) {
        throw new RuntimeException('LLM request failed: ' . $curlError);
    }

    $decoded = json_decode($responseBody, true);
    if (!is_array($decoded)) {
        throw new RuntimeException(
            'The API response is not valid JSON. HTTP ' . $httpCode . '. Raw response: ' . llm_shorten_text($responseBody)
        );
    }

    if ($httpCode >= 400) {
        $message = $decoded['error']['message'] ?? $decoded['message'] ?? ('HTTP ' . $httpCode);
        throw new RuntimeException('LLM API error: ' . $message);
    }

    return $decoded;
}

function llm_extract_assistant_text(array $response): string
{
    if (isset($response['choices'][0]['message']['content'])) {
        $content = $response['choices'][0]['message']['content'];

        if (is_string($content)) {
            return trim($content);
        }

        if (is_array($content)) {
            $parts = [];
            foreach ($content as $item) {
                if (is_array($item) && isset($item['text']) && is_string($item['text'])) {
                    $parts[] = $item['text'];
                } elseif (is_string($item)) {
                    $parts[] = $item;
                }
            }

            $joined = trim(implode("\n", $parts));
            if ($joined !== '') {
                return $joined;
            }
        }
    }

    if (isset($response['output_text']) && is_string($response['output_text'])) {
        return trim($response['output_text']);
    }

    if (isset($response['output']) && is_array($response['output'])) {
        $parts = [];
        foreach ($response['output'] as $outputItem) {
            if (!is_array($outputItem) || !isset($outputItem['content']) || !is_array($outputItem['content'])) {
                continue;
            }

            foreach ($outputItem['content'] as $contentItem) {
                if (is_array($contentItem) && isset($contentItem['text']) && is_string($contentItem['text'])) {
                    $parts[] = $contentItem['text'];
                }
            }
        }

        $joined = trim(implode("\n", $parts));
        if ($joined !== '') {
            return $joined;
        }
    }

    throw new RuntimeException('Could not find assistant text in the API response.');
}

function llm_extract_json_text(string $text): string
{
    $text = trim($text);

    if ($text === '') {
        throw new RuntimeException('The LLM response was empty.');
    }

    $fenceCleaned = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text);
    if (is_string($fenceCleaned)) {
        $text = trim($fenceCleaned);
    }

    if (llm_is_valid_json($text)) {
        return $text;
    }

    $firstBrace = strpos($text, '{');
    $lastBrace  = strrpos($text, '}');
    if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
        $candidate = substr($text, $firstBrace, $lastBrace - $firstBrace + 1);
        if (llm_is_valid_json($candidate)) {
            return $candidate;
        }
    }

    throw new RuntimeException('The LLM response did not contain valid JSON. Raw response: ' . llm_shorten_text($text));
}

function llm_is_valid_json(string $text): bool
{
    json_decode($text, true);
    return json_last_error() === JSON_ERROR_NONE;
}

function llm_normalize_questions(array $decoded, int $plannedCount = 0): array
{
    $questions = [];

    if (isset($decoded['questions']) && is_array($decoded['questions'])) {
        $questions = $decoded['questions'];
    } elseif (array_is_list($decoded)) {
        $questions = $decoded;
    } else {
        throw new RuntimeException('The JSON response does not contain a valid "questions" array.');
    }

    $allowedTypes        = ['mcq', 'true_false', 'short_answer', 'code_writing', 'debugging', 'output_prediction'];
    $allowedDifficulties = ['Easy', 'Medium', 'Hard'];
    $normalized          = [];

    foreach ($questions as $item) {
        if (!is_array($item)) {
            continue;
        }

        $questionText    = trim((string)($item['question_text'] ?? $item['question'] ?? ''));
        $questionType    = llm_normalize_question_type((string)($item['question_type'] ?? $item['type'] ?? 'short_answer'));
        $difficultyLevel = llm_normalize_difficulty_level((string)($item['difficulty_level'] ?? 'Medium'));
        $modelAnswer     = trim((string)($item['model_answer'] ?? $item['answer'] ?? ''));
        $marks           = (int)($item['marks'] ?? 1);

        $optionA         = trim((string)($item['option_a'] ?? ''));
        $optionB         = trim((string)($item['option_b'] ?? ''));
        $optionC         = trim((string)($item['option_c'] ?? ''));
        $optionD         = trim((string)($item['option_d'] ?? ''));
        $correctAnswer   = trim((string)($item['correct_answer'] ?? ''));

        if ($questionText === '') {
            continue;
        }

        if (!in_array($questionType, $allowedTypes, true)) {
            $questionType = 'short_answer';
        }

        if (!in_array($difficultyLevel, $allowedDifficulties, true)) {
            $difficultyLevel = 'Medium';
        }

        if ($marks < 1) {
            $marks = 1;
        }

        if ($marks > 100) {
            $marks = 100;
        }

        if ($questionType === 'mcq') {
            $optionA = $optionA !== '' ? $optionA : 'Option A';
            $optionB = $optionB !== '' ? $optionB : 'Option B';
            $optionC = $optionC !== '' ? $optionC : 'Option C';
            $optionD = $optionD !== '' ? $optionD : 'Option D';
            $correctAnswer = llm_normalize_mcq_correct_answer($correctAnswer, [
                'A' => $optionA,
                'B' => $optionB,
                'C' => $optionC,
                'D' => $optionD,
            ]);
        } elseif ($questionType === 'true_false') {
            $optionA = 'True';
            $optionB = 'False';
            $optionC = '';
            $optionD = '';
            $correctAnswer = llm_normalize_true_false_answer($correctAnswer);
        } else {
            $optionA = '';
            $optionB = '';
            $optionC = '';
            $optionD = '';
            $correctAnswer = '';
        }

        $normalized[] = [
            'question_text'    => $questionText,
            'question_type'    => $questionType,
            'difficulty_level' => $difficultyLevel,
            'option_a'         => $optionA,
            'option_b'         => $optionB,
            'option_c'         => $optionC,
            'option_d'         => $optionD,
            'correct_answer'   => $correctAnswer,
            'model_answer'     => $modelAnswer,
            'marks'            => $marks,
            'question_order'   => count($normalized) + 1,
        ];
    }

    if (empty($normalized)) {
        throw new RuntimeException('The LLM returned JSON, but no usable questions were found inside it.');
    }

    if ($plannedCount > 0 && count($normalized) > $plannedCount) {
        $normalized = array_slice($normalized, 0, $plannedCount);
        foreach ($normalized as $index => &$row) {
            $row['question_order'] = $index + 1;
        }
        unset($row);
    }

    return $normalized;
}

function llm_normalize_question_type(string $type): string
{
    $type = strtolower(trim($type));
    $type = str_replace([' ', '-'], '_', $type);

    return match ($type) {
        'mcq',
        'multiple_choice',
        'multiple_choice_question' => 'mcq',

        'truefalse',
        'true_false',
        'true/false',
        'true_false_question' => 'true_false',

        'short',
        'shortanswer',
        'short_answer' => 'short_answer',

        'code',
        'coding',
        'programming',
        'code_writing' => 'code_writing',

        'debug',
        'debugging' => 'debugging',

        'output',
        'output_prediction' => 'output_prediction',

        default => $type,
    };
}

function llm_normalize_difficulty_level(string $difficulty): string
{
    $difficulty = strtolower(trim($difficulty));

    return match ($difficulty) {
        'easy'   => 'Easy',
        'medium' => 'Medium',
        'hard'   => 'Hard',
        default  => 'Medium',
    };
}

function llm_normalize_mcq_correct_answer(string $correctAnswer, array $options): string
{
    $value = strtoupper(trim($correctAnswer));

    if (in_array($value, ['A', 'B', 'C', 'D'], true)) {
        return $value;
    }

    $cleanOriginal = llm_clean_compare_text($correctAnswer);

    foreach ($options as $letter => $optionText) {
        if ($cleanOriginal !== '' && llm_clean_compare_text($optionText) === $cleanOriginal) {
            return $letter;
        }
    }

    return 'A';
}

function llm_normalize_true_false_answer(string $correctAnswer): string
{
    $value = strtolower(trim($correctAnswer));

    return match ($value) {
        'true', 't', 'صح', 'correct'  => 'True',
        'false', 'f', 'خطأ', 'wrong'  => 'False',
        default                       => 'True',
    };
}

function llm_clean_compare_text(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/\s+/', ' ', $text);
    return is_string($text) ? $text : '';
}

function llm_shorten_text(string $text, int $length = 350): string
{
    $text = trim($text);

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($text, 'UTF-8') > $length
            ? mb_substr($text, 0, $length, 'UTF-8') . '...'
            : $text;
    }

    return strlen($text) > $length
        ? substr($text, 0, $length) . '...'
        : $text;
}