<?php
// Include the database connection and URL helper
require 'config.php';
require_once 'url_helper.php';

// Start the session if it hasn't been started yet
// This is needed for login processing before header.php is included
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Process the login when the form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    
    // Query the database for a user with the provided username
    $stmt = $pdo->prepare("SELECT * FROM tblUser WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    
    // For development: check plain text password (later replace with hashed password verification)
    if ($user && $password === $user['password']) {
        // Save user details in session for later use
        $_SESSION['userID']   = $user['userID'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['admin']    = $user['admin']; // 1 means admin, 0 means regular user
        
        // Redirect to the landing page after successful login
        redirect('index.php');
    } else {
        $error = "Invalid username or password.";
    }
}

// Set this variable BEFORE including the header to hide user menu
$isLoginPage = true;
// Set this variable to true to display the "Bids and Awards" title
$showTitleRight = true; 
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Login - DepEd BAC Tracking System</title>
  <link rel="stylesheet" href="assets/css/Login.css">
  <!-- Include header.php for styles and layout -->
  <?php include 'header.php'; ?>
</head>
<body class="home-bg">
  
  <div class="login-flex-wrapper">
    <div class="login-container">
      <div class="login-box">
        <img src="assets/images/DepEd_Name_Logo.png" alt="DepEd" class="login-logo">
        <!-- Display error messages from PHP -->
        <?php if (isset($error)): ?>
          <p style="color:red; font-weight:bold;"><?php echo htmlspecialchars($error); ?></p>
        <?php endif; ?>
        <!-- Form updated to use POST and include name attributes -->
        <form id="loginForm" action="login.php" method="post">
          <label for="username">Username</label>
          <input type="text" id="username" name="username" placeholder="Enter your username" required>

          <label for="password">Password</label>
          <input type="password" id="password" name="password" placeholder="Enter your password" required>

          <button type="submit">Sign In</button>
        </form>
      </div>
    </div>
    <img src="assets/images/DepEd_Logo.png" alt="DepEd Logo" class="side-logo-login">
  </div>

  <!-- Server-side authentication is used instead of client-side login.js -->
</body>
</html>