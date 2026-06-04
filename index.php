<?php
declare(strict_types=1);

session_start();

if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0) {
    header('Location: dashboard.php');
    exit;
}

$errorMessage = $_SESSION['login_error'] ?? '';
$oldEmail     = $_SESSION['old_email'] ?? '';
$timedOut     = isset($_GET['timeout']) && (int)$_GET['timeout'] === 1;

unset($_SESSION['login_error'], $_SESSION['old_email']);

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Automatic Exam Generator</title>
    <link rel="stylesheet" href="style.css">
    <style>
        :root {
            --login-primary: #2563eb;
            --login-primary-2: #1d4ed8;
            --login-accent: #06b6d4;
            --login-accent-2: #8b5cf6;
            --login-dark: #0f172a;
            --login-soft: #475569;
            --login-border: rgba(255,255,255,0.16);
            --login-glass: rgba(255,255,255,0.10);
            --login-glass-strong: rgba(255,255,255,0.16);
        }

        * {
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            margin: 0;
            font-family: var(--font-family, 'Rubik', 'Segoe UI', Tahoma, Arial, sans-serif);
            color: #fff;
            background:
                radial-gradient(circle at 10% 20%, rgba(37,99,235,0.25), transparent 24%),
                radial-gradient(circle at 85% 15%, rgba(6,182,212,0.22), transparent 24%),
                radial-gradient(circle at 80% 80%, rgba(139,92,246,0.18), transparent 24%),
                linear-gradient(135deg, #071120 0%, #0b1730 35%, #0f1d3b 65%, #0a1224 100%);
            overflow: hidden;
            position: relative;
        }

        .bg-orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(10px);
            opacity: 0.55;
            animation: floatOrb 9s ease-in-out infinite;
            pointer-events: none;
        }

        .orb-1 {
            width: 260px;
            height: 260px;
            top: -60px;
            left: -40px;
            background: radial-gradient(circle, rgba(37,99,235,0.55), rgba(37,99,235,0.05));
        }

        .orb-2 {
            width: 320px;
            height: 320px;
            bottom: -110px;
            right: -70px;
            background: radial-gradient(circle, rgba(6,182,212,0.45), rgba(6,182,212,0.04));
            animation-delay: 1.2s;
        }

        .orb-3 {
            width: 180px;
            height: 180px;
            top: 55%;
            left: 48%;
            background: radial-gradient(circle, rgba(139,92,246,0.35), rgba(139,92,246,0.03));
            animation-delay: 2s;
        }

        @keyframes floatOrb {
            0%, 100% {
                transform: translateY(0px) translateX(0px);
            }
            50% {
                transform: translateY(-18px) translateX(14px);
            }
        }

        .login-page {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 28px;
            position: relative;
            z-index: 2;
        }

        .login-shell {
            width: 100%;
            max-width: 1320px;
            min-height: 720px;
            display: grid;
            grid-template-columns: 1.08fr 0.92fr;
            border-radius: 34px;
            overflow: hidden;
            border: 1px solid rgba(255,255,255,0.10);
            box-shadow:
                0 30px 80px rgba(0,0,0,0.35),
                0 8px 24px rgba(15,23,42,0.25);
            background: rgba(255,255,255,0.05);
            backdrop-filter: blur(10px);
        }

        .login-left {
            position: relative;
            padding: 54px;
            background:
                linear-gradient(155deg, rgba(37,99,235,0.92), rgba(6,182,212,0.82) 55%, rgba(14,165,233,0.78));
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            overflow: hidden;
        }

        .login-left::before,
        .login-left::after {
            content: "";
            position: absolute;
            border-radius: 50%;
            background: rgba(255,255,255,0.10);
            pointer-events: none;
        }

        .login-left::before {
            width: 260px;
            height: 260px;
            top: -70px;
            right: -70px;
        }

        .login-left::after {
            width: 220px;
            height: 220px;
            bottom: -80px;
            left: -60px;
        }

        .brand-top {
            position: relative;
            z-index: 2;
        }

        .brand-badge {
            width: 84px;
            height: 84px;
            border-radius: 24px;
            display: grid;
            place-items: center;
            font-weight: 900;
            font-size: 28px;
            color: #fff;
            background: rgba(255,255,255,0.18);
            border: 1px solid rgba(255,255,255,0.22);
            box-shadow: 0 10px 28px rgba(0,0,0,0.16);
            margin-bottom: 24px;
            backdrop-filter: blur(8px);
        }

        .hero-kicker {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 16px;
            border-radius: 999px;
            background: rgba(255,255,255,0.14);
            border: 1px solid rgba(255,255,255,0.16);
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 0.4px;
            margin-bottom: 18px;
        }

        .login-left h1 {
            margin: 0 0 16px;
            font-size: clamp(38px, 4vw, 56px);
            line-height: 1.08;
            font-weight: 900;
            letter-spacing: -0.02em;
        }

        .hero-text {
            margin: 0;
            max-width: 640px;
            color: rgba(255,255,255,0.92);
            font-size: 16px;
            line-height: 1.9;
        }

        .hero-stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
            margin-top: 30px;
            position: relative;
            z-index: 2;
        }

        .hero-stat {
            padding: 18px;
            border-radius: 20px;
            background: rgba(255,255,255,0.13);
            border: 1px solid rgba(255,255,255,0.16);
            backdrop-filter: blur(8px);
        }

        .hero-stat .num {
            font-size: 28px;
            font-weight: 900;
            line-height: 1.1;
            margin-bottom: 6px;
        }

        .hero-stat .label {
            font-size: 13px;
            color: rgba(255,255,255,0.86);
            line-height: 1.5;
        }

        .feature-grid {
            position: relative;
            z-index: 2;
            display: grid;
            gap: 14px;
            margin-top: 34px;
        }

        .feature-card {
            display: flex;
            gap: 14px;
            align-items: flex-start;
            padding: 18px 20px;
            border-radius: 22px;
            background: rgba(255,255,255,0.12);
            border: 1px solid rgba(255,255,255,0.16);
            backdrop-filter: blur(10px);
            transition: transform 0.25s ease, background 0.25s ease;
        }

        .feature-card:hover {
            transform: translateX(6px);
            background: rgba(255,255,255,0.16);
        }

        .feature-icon {
            width: 48px;
            height: 48px;
            flex-shrink: 0;
            border-radius: 16px;
            display: grid;
            place-items: center;
            background: rgba(255,255,255,0.18);
            font-size: 21px;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.12);
        }

        .feature-content h4 {
            margin: 0 0 6px;
            font-size: 16px;
            color: #fff;
        }

        .feature-content p {
            margin: 0;
            color: rgba(255,255,255,0.88);
            font-size: 13px;
            line-height: 1.7;
        }

        .hero-footer {
            position: relative;
            z-index: 2;
            margin-top: 26px;
            padding-top: 18px;
            border-top: 1px solid rgba(255,255,255,0.16);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            color: rgba(255,255,255,0.88);
            font-size: 14px;
            font-weight: 600;
        }

        .login-right {
            background:
                linear-gradient(180deg, rgba(255,255,255,0.92), rgba(248,250,252,0.96));
            padding: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }

        .login-right::before {
            content: "";
            position: absolute;
            inset: 20px;
            border-radius: 28px;
            border: 1px solid rgba(37,99,235,0.08);
            pointer-events: none;
        }

        .form-shell {
            width: 100%;
            max-width: 460px;
            position: relative;
            z-index: 2;
        }

        .form-head {
            text-align: left;
            margin-bottom: 24px;
        }

        .form-head .mini-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 14px;
            border-radius: 999px;
            background: rgba(37,99,235,0.08);
            color: #1d4ed8;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.3px;
            margin-bottom: 14px;
        }

        .form-head h2 {
            margin: 0 0 10px;
            font-size: 38px;
            line-height: 1.1;
            color: #0f172a;
            font-weight: 900;
        }

        .form-head p {
            margin: 0;
            color: #64748b;
            font-size: 15px;
            line-height: 1.8;
        }

        .login-card {
            background: rgba(255,255,255,0.88);
            border: 1px solid rgba(148,163,184,0.20);
            border-radius: 28px;
            padding: 28px;
            box-shadow:
                0 18px 44px rgba(15,23,42,0.10),
                0 4px 12px rgba(15,23,42,0.05);
            backdrop-filter: blur(8px);
        }

        .alert {
            border-radius: 16px;
            padding: 14px 16px;
            font-size: 14px;
            font-weight: 700;
            margin-bottom: 16px;
        }

        .alert-danger {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .alert-warning {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-label {
            display: inline-block;
            margin-bottom: 8px;
            font-weight: 800;
            color: #0f172a;
            font-size: 14px;
        }

        .input-wrap {
            position: relative;
        }

        .form-input {
            width: 100%;
            height: 56px;
            border-radius: 18px;
            border: 1px solid #dbe2ea;
            background: #fff;
            padding: 0 18px 0 52px;
            font-size: 15px;
            font-weight: 500;
            color: #0f172a;
            outline: none;
            transition: all 0.22s ease;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.55);
        }

        .form-input:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 4px rgba(37,99,235,0.12);
            transform: translateY(-1px);
        }

        .input-icon {
            position: absolute;
            left: 17px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 17px;
            color: #64748b;
            pointer-events: none;
        }

        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            border: none;
            background: transparent;
            color: #475569;
            font-weight: 800;
            cursor: pointer;
            padding: 8px 10px;
            border-radius: 10px;
            transition: all 0.2s ease;
        }

        .password-toggle:hover {
            background: rgba(37,99,235,0.08);
            color: #1d4ed8;
        }

        .login-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 18px;
        }

        .remember-box {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            color: #475569;
            font-size: 14px;
            font-weight: 600;
        }

        .remember-box input {
            width: 18px;
            height: 18px;
            accent-color: #2563eb;
            cursor: pointer;
        }

        .small-link {
            text-decoration: none;
            font-size: 14px;
            font-weight: 800;
            color: #2563eb;
            transition: color 0.2s ease;
        }

        .small-link:hover {
            color: #1d4ed8;
        }

        .login-btn {
            width: 100%;
            height: 58px;
            border: none;
            border-radius: 18px;
            background: linear-gradient(135deg, #2563eb, #06b6d4);
            color: #fff;
            font-size: 16px;
            font-weight: 900;
            letter-spacing: 0.2px;
            cursor: pointer;
            transition: all 0.25s ease;
            box-shadow: 0 14px 26px rgba(37,99,235,0.25);
        }

        .login-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 18px 34px rgba(37,99,235,0.30);
            filter: brightness(1.02);
        }

        .login-btn:active {
            transform: translateY(0);
        }

        .divider {
            position: relative;
            text-align: center;
            margin: 22px 0;
        }

        .divider::before {
            content: "";
            position: absolute;
            left: 0;
            right: 0;
            top: 50%;
            height: 1px;
            background: #e2e8f0;
        }

        .divider span {
            position: relative;
            z-index: 1;
            background: rgba(255,255,255,0.96);
            padding: 0 14px;
            color: #94a3b8;
            font-size: 13px;
            font-weight: 700;
        }

        .security-note {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 16px;
            border-radius: 18px;
            background: linear-gradient(180deg, #f8fafc, #ffffff);
            border: 1px solid #e2e8f0;
        }

        .security-note .icon {
            width: 40px;
            height: 40px;
            flex-shrink: 0;
            border-radius: 14px;
            display: grid;
            place-items: center;
            background: rgba(37,99,235,0.10);
            color: #1d4ed8;
            font-size: 18px;
            font-weight: 900;
        }

        .security-note h4 {
            margin: 0 0 4px;
            font-size: 14px;
            color: #0f172a;
        }

        .security-note p {
            margin: 0;
            color: #64748b;
            line-height: 1.7;
            font-size: 13px;
        }

        .form-footer {
            margin-top: 18px;
            text-align: center;
            color: #64748b;
            font-size: 13px;
            font-weight: 600;
        }

        .form-footer strong {
            color: #0f172a;
        }

        @media (max-width: 1100px) {
            .login-shell {
                grid-template-columns: 1fr;
                min-height: auto;
            }

            .login-left {
                padding: 36px 28px;
            }

            .login-right {
                padding: 34px 22px;
            }

            .hero-stats {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 640px) {
            .login-page {
                padding: 16px;
            }

            .login-left,
            .login-right {
                padding: 24px 18px;
            }

            .login-card {
                padding: 22px 18px;
                border-radius: 22px;
            }

            .form-head h2 {
                font-size: 30px;
            }

            .login-left h1 {
                font-size: 34px;
            }

            .hero-footer {
                font-size: 13px;
            }
        }
    </style>
</head>
<body>
    <div class="bg-orb orb-1"></div>
    <div class="bg-orb orb-2"></div>
    <div class="bg-orb orb-3"></div>

    <div class="login-page">
        <div class="login-shell">
            <section class="login-left">
                <div class="brand-top">
                    <div class="brand-badge">AG</div>

                    <div class="hero-kicker">✨ Smart • Clean • Secure</div>

                    <h1>Automatic<br>Exam Generator</h1>

                    <p class="hero-text">
                        A modern programming exam system designed to make question creation,
                        AI-assisted generation, exam building, and review much faster and more professional.
                    </p>

                    <div class="hero-stats">
                        <div class="hero-stat">
                            <div class="num">6+</div>
                            <div class="label">Supported question types for programming exams</div>
                        </div>
                        <div class="hero-stat">
                            <div class="num">AI</div>
                            <div class="label">Reusable question generation workflow</div>
                        </div>
                        <div class="hero-stat">
                            <div class="num">100%</div>
                            <div class="label">Focused workflow for cleaner exam management</div>
                        </div>
                    </div>

                    <div class="feature-grid">
                        <div class="feature-card">
                            <div class="feature-icon">📝</div>
                            <div class="feature-content">
                                <h4>Create Exams Easily</h4>
                                <p>Build exams manually, define language and topic, and control every question clearly.</p>
                            </div>
                        </div>

                        <div class="feature-card">
                            <div class="feature-icon">🤖</div>
                            <div class="feature-content">
                                <h4>AI Generation Workflow</h4>
                                <p>Generate, review, edit, approve, and save questions directly into the bank.</p>
                            </div>
                        </div>

                        <div class="feature-card">
                            <div class="feature-icon">🗂️</div>
                            <div class="feature-content">
                                <h4>Question Bank Control</h4>
                                <p>Organize reusable questions and build exams from approved items only.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="hero-footer">
                    <span>Programming Exam System</span>
                    <span>Elegant Login Experience</span>
                </div>
            </section>

            <section class="login-right">
                <div class="form-shell">
                    <div class="form-head">
                        <div class="mini-badge">🔐 Secure Sign In</div>
                        <h2>Welcome Back</h2>
                        <p>
                            Enter your account details to access the system dashboard and continue your work.
                        </p>
                    </div>

                    <?php if ($timedOut): ?>
                        <div class="alert alert-warning">
                            Your session expired due to inactivity. Please sign in again.
                        </div>
                    <?php endif; ?>

                    <?php if ($errorMessage !== ''): ?>
                        <div class="alert alert-danger">
                            <?= e($errorMessage) ?>
                        </div>
                    <?php endif; ?>

                    <div class="login-card">
                        <form method="POST" action="login_process.php" autocomplete="off">
                            <div class="form-group">
                                <label class="form-label">Email Address</label>
                                <div class="input-wrap">
                                    <span class="input-icon">✉️</span>
                                    <input
                                        type="email"
                                        name="email"
                                        class="form-input"
                                        value="<?= e($oldEmail) ?>"
                                        placeholder="Enter your email address"
                                        required
                                    >
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Password</label>
                                <div class="input-wrap">
                                    <span class="input-icon">🔒</span>
                                    <input
                                        type="password"
                                        name="password"
                                        id="passwordField"
                                        class="form-input"
                                        placeholder="Enter your password"
                                        required
                                    >
                                    <button type="button" class="password-toggle" id="togglePassword">
                                        Show
                                    </button>
                                </div>
                            </div>

                            <div class="login-meta">
                                <label class="remember-box">
                                    <input type="checkbox" checked disabled>
                                    <span>Secure session enabled</span>
                                </label>

                                <a href="#" class="small-link" onclick="return false;">Staff Access</a>
                            </div>

                            <button type="submit" class="login-btn">
                                Sign In to Dashboard
                            </button>
                        </form>

                        <div class="divider">
                            <span>System Access</span>
                        </div>

                        <div class="security-note">
                            <div class="icon">🛡️</div>
                            <div>
                                <h4>Protected Login Session</h4>
                                <p>
                                    Your access is protected with session validation and automatic timeout for inactive users.
                                </p>
                            </div>
                        </div>

                        <div class="form-footer">
                            Automatic Exam Generator • <strong>Professional Login Portal</strong>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>

    <script>
        const passwordField = document.getElementById('passwordField');
        const togglePassword = document.getElementById('togglePassword');

        togglePassword.addEventListener('click', function () {
            const isPassword = passwordField.getAttribute('type') === 'password';
            passwordField.setAttribute('type', isPassword ? 'text' : 'password');
            togglePassword.textContent = isPassword ? 'Hide' : 'Show';
        });

        const loginShell = document.querySelector('.login-shell');

        document.addEventListener('mousemove', function (e) {
            if (window.innerWidth <= 1100) return;

            const x = (window.innerWidth / 2 - e.clientX) / 70;
            const y = (window.innerHeight / 2 - e.clientY) / 70;

            loginShell.style.transform = `perspective(1400px) rotateY(${-x * 0.6}deg) rotateX(${y * 0.35}deg)`;
            loginShell.style.transition = 'transform 0.12s ease';
        });

        document.addEventListener('mouseleave', function () {
            loginShell.style.transform = 'perspective(1400px) rotateY(0deg) rotateX(0deg)';
            loginShell.style.transition = 'transform 0.35s ease';
        });
    </script>
</body>
</html>