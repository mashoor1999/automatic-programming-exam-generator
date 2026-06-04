# Automatic Programming Exam Generator Using LLM

## Overview
Automatic Programming Exam Generator is a web-based system that helps instructors create programming exams manually or through AI-generated questions. The system includes exam creation, AI question generation, question bank management, exam building, previewing, printing, and exporting.

## Main Features
- User login system
- Create exams manually
- Generate programming questions using LLM
- Manage reusable question bank
- Build exams from selected questions
- Preview and print exams
- Export exam data to Excel
- Support for MCQ, True/False, short answer, code writing, debugging, and output prediction questions

## Technologies Used
- PHP
- MySQL
- HTML
- CSS
- JavaScript
- LLM API

## Database
The repository includes only the database structure without real user data.

## How to Run
1. Copy the project folder to `htdocs`
2. Import `database/schema.sql` into MySQL
3. Rename `Data/config.example.php` to `Data/config.php`
4. Rename `Data/db.example.php` to `Data/db.php`
5. Update database credentials and LLM API settings
6. Run the project using localhost

## Security Note
Real passwords, API keys, private configuration files, and real user data are not included in this repository.