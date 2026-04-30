<?php
session_start();

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/utils/logger.php';

$action = $_GET['action'] ?? '';
$database = new Database();
$db = $database->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'signup') {
        $fname = trim($_POST['fname'] ?? '');
        $lname = trim($_POST['lname'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($fname) || empty($lname) || empty($email) || empty($password)) {
            $_SESSION['error'] = 'All fields are required for sign up.';
            header('Location: ../views/feed.php?action=signup');
            exit;
        }

        try {
            // Check if email already exists
            $stmt = $db->prepare("SELECT user_id FROM users WHERE email = :email");
            $stmt->execute(['email' => $email]);
            if ($stmt->fetch()) {
                $_SESSION['error'] = 'Email is already registered.';
                header('Location: ../views/feed.php?action=signup');
                exit;
            }

            // Securely hash password
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

            // Insert new user
            $insertQuery = "INSERT INTO users (fname, lname, email, password) VALUES (:fname, :lname, :email, :password)";
            $stmt = $db->prepare($insertQuery);
            $stmt->execute([
                'fname' => $fname,
                'lname' => $lname,
                'email' => $email,
                'password' => $hashedPassword
            ]);

            $_SESSION['user_id'] = $db->lastInsertId();
            $_SESSION['user_fname'] = $fname;
            $_SESSION['user_position'] = 'user'; // Default position
            $_SESSION['success'] = 'Account created successfully!';

            // Log the login
            logLogin($db, $_SESSION['user_id']);

            // Redirect to feed/dashboard
            header('Location: ../views/feed.php');
            exit;

        } catch (PDOException $e) {
            $_SESSION['error'] = 'Registration failed. Please try again.';
            // Log error internally if needed: error_log($e->getMessage());
            header('Location: ../views/feed.php?action=signup');
            exit;
        }
    } elseif ($action === 'login') {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
            $_SESSION['error'] = 'Email and password are required.';
            header('Location: ../views/feed.php?action=login');
            exit;
        }

        try {
            $stmt = $db->prepare("SELECT user_id, fname, password, position FROM users WHERE email = :email LIMIT 1");
            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password'])) {
                // Successful login
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['user_fname'] = $user['fname'];
                $_SESSION['user_position'] = $user['position'] ?? 'user';
                $_SESSION['success'] = 'Welcome back, ' . htmlspecialchars($user['fname']) . '!';

                // Log the login
                logLogin($db, $user['user_id']);

                header('Location: ../views/feed.php');
                exit;
            } else {
                $_SESSION['error'] = 'Invalid email or password.';
                header('Location: ../views/feed.php?action=login');
                exit;
            }
        } catch (PDOException $e) {
            $_SESSION['error'] = 'Login failed due to a system error. Please try again.';
            header('Location: ../views/feed.php?action=login');
            exit;
        }
    } elseif ($action === 'complete_profile') {
        if (!isset($_SESSION['user_id'])) {
            header('Location: ../views/feed.php?action=login');
            exit;
        }

        $birthdate = $_POST['birthdate'] ?? null;
        $gender = $_POST['gender'] ?? null;
        $province = trim($_POST['province'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $barangay = trim($_POST['barangay'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $contact_number = trim($_POST['contact_number'] ?? '');
        $division_id = $_POST['division_id'] ?? null;
        $office_id = $_POST['office_id'] ?? null;
        $position = trim($_POST['position'] ?? '');

        if (empty($birthdate) || empty($gender) || empty($province) || empty($city) || empty($barangay) || empty($address) || empty($contact_number) || empty($division_id) || empty($office_id) || empty($position)) {
            $_SESSION['error'] = 'All profile fields are required.';
            header('Location: ../views/feed.php');
            exit;
        }

        try {
            $updateQuery = "UPDATE users SET birthdate = :birthdate, gender = :gender, province = :province, city = :city, barangay = :barangay, address = :address, contact_number = :contact_number, division_id = :division_id, office_id = :office_id, position = :position WHERE user_id = :id";
            $stmt = $db->prepare($updateQuery);
            $stmt->execute([
                'birthdate' => $birthdate,
                'gender' => $gender,
                'province' => $province,
                'city' => $city,
                'barangay' => $barangay,
                'address' => $address,
                'contact_number' => $contact_number,
                'division_id' => $division_id,
                'office_id' => $office_id,
                'position' => $position,
                'id' => $_SESSION['user_id']
            ]);

            $_SESSION['user_position'] = $position;
            $_SESSION['success'] = 'Profile completed successfully!';
            header('Location: ../views/feed.php');
            exit;
        } catch (PDOException $e) {
            $_SESSION['error'] = 'Failed to update profile. Please try again.';
            header('Location: ../views/feed.php');
            exit;
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($action === 'logout') {
        session_destroy();
        header('Location: ../views/feed.php?action=login');
        exit;
    } elseif ($action === 'google_login') {
        $client_id = $_ENV['GOOGLE_CLIENT_ID'];
        $redirect_uri = "http://localhost/Quality-Assurance-System/api/auth.php?action=google_callback";
        
        $params = [
            'client_id' => $client_id,
            'redirect_uri' => $redirect_uri,
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/userinfo.profile https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/drive.file',
            'access_type' => 'offline',
            'prompt' => 'consent select_account'
        ];
        
        $url = "https://accounts.google.com/o/oauth2/v2/auth?" . http_build_query($params);
        header('Location: ' . $url);
        exit;
    } elseif ($action === 'google_callback') {
        $code = $_GET['code'] ?? null;
        if (!$code) {
            $_SESSION['error'] = 'Google authentication failed.';
            header('Location: ../views/feed.php?action=login');
            exit;
        }

        $client_id = $_ENV['GOOGLE_CLIENT_ID'];
        $client_secret = $_ENV['GOOGLE_CLIENT_SECRET'] ?? ''; // Ensure this is in .env
        $redirect_uri = "http://localhost/Quality-Assurance-System/api/auth.php?action=google_callback";

        // Exchange code for token
        $token_url = "https://oauth2.googleapis.com/token";
        $token_params = [
            'code' => $code,
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'redirect_uri' => $redirect_uri,
            'grant_type' => 'authorization_code'
        ];

        $ch = curl_init($token_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($token_params));
        $token_response = curl_exec($ch);
        curl_close($ch);

        $token_data = json_decode($token_response, true);
        if (!isset($token_data['access_token'])) {
            $_SESSION['error'] = 'Failed to retrieve access token from Google.';
            header('Location: ../views/feed.php?action=login');
            exit;
        }

        // Get user info
        $userinfo_url = "https://www.googleapis.com/oauth2/v2/userinfo?access_token=" . $token_data['access_token'];
        $userinfo_response = file_get_contents($userinfo_url);
        $user_data = json_decode($userinfo_response, true);

        if (!$user_data || !isset($user_data['email'])) {
            $_SESSION['error'] = 'Failed to retrieve user information from Google.';
            header('Location: ../views/feed.php?action=login');
            exit;
        }

        $email = $user_data['email'];
        $google_id = $user_data['id'];
        $fname = $user_data['given_name'] ?? 'Google';
        $lname = $user_data['family_name'] ?? 'User';

        try {
            // Check if user exists by google_id or email
            $stmt = $db->prepare("SELECT user_id, fname, position, google_id FROM users WHERE google_id = :gid OR email = :email LIMIT 1");
            $stmt->execute(['gid' => $google_id, 'email' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                // Update tokens and google_id
                $update = $db->prepare("UPDATE users SET google_id = :gid, google_access_token = :at, google_refresh_token = :rt WHERE user_id = :uid");
                $update->execute([
                    'gid' => $google_id,
                    'at' => $token_data['access_token'],
                    'rt' => $token_data['refresh_token'] ?? null,
                    'uid' => $user['user_id']
                ]);
                
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['user_fname'] = $user['fname'];
                $_SESSION['user_position'] = $user['position'] ?? 'user';
            } else {
                // Create new user
                $insert = $db->prepare("INSERT INTO users (google_id, email, fname, lname, password, position, google_access_token, google_refresh_token) VALUES (:gid, :email, :fname, :lname, :pass, :pos, :at, :rt)");
                $insert->execute([
                    'gid' => $google_id,
                    'email' => $email,
                    'fname' => $fname,
                    'lname' => $lname,
                    'pass' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
                    'pos' => 'QA Officer',
                    'at' => $token_data['access_token'],
                    'rt' => $token_data['refresh_token'] ?? null
                ]);
                
                $_SESSION['user_id'] = $db->lastInsertId();
                $_SESSION['user_fname'] = $fname;
                $_SESSION['user_position'] = 'QA Officer';
            }

            // Log the login
            logLogin($db, $_SESSION['user_id'], 'Google');

            header('Location: ../views/feed.php');
            exit;
        } catch (PDOException $e) {
            $_SESSION['error'] = 'Login failed due to a system error. ' . $e->getMessage();
            header('Location: ../views/feed.php?action=login');
            exit;
        }
    }
}

// Fallback if accessed without proper action
header('Location: ../views/feed.php');
exit;
