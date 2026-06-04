-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Apr 05, 2026 at 08:23 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `programming_exam_generator`
--

-- --------------------------------------------------------

--
-- Table structure for table `exams`
--

CREATE TABLE `exams` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `exam_title` varchar(150) NOT NULL,
  `topic` varchar(255) NOT NULL,
  `programming_language` varchar(100) NOT NULL,
  `difficulty_level` enum('Easy','Medium','Hard','Mixed') NOT NULL DEFAULT 'Medium',
  `question_count` int(11) NOT NULL,
  `duration_minutes` int(11) NOT NULL DEFAULT 60,
  `notes` text DEFAULT NULL,
  `status` enum('draft','ready','generated','published') NOT NULL DEFAULT 'draft',
  `prompt_text` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `exams`
--

INSERT INTO `exams` (`id`, `user_id`, `exam_title`, `topic`, `programming_language`, `difficulty_level`, `question_count`, `duration_minutes`, `notes`, `status`, `prompt_text`, `created_at`) VALUES
(1, 1, 'Programming 1', 'Loops in Python', 'Python', 'Medium', 3, 60, NULL, 'generated', 'Generate 3 medium-level Python programming questions about loops with model answers.', '2026-03-25 13:00:43'),
(2, 1, 'programing 2', 'loop', 'C++', 'Medium', 5, 60, '', 'ready', NULL, '2026-04-04 21:51:56'),
(3, 2, 'test', 'Topic', 'SQL', 'Mixed', 1, 60, '', 'generated', NULL, '2026-04-05 13:35:18'),
(4, 2, '<>', '{}', 'PHP', 'Easy', 20, 60, '', 'ready', NULL, '2026-04-05 13:46:58');

-- --------------------------------------------------------

--
-- Table structure for table `exam_question_bank`
--

CREATE TABLE `exam_question_bank` (
  `id` int(11) NOT NULL,
  `exam_id` int(11) NOT NULL,
  `question_bank_id` int(11) NOT NULL,
  `question_order` int(11) NOT NULL,
  `marks_override` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `exam_question_bank`
--

INSERT INTO `exam_question_bank` (`id`, `exam_id`, `question_bank_id`, `question_order`, `marks_override`, `created_at`) VALUES
(1, 2, 1, 1, NULL, '2026-04-04 21:51:56'),
(2, 3, 2, 1, NULL, '2026-04-05 13:35:18'),
(3, 4, 3, 1, NULL, '2026-04-05 13:46:58');

-- --------------------------------------------------------

--
-- Table structure for table `questions_legacy_backup`
--

CREATE TABLE `questions_legacy_backup` (
  `id` int(11) NOT NULL,
  `exam_id` int(11) NOT NULL,
  `question_text` text NOT NULL,
  `question_type` enum('mcq','short_answer','code_writing','debugging','output_prediction') NOT NULL,
  `model_answer` text NOT NULL,
  `marks` int(11) DEFAULT 1,
  `question_order` int(11) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `questions_legacy_backup`
--

INSERT INTO `questions_legacy_backup` (`id`, `exam_id`, `question_text`, `question_type`, `model_answer`, `marks`, `question_order`, `created_at`) VALUES
(7, 1, 'Write a Python function that takes a list of numbers as input and returns the sum of all even numbers in the list.', 'code_writing', 'def sum_even(numbers):\r\n  \"\"\"Calculates the sum of even numbers in a list.\r\n\r\n  Args:\r\n    numbers: A list of numbers.\r\n\r\n  Returns:\r\n    The sum of all even numbers in the list.\r\n  \"\"\"\r\n  total = 0\r\n  for number in numbers:\r\n    if number % 2 == 0:\r\n      total += number\r\n  return total', 10, 1, '2026-03-26 15:02:53'),
(8, 1, 'Given the list `numbers = [1, 2, 3, 4, 5, 6]`, use a loop to print each element of the list on a new line.', 'code_writing', 'numbers = [1, 2, 3, 4, 5, 6]\r\nfor number in numbers:\r\n  print(number)', 5, 2, '2026-03-26 15:02:53'),
(9, 1, 'A user enters a positive integer. Write a Python program that uses a `while` loop to repeatedly multiply the entered number by 2 until the result is greater than 100.\r\nPrint the final result.', 'code_writing', 'num = int(input(\"Enter a positive integer: \"))\r\nresult = num\r\nwhile result <= 100:\r\n  result *= 2\r\nprint(\"Final result:\", result)', 8, 3, '2026-03-26 15:02:53');

-- --------------------------------------------------------

--
-- Table structure for table `question_bank`
--

CREATE TABLE `question_bank` (
  `id` int(11) NOT NULL,
  `programming_language` varchar(100) NOT NULL,
  `topic` varchar(255) NOT NULL,
  `difficulty_level` enum('Easy','Medium','Hard') NOT NULL DEFAULT 'Medium',
  `question_type` enum('mcq','true_false','short_answer','code_writing','debugging','output_prediction') NOT NULL,
  `question_text` mediumtext NOT NULL,
  `option_a` text DEFAULT NULL,
  `option_b` text DEFAULT NULL,
  `option_c` text DEFAULT NULL,
  `option_d` text DEFAULT NULL,
  `correct_answer` varchar(255) DEFAULT NULL,
  `model_answer` mediumtext DEFAULT NULL,
  `marks` int(11) NOT NULL DEFAULT 5,
  `source_type` enum('manual','ai') NOT NULL DEFAULT 'manual',
  `status` enum('pending','approved','archived') NOT NULL DEFAULT 'approved',
  `generation_prompt` mediumtext DEFAULT NULL,
  `llm_model` varchar(150) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `question_bank`
--

INSERT INTO `question_bank` (`id`, `programming_language`, `topic`, `difficulty_level`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_answer`, `model_answer`, `marks`, `source_type`, `status`, `generation_prompt`, `llm_model`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 'C++', 'loop', 'Easy', 'true_false', 'Test', 'True', 'False', NULL, NULL, 'False', 'why ??', 2, 'manual', 'approved', NULL, NULL, 1, '2026-04-04 21:51:56', '2026-04-04 22:09:04'),
(2, 'SQL', 'Topic', 'Easy', 'short_answer', 'ooops', NULL, NULL, NULL, NULL, NULL, 'mmm', 7, 'manual', 'approved', NULL, NULL, 2, '2026-04-05 13:35:18', '2026-04-05 13:35:18'),
(3, 'PHP', '{}', 'Easy', 'mcq', 'LLLLLLl', 'qwe', 'ewq', 'wqe', 'qew', 'D', 'Correct answer: D) qew', 20, 'manual', 'approved', NULL, NULL, 2, '2026-04-05 13:46:58', '2026-04-05 13:46:58'),
(4, 'SQL', 'Topic', 'Easy', 'mcq', 'Which SQL clause is used to filter rows based on a specified condition?', 'SELECT', 'WHERE', 'ORDER BY', 'GROUP BY', 'B', 'The WHERE clause is used to filter rows from a table based on one or more conditions. It specifies the criteria that must be met for a row to be included in the result set.', 3, 'ai', 'approved', '', 'google/gemma-3-4b', 2, '2026-04-05 14:03:12', '2026-04-05 14:03:12'),
(5, 'SQL', 'Topic', 'Easy', 'true_false', 'True or False: The `JOIN` operation combines rows from two or more tables based on a related column between them.', 'True', 'False', NULL, NULL, 'True', 'A JOIN operation combines rows from two or more tables based on a related column. This is the fundamental purpose of a JOIN.', 2, 'ai', 'approved', '', 'google/gemma-3-4b', 2, '2026-04-05 14:03:12', '2026-04-05 14:03:12'),
(6, 'SQL', 'Topic', 'Medium', 'code_writing', 'Write an SQL query to retrieve the names and email addresses of all customers from the \'Customers\' table where their city is \'London\'.', NULL, NULL, NULL, NULL, NULL, 'This query selects the `name` and `email` columns from the `Customers` table where the `city` column is equal to \'London\'.', 7, 'ai', 'approved', '', 'google/gemma-3-4b', 2, '2026-04-05 14:03:12', '2026-04-05 14:03:12'),
(7, 'SQL', 'Topic', 'Hard', 'debugging', 'You have a database with a \'Products\' table containing product IDs, names, and prices. You need to find all products whose price is greater than $50 but less than $100.  Write an SQL query to achieve this.', NULL, NULL, NULL, NULL, NULL, 'The provided query correctly filters the \'Products\' table to select products with a price between $50 and $100 (exclusive). The `AND` operator ensures that both conditions are met.', 8, 'ai', 'approved', '', 'google/gemma-3-4b', 2, '2026-04-05 14:03:12', '2026-04-05 14:03:12'),
(8, 'SQL', 'Topic', 'Medium', 'output_prediction', 'Given the following SQL code snippet, what will be the output?', NULL, NULL, NULL, NULL, NULL, 'The query selects all rows from the `my_table` table where the `id` column is equal to 1 and the `value` column is equal to 10. The result set will have two columns: \'id\' and \'value\', with the specified values.', 5, 'ai', 'approved', '', 'google/gemma-3-4b', 2, '2026-04-05 14:03:12', '2026-04-05 14:03:12');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `full_name` varchar(150) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','teacher') DEFAULT 'teacher',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `first_name`, `last_name`, `full_name`, `email`, `password`, `role`, `created_at`, `updated_at`) VALUES
(1, 'Mashhoor', 'AL-Awaishah', 'Mashhoor Teacher', 'teacher@test.com', '202cb962ac59075b964b07152d234b70', 'teacher', '2026-03-25 12:58:04', '2026-04-05 12:35:19'),
(2, 'Yesmmm', 'Yess', 'Noo', 'test@gmail.com', '202cb962ac59075b964b07152d234b70', 'teacher', '2026-04-05 13:28:18', '2026-04-05 13:28:18');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `exams`
--
ALTER TABLE `exams`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `exam_question_bank`
--
ALTER TABLE `exam_question_bank`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_exam_question_order` (`exam_id`,`question_order`),
  ADD UNIQUE KEY `uq_exam_question_bank` (`exam_id`,`question_bank_id`),
  ADD KEY `idx_eqb_exam` (`exam_id`),
  ADD KEY `idx_eqb_question_bank` (`question_bank_id`);

--
-- Indexes for table `questions_legacy_backup`
--
ALTER TABLE `questions_legacy_backup`
  ADD PRIMARY KEY (`id`),
  ADD KEY `exam_id` (`exam_id`);

--
-- Indexes for table `question_bank`
--
ALTER TABLE `question_bank`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_qb_language` (`programming_language`),
  ADD KEY `idx_qb_topic` (`topic`),
  ADD KEY `idx_qb_difficulty` (`difficulty_level`),
  ADD KEY `idx_qb_type` (`question_type`),
  ADD KEY `idx_qb_status` (`status`),
  ADD KEY `idx_qb_source` (`source_type`),
  ADD KEY `fk_question_bank_user` (`created_by`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `exams`
--
ALTER TABLE `exams`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `exam_question_bank`
--
ALTER TABLE `exam_question_bank`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `questions_legacy_backup`
--
ALTER TABLE `questions_legacy_backup`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `question_bank`
--
ALTER TABLE `question_bank`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `exams`
--
ALTER TABLE `exams`
  ADD CONSTRAINT `exams_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `exam_question_bank`
--
ALTER TABLE `exam_question_bank`
  ADD CONSTRAINT `fk_eqb_exam` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_eqb_question_bank` FOREIGN KEY (`question_bank_id`) REFERENCES `question_bank` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `questions_legacy_backup`
--
ALTER TABLE `questions_legacy_backup`
  ADD CONSTRAINT `questions_legacy_backup_ibfk_1` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `question_bank`
--
ALTER TABLE `question_bank`
  ADD CONSTRAINT `fk_question_bank_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
