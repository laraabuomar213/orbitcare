<?php
// /api/auth/* — sign-up (2 steps), login, logout, session, password change.
declare(strict_types=1);

// Login: used when the email doesn't exist, so a wrong email and a wrong password take the same time.
const DUMMY_PASSWORD_HASH = '$2y$10$gaqOF6mBPQjbyllFPqJagezsgWlhJoNFhINFgNgtcf1DX5wXbHcIy';

return function (Router $router): void {

    $router->get('/auth/session', function (array $params, array $body, ?array $user): array {
        return [
            'authenticated' => $user !== null,
            'session_expired' => $user === null && !empty($_SESSION['session_expired']),
            'user' => $user ? [
                'id' => (int) $user['id'], 'name' => $user['full_name'], 'role' => $user['role'],
                'profile_complete' => $user['profile_completed_at'] !== null,
                'home' => home_path_for($user),
            ] : null,
            'csrf_token' => csrf_token(),
        ];
    }, ['auth' => false]);

    // Step 1: create the account and sign in.
    $router->post('/auth/signup', function (array $params, array $body, ?array $user): array {
        if ($user !== null) {
            throw new ApiException(409, 'already_signed_in', t('err.already_signed_in'));
        }

        $fullName = clean_text($body['full_name'] ?? '');
        $email = strtolower(clean_text($body['email'] ?? ''));
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';
        $confirm = is_string($body['confirm_password'] ?? null) ? $body['confirm_password'] : '';
        $role = $body['role'] ?? '';

        $errors = [];
        if (!in_array($role, ['astronaut', 'mission_admin'], true)) {
            $errors['role'] = t('err.choose_role');
        }
        if ($fullName === '') {
            $errors['full_name'] = t('err.full_name');
        } elseif (mb_strlen($fullName) > 120) {
            $errors['full_name'] = t('err.full_name_long');
        }
        if ($email === '') {
            $errors['email'] = t('err.email');
        } elseif (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = t('err.email_invalid');
        }
        if (($problem = password_problem($password)) !== null) {
            $errors['password'] = $problem;
        }
        if ($confirm === '') {
            $errors['confirm_password'] = t('err.confirm_password');
        } elseif (!hash_equals($password, $confirm)) {
            $errors['confirm_password'] = t('err.password_mismatch');
        }
        if ($errors) {
            throw ApiException::validation($errors);
        }

        // Choosing "mission_admin" only records a request: admin rights also need approval.
        try {
            db()->prepare('INSERT INTO users (full_name, email, password_hash, role) VALUES (?, ?, ?, ?)')
                ->execute([$fullName, $email, password_hash($password, PASSWORD_DEFAULT), $role]);
        } catch (PDOException $error) {
            if (($error->errorInfo[1] ?? null) === 1062) {
                throw new ApiException(409, 'email_taken', t('err.fix_fields'), ['email' => t('err.email_taken')]);
            }
            throw $error;
        }

        $userId = (int) db()->lastInsertId();
        log_in_user($userId);
        audit('auth.signup', $userId, 'user', $userId, ['role' => $role]);
        log_event('auth.signup', ['user_id' => $userId, 'role' => $role, 'status' => 'success']);

        return ['role' => $role, 'next' => 'profile'];
    }, ['auth' => false, 'rate' => ['signup', 10, 600]]);

    // Step 2: role-specific profile. Missions are not chosen here: an approved admin creates their
    // own missions and adds astronauts to the crew (MissionService::addAstronaut).
    $router->post('/auth/profile', function (array $params, array $body, array $user): array {
        if ($user['profile_completed_at'] !== null) {
            return ['redirect' => home_path_for($user)];
        }

        $errors = [];

        if ($user['role'] === 'astronaut') {
            $profile = validate_astronaut_profile($body, $errors);
            if ($errors) {
                throw ApiException::validation($errors, t('err.complete_fields'));
            }

            try {
                db_transaction(function (PDO $pdo) use ($user, $profile) {
                    $pool = config()['replay']['record_pool'];
                    $assigned = (int) $pdo->query('SELECT COUNT(*) FROM astronauts WHERE data_source_record_id IS NOT NULL')->fetchColumn();
                    $recordId = $pool[$assigned % count($pool)];

                    $pdo->prepare('INSERT INTO astronauts (user_id, astronaut_code, age, height_cm, weight_kg, data_source_record_id) VALUES (?, ?, ?, ?, ?, ?)')
                        ->execute([$user['id'], $profile['astronaut_code'], $profile['age'], $profile['height_cm'], $profile['weight_kg'], $recordId]);
                    $pdo->prepare(
                        'INSERT INTO astronaut_medical_background
                            (user_id, has_chronic_conditions, chronic_conditions, takes_medications, has_allergies, allergies, medical_history_notes)
                         VALUES (?, ?, ?, ?, ?, ?, ?)'
                    )->execute([
                        $user['id'], (int) $profile['has_chronic_conditions'], $profile['chronic_conditions'] ?: null,
                        (int) $profile['takes_medications'], (int) $profile['has_allergies'], $profile['allergies'] ?: null,
                        $profile['medical_history_notes'] ?: null,
                    ]);
                    $insertMedication = $pdo->prepare('INSERT INTO astronaut_medications (user_id, name, purpose) VALUES (?, ?, ?)');
                    foreach ($profile['medications'] as [$name, $purpose]) {
                        $insertMedication->execute([$user['id'], $name, $purpose]);
                    }
                    $pdo->prepare('UPDATE users SET profile_completed_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$user['id']]);
                });
            } catch (PDOException $error) {
                if (($error->errorInfo[1] ?? null) === 1062) {
                    throw ApiException::validation(['astronaut_code' => t('err.code_taken')]);
                }
                throw $error;
            }
        } else {
            $jobTitle = clean_text($body['job_title'] ?? '');
            $organization = clean_text($body['organization'] ?? '');
            if ($jobTitle === '' || mb_strlen($jobTitle) > 120) {
                $errors['job_title'] = $jobTitle === '' ? t('err.job_title') : t('err.job_title_long');
            }
            if ($organization === '' || mb_strlen($organization) > 150) {
                $errors['organization'] = $organization === '' ? t('err.organization') : t('err.organization_long');
            }
            if ($errors) {
                throw ApiException::validation($errors);
            }

            // Admin rights start after approval (cli/approve-admin.php); then the admin creates missions.
            db_transaction(function (PDO $pdo) use ($user, $jobTitle, $organization) {
                $pdo->prepare('INSERT INTO admin_profiles (user_id, job_title, organization) VALUES (?, ?, ?)')
                    ->execute([$user['id'], $jobTitle, $organization]);
                $pdo->prepare('UPDATE users SET profile_completed_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$user['id']]);
            });
        }

        audit('profile.complete', (int) $user['id'], 'user', $user['id']);
        $user = current_user(true);

        return [
            'redirect' => home_path_for($user),
            'role' => $user['role'],
            'pending_approval' => $user['role'] === 'mission_admin' && !is_approved_mission_admin($user),
        ];
    });

    $router->post('/auth/login', function (array $params, array $body): array {
        $email = strtolower(clean_text($body['email'] ?? ''));
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';

        if ($email === '' || $password === '') {
            throw new ApiException(422, 'validation_failed', t('err.login_missing'));
        }

        $statement = db()->prepare('SELECT id, password_hash FROM users WHERE email = ?');
        $statement->execute([$email]);
        $account = $statement->fetch();
        $passwordMatches = password_verify($password, $account['password_hash'] ?? DUMMY_PASSWORD_HASH);

        if ($account === false || !$passwordMatches) {
            log_event('auth.login', ['status' => 'failed'], 'warning');
            audit('auth.login_failed', $account ? (int) $account['id'] : null, 'user', $account['id'] ?? null);
            throw new ApiException(401, 'invalid_credentials', t('err.invalid_credentials'));
        }

        if (password_needs_rehash($account['password_hash'], PASSWORD_DEFAULT)) {
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $account['id']]);
        }
        db()->prepare('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$account['id']]);

        log_in_user((int) $account['id']);
        apply_saved_language(load_user_settings((int) $account['id'])['display']['language'] ?? null);
        audit('auth.login', (int) $account['id'], 'user', $account['id']);
        log_event('auth.login', ['user_id' => (int) $account['id'], 'status' => 'success']);

        return ['redirect' => home_path_for(current_user(true))];
    }, ['auth' => false, 'rate' => ['login', 10, 300]]);

    // Works for fetch() (JSON) and for the plain Log Out form (redirects to the landing page).
    $router->post('/auth/logout', function (array $params, array $body, ?array $user): array {
        if ($user !== null) {
            audit('auth.logout', (int) $user['id'], 'user', $user['id']);
            log_event('auth.logout', ['user_id' => (int) $user['id'], 'status' => 'success']);
        }
        log_out_user();

        if (!str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
            redirect(url('index.php?logged_out=1'));
        }
        return ['redirect' => url('index.php?logged_out=1')];
    }, ['auth' => false]);

    $router->post('/auth/password', function (array $params, array $body, array $user): array {
        $current = is_string($body['current_password'] ?? null) ? $body['current_password'] : '';
        $new = is_string($body['new_password'] ?? null) ? $body['new_password'] : '';

        $statement = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
        $statement->execute([$user['id']]);
        if (!password_verify($current, (string) $statement->fetchColumn())) {
            throw ApiException::validation(['current_password' => t('err.current_password')]);
        }
        if (($problem = password_problem($new)) !== null) {
            throw ApiException::validation(['new_password' => $problem]);
        }

        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
        session_regenerate_id(true);
        audit('auth.password_change', (int) $user['id'], 'user', $user['id']);
        return ['changed' => true];
    }, ['rate' => ['password', 5, 600]]);
};

// Validates the astronaut part of sign-up step 2. Adds problems to $errors.
function validate_astronaut_profile(array $body, array &$errors): array
{
    $code = clean_text($body['astronaut_code'] ?? '');
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{1,39}$/', $code)) {
        $errors['astronaut_code'] = t('err.astronaut_code');
    }

    $number = function (string $field, float $min, float $max, string $label) use ($body, &$errors): ?float {
        $raw = $body[$field] ?? '';
        if (!is_numeric($raw) || (float) $raw < $min || (float) $raw > $max) {
            $errors[$field] = t('err.number_range', ['label' => t($label), 'min' => $min, 'max' => $max]);
            return null;
        }
        return round((float) $raw, 1);
    };
    $age = $number('age', 18, 80, 'field.age');
    $height = $number('height_cm', 120, 230, 'field.height_cm');
    $weight = $number('weight_kg', 35, 200, 'field.weight_kg');

    $yesNo = function (string $field) use ($body, &$errors): ?bool {
        $answer = $body[$field] ?? '';
        if ($answer === 'yes' || $answer === 'no') {
            return $answer === 'yes';
        }
        $errors[$field] = t('err.yes_no');
        return null;
    };

    $hasConditions = $yesNo('has_chronic_conditions');
    $conditions = $hasConditions ? clean_multiline($body['chronic_conditions'] ?? '') : '';
    if ($hasConditions && $conditions === '') {
        $errors['chronic_conditions'] = t('err.conditions');
    } elseif (mb_strlen($conditions) > 1000) {
        $errors['chronic_conditions'] = t('err.max_1000');
    }

    $takesMedications = $yesNo('takes_medications');
    $medications = [];
    if ($takesMedications) {
        $names = is_array($body['medication_name'] ?? null) ? $body['medication_name'] : [];
        $purposes = is_array($body['medication_purpose'] ?? null) ? $body['medication_purpose'] : [];
        foreach ($names as $index => $name) {
            $name = clean_text($name);
            $purpose = clean_text($purposes[$index] ?? '');
            if ($name === '' && $purpose === '') {
                continue;
            }
            if ($name === '' || $purpose === '' || mb_strlen($name) > 150 || mb_strlen($purpose) > 255) {
                $errors['medications'] = t('err.medication_pair');
                break;
            }
            $medications[] = [$name, $purpose];
        }
        if (!isset($errors['medications']) && ($medications === [] || count($medications) > 10)) {
            $errors['medications'] = $medications === [] ? t('err.medication_none') : t('err.medication_many');
        }
    }

    $hasAllergies = $yesNo('has_allergies');
    $allergies = $hasAllergies ? clean_multiline($body['allergies'] ?? '') : '';
    if ($hasAllergies && $allergies === '') {
        $errors['allergies'] = t('err.allergies');
    } elseif (mb_strlen($allergies) > 1000) {
        $errors['allergies'] = t('err.max_1000');
    }

    $notes = clean_multiline($body['medical_history_notes'] ?? '');
    if (mb_strlen($notes) > 2000) {
        $errors['medical_history_notes'] = t('err.max_2000');
    }

    return [
        'astronaut_code' => $code, 'age' => $age, 'height_cm' => $height, 'weight_kg' => $weight,
        'has_chronic_conditions' => $hasConditions, 'chronic_conditions' => $conditions,
        'takes_medications' => $takesMedications, 'medications' => $medications,
        'has_allergies' => $hasAllergies, 'allergies' => $allergies, 'medical_history_notes' => $notes,
    ];
}
