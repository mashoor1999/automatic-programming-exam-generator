<?php
declare(strict_types=1);

require_once __DIR__ . '/session_check.php';
require_once __DIR__ . '/Data/db.php';

if (strtolower((string)($_SESSION['role'] ?? '')) !== 'admin') {
    header('Location: dashboard.php');
    exit;
}

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function selectedValue(string $current, string $expected): string
{
    return $current === $expected ? 'selected' : '';
}

$errors = [];
$success = '';

$currentAdminId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);

$formData = [
    'first_name' => '',
    'last_name'  => '',
    'email'      => '',
    'role'       => 'teacher',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));

    /*
    |--------------------------------------------------------------------------
    | Add New User
    |--------------------------------------------------------------------------
    */
    if ($action === 'add_user') {
        $formData['first_name'] = trim((string)($_POST['first_name'] ?? ''));
        $formData['last_name']  = trim((string)($_POST['last_name'] ?? ''));
        $formData['email']      = trim((string)($_POST['email'] ?? ''));
        $formData['role']       = trim((string)($_POST['role'] ?? 'teacher'));
        $password               = trim((string)($_POST['password'] ?? ''));

        if ($formData['first_name'] === '') {
            $errors[] = 'First name is required.';
        }

        if ($formData['last_name'] === '') {
            $errors[] = 'Last name is required.';
        }

        if ($formData['email'] === '') {
            $errors[] = 'Email is required.';
        } elseif (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }

        if (!in_array($formData['role'], ['admin', 'teacher'], true)) {
            $errors[] = 'Invalid role selected.';
        }

        if ($password === '') {
            $errors[] = 'Password is required.';
        }

        if (empty($errors)) {
            try {
                $fullName = trim($formData['first_name'] . ' ' . $formData['last_name']);
                $pepper = defined('PASS_PEPPER') ? PASS_PEPPER : '';
                $hashedPassword = md5($pepper . $password);

                $stmt = $pdo->prepare("
                    INSERT INTO users (
                        first_name,
                        last_name,
                        full_name,
                        email,
                        password,
                        role,
                        created_at,
                        updated_at
                    ) VALUES (
                        :first_name,
                        :last_name,
                        :full_name,
                        :email,
                        :password,
                        :role,
                        NOW(),
                        NOW()
                    )
                ");

                $stmt->execute([
                    ':first_name' => $formData['first_name'],
                    ':last_name'  => $formData['last_name'],
                    ':full_name'  => $fullName,
                    ':email'      => $formData['email'],
                    ':password'   => $hashedPassword,
                    ':role'       => $formData['role'],
                ]);

                $success = 'Account created successfully.';

                $formData = [
                    'first_name' => '',
                    'last_name'  => '',
                    'email'      => '',
                    'role'       => 'teacher',
                ];
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $errors[] = 'This email already exists.';
                } else {
                    $errors[] = 'Save failed: ' . $e->getMessage();
                }
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Reset Password
    |--------------------------------------------------------------------------
    */
    if ($action === 'reset_password') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $newPassword = trim((string)($_POST['new_password'] ?? ''));

        if ($userId <= 0) {
            $errors[] = 'Invalid user ID.';
        }

        if ($newPassword === '') {
            $errors[] = 'New password is required.';
        }

        if (strlen($newPassword) < 4) {
            $errors[] = 'Password must be at least 4 characters.';
        }

        if (empty($errors)) {
            try {
                $pepper = defined('PASS_PEPPER') ? PASS_PEPPER : '';
                $hashedPassword = md5($pepper . $newPassword);

                $stmt = $pdo->prepare("
                    UPDATE users
                    SET password = :password,
                        updated_at = NOW()
                    WHERE id = :id
                    LIMIT 1
                ");

                $stmt->execute([
                    ':password' => $hashedPassword,
                    ':id'       => $userId,
                ]);

                $success = 'Password has been reset successfully.';
            } catch (Throwable $e) {
                $errors[] = 'Password reset failed: ' . $e->getMessage();
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Remove Account
    |--------------------------------------------------------------------------
    */
    if ($action === 'delete_user') {
        $userId = (int)($_POST['user_id'] ?? 0);

        if ($userId <= 0) {
            $errors[] = 'Invalid user ID.';
        }

        if ($userId === $currentAdminId) {
            $errors[] = 'You cannot remove your own account while logged in.';
        }

        if (empty($errors)) {
            try {
                $pdo->beginTransaction();

                $userStmt = $pdo->prepare("SELECT role FROM users WHERE id = :id LIMIT 1");
                $userStmt->execute([':id' => $userId]);
                $targetUser = $userStmt->fetch(PDO::FETCH_ASSOC);

                if (!$targetUser) {
                    throw new RuntimeException('Account not found.');
                }

                if (strtolower((string)$targetUser['role']) === 'admin') {
                    $adminCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();

                    if ($adminCount <= 1) {
                        throw new RuntimeException('You cannot remove the last admin account.');
                    }
                }

                /*
                 | Preserve project data:
                 | Reassign exams and questions created by the removed user
                 | to the current admin before deleting the account.
                */
                $reassignExams = $pdo->prepare("
                    UPDATE exams
                    SET user_id = :admin_id
                    WHERE user_id = :user_id
                ");
                $reassignExams->execute([
                    ':admin_id' => $currentAdminId,
                    ':user_id'  => $userId,
                ]);

                $reassignQuestions = $pdo->prepare("
                    UPDATE question_bank
                    SET created_by = :admin_id
                    WHERE created_by = :user_id
                ");
                $reassignQuestions->execute([
                    ':admin_id' => $currentAdminId,
                    ':user_id'  => $userId,
                ]);

                $deleteStmt = $pdo->prepare("
                    DELETE FROM users
                    WHERE id = :id
                    LIMIT 1
                ");
                $deleteStmt->execute([':id' => $userId]);

                $pdo->commit();

                $success = 'Account removed successfully.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $errors[] = 'Remove failed: ' . $e->getMessage();
            }
        }
    }
}

$stmt = $pdo->query("
    SELECT
        id,
        first_name,
        last_name,
        full_name,
        email,
        role,
        created_at,
        updated_at
    FROM users
    ORDER BY id DESC
");

$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle   = 'Accounts';
$currentPage = 'accounts';
$basePath    = '';

$pageStyles = <<<CSS
.accounts-grid {
    display: grid;
    grid-template-columns: 0.9fr 1.1fr;
    gap: 22px;
    align-items: start;
}

.role-pill {
    display: inline-flex;
    align-items: center;
    padding: 7px 12px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 800;
}

.role-admin {
    background: var(--danger-light);
    color: #991b1b;
}

.role-teacher {
    background: var(--primary-light);
    color: var(--primary-dark);
}

.account-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.modal-backdrop-custom {
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

.modal-backdrop-custom.show {
    display: flex;
}

.reset-modal {
    width: min(440px, 100%);
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 24px;
    box-shadow: var(--shadow-lg);
    padding: 24px;
}

.reset-modal h3 {
    margin-bottom: 8px;
}

.reset-modal p {
    margin-bottom: 18px;
    color: var(--text-muted);
}

.modal-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    flex-wrap: wrap;
    margin-top: 18px;
}

@media (max-width: 1100px) {
    .accounts-grid {
        grid-template-columns: 1fr;
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
            </div>
        <?php endif; ?>

        <section class="hero-banner mt-4">
            <h1 class="page-title">Accounts Management</h1>
            <p class="page-subtitle text-white">
                Manage system users, reset passwords, and remove accounts safely.
            </p>
        </section>

        <div class="accounts-grid">
            <div class="form-card">
                <div class="section-title">
                    <h2 class="mb-0">Add New Account</h2>
                </div>

                <p class="section-subtitle">
                    Create a new user account and assign the suitable role.
                </p>

                <form method="POST">
                    <input type="hidden" name="action" value="add_user">

                    <div class="form-group">
                        <label class="form-label">First Name <span class="required">*</span></label>
                        <input
                            type="text"
                            name="first_name"
                            value="<?= e($formData['first_name']) ?>"
                            placeholder="e.g. Ahmad"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label class="form-label">Last Name <span class="required">*</span></label>
                        <input
                            type="text"
                            name="last_name"
                            value="<?= e($formData['last_name']) ?>"
                            placeholder="e.g. Ali"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label class="form-label">Email <span class="required">*</span></label>
                        <input
                            type="email"
                            name="email"
                            value="<?= e($formData['email']) ?>"
                            placeholder="example@email.com"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label class="form-label">Password <span class="required">*</span></label>
                        <input
                            type="password"
                            name="password"
                            placeholder="Enter password"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label class="form-label">Role <span class="required">*</span></label>
                        <select name="role" required>
                            <option value="teacher" <?= selectedValue($formData['role'], 'teacher') ?>>Teacher</option>
                            <option value="admin" <?= selectedValue($formData['role'], 'admin') ?>>Admin</option>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-primary btn-block">
                        Create Account
                    </button>
                </form>
            </div>

            <div class="table-card">
                <div class="table-header">
                    <h3 class="table-title">System Accounts</h3>
                    <p class="table-subtitle"><?= count($users) ?> account(s) found.</p>
                </div>

                <div class="table-responsive">
                    <table class="custom-table table-striped">
                        <thead>
                            <tr>
                                <th>#ID</th>
                                <th>Full Name</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Created At</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($users)): ?>
                                <tr>
                                    <td colspan="6" class="table-empty">No accounts found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($users as $user): ?>
                                    <?php
                                        $role = strtolower((string)$user['role']);
                                        $roleClass = $role === 'admin' ? 'role-admin' : 'role-teacher';
                                        $fullName = $user['full_name'] ?: trim((string)$user['first_name'] . ' ' . (string)$user['last_name']);
                                    ?>
                                    <tr>
                                        <td><strong>#<?= (int)$user['id'] ?></strong></td>
                                        <td><?= e($fullName) ?></td>
                                        <td><?= e($user['email']) ?></td>
                                        <td>
                                            <span class="role-pill <?= $roleClass ?>">
                                                <?= e(ucfirst($role)) ?>
                                            </span>
                                        </td>
                                        <td><?= e($user['created_at'] ?? '-') ?></td>
                                        <td>
                                            <div class="account-actions">
                                                <button
                                                    type="button"
                                                    class="btn btn-info btn-sm"
                                                    onclick="openResetModal(<?= (int)$user['id'] ?>, '<?= e($fullName) ?>')"
                                                >
                                                    Reset
                                                </button>

                                                <?php if ((int)$user['id'] !== $currentAdminId): ?>
                                                    <form method="POST" onsubmit="return confirm('Are you sure you want to remove this account?');">
                                                        <input type="hidden" name="action" value="delete_user">
                                                        <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
                                                        <button type="submit" class="btn btn-danger btn-sm">
                                                            Remove
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <span class="badge badge-dark">Current</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</main>

<div class="modal-backdrop-custom" id="resetModal">
    <div class="reset-modal">
        <h3>Reset Password</h3>
        <p id="resetUserText">Enter a new password for this account.</p>

        <form method="POST">
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="user_id" id="resetUserId">

            <div class="form-group">
                <label class="form-label">New Password <span class="required">*</span></label>
                <input
                    type="password"
                    name="new_password"
                    placeholder="Enter new password"
                    required
                >
            </div>

            <div class="modal-actions">
                <button type="button" class="btn btn-light" onclick="closeResetModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Update Password</button>
            </div>
        </form>
    </div>
</div>

<script>
function openResetModal(userId, fullName) {
    document.getElementById('resetUserId').value = userId;
    document.getElementById('resetUserText').textContent = 'Enter a new password for: ' + fullName;
    document.getElementById('resetModal').classList.add('show');
}

function closeResetModal() {
    document.getElementById('resetModal').classList.remove('show');
}

document.getElementById('resetModal').addEventListener('click', function (event) {
    if (event.target === this) {
        closeResetModal();
    }
});
</script>

<?php require_once __DIR__ . '/include/footer.php'; ?>