<?php
session_start();

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';

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
            $stmt = $db->prepare("SELECT id FROM users WHERE email = :email");
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
            $_SESSION['user_role'] = 'user';
            $_SESSION['success'] = 'Account created successfully!';
            
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
            $stmt = $db->prepare("SELECT id, fname, password, role FROM users WHERE email = :email LIMIT 1");
            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password'])) {
                // Successful login
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_fname'] = $user['fname'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION['success'] = 'Welcome back, ' . htmlspecialchars($user['fname']) . '!';
                
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
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($action === 'logout') {
        session_destroy();
        header('Location: ../views/feed.php?action=login');
        exit;
    }
}

// Fallback if accessed without proper action
header('Location: ../views/feed.php');
exit;
