<?php
// Start the session if it hasn't been started yet
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require 'config.php'; // Ensure this file exists and contains PDO connection

// Allow only admin users to create accounts.
if (!isset($_SESSION['username']) || $_SESSION['admin'] != 1) {
    redirect('index.php');
    exit();
}

$success = false;
$error = "";

// Fetch office names and IDs from the database for the dropdown
$officeList = [];
try {
    $stmtOffices = $pdo->query("SELECT officeID, officename FROM officeid ORDER BY officeID");
    while ($office = $stmtOffices->fetch()) {
        $officeList[$office['officeID']] = $office['officeID'] . ' - ' . $office['officename'];
    }
} catch (PDOException $e) {
    $error = "Error fetching office list: " . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Retrieve and trim the form values.
    $firstname   = trim($_POST['firstname']);
    $middlename  = trim($_POST['middlename'] ?? "");
    $lastname    = trim($_POST['lastname']);
    $position    = trim($_POST['position'] ?? "");
    $username    = trim($_POST['username']);
    $password    = trim($_POST['password']);  // Plain text for now (not recommended for production)
    $adminFlag   = isset($_POST['admin']) ? 1 : 0;
    $officeName  = trim($_POST['office']);      // Now comes from a select dropdown

    // Basic validation—check that required fields are filled.
    // Also check that a valid office was selected (not the empty default option)
    if(empty($firstname) || empty($lastname) || empty($username) || empty($password) || empty($officeName)){
       $error = "Please fill in all required fields.";
    } else {
        try {
            // Extract the office ID from the selected option (format: "1 - OSDS")
            $officeID = intval(explode(' - ', $officeName)[0]);
            
            // Verify the office ID exists
            $stmtOffice = $pdo->prepare("SELECT officeID FROM officeid WHERE officeID = ?");
            $stmtOffice->execute([$officeID]);
            $office = $stmtOffice->fetch();
            
            if (!$office) {
                // If somehow the office ID doesn't exist, use a default (1)
                $officeID = 1;
            }

            // Now insert the new user into tbluser.
            $stmtUser = $pdo->prepare("INSERT INTO tbluser (firstname, middlename, lastname, position, username, password, admin, officeID) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmtUser->execute([$firstname, $middlename, $lastname, $position, $username, $password, $adminFlag, $officeID]);

            $success = true;
            // Retrieve the newly created account details using the auto-generated userID.
            $newAccountID = $pdo->lastInsertId();
            $stmt2 = $pdo->prepare("SELECT u.*, o.officename FROM tbluser u LEFT JOIN officeid o ON u.officeID = o.officeID WHERE u.userID = ?");
            $stmt2->execute([$newAccountID]);
            $newAccount = $stmt2->fetch();

        } catch (PDOException $e) {
            $error = "Error creating account: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Create Account - DepEd BAC Tracking System</title>
  <link rel="stylesheet" href="assets/css/home.css">
  <link rel="stylesheet" href="assets/css/background.css">
  <style>

  .create-account-container { /* Used in Create.html and CreateSuccess.html */
      position: fixed;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      width: 100%;
      max-width: 520px;
      min-width: 320px;
      background: #fff;
      padding: 48px 48px 48px 48px;
      border-radius: 18px;
      box-shadow: 0 6px 32px rgba(0,0,0,0.13);
      text-align: center;
      z-index: 10;
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: center;
}

  #createAccountForm label { /* Used in Create.html */
      display: block;
      text-align: left;
      margin-bottom: 5px;
      font-size: 14px;
    }

  #createAccountForm input[type="text"], /* Used in Create.html */
  #createAccountForm input[type="password"], /* Used in Create.html */
  #createAccountForm select { /* Added for dropdown */
      width: 95%;
      max-width: 420px;
      min-width: 220px;
      padding: 14px;
      margin-bottom: 20px;
      border: 1.5px solid #ccc;
      border-radius: 8px;
      font-size: 17px;
      box-sizing: border-box;
    }

  #createAccountForm button { /* Used in Create.html */
      width: 100%;
      padding: 12px;
      border-radius: 20px;
      background: #0d47a1;
      color: #fff;
      border: none;
      font-weight: bold;
      font-size: 16px;
      cursor: pointer;
      transition: background 0.3s;
    }

  #createAccountForm button:hover { /* Used in Create.html */
      background: #1565c0;
    }

  #createAccountMsg { /* Used in Create.html */
      margin-top: 10px;
      font-size: 14px;
      color: #c62828;
    }



    /* Modal container styling */
    .modal {
      display: block; /* Always shown on this page */
      position: fixed;
      z-index: 1000;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      overflow: auto;
      background-color: rgba(0,0,0,0.4); /* Semi-transparent overlay */
    }
    /* Modal content styling */
    .modal-content {
      background-color:rgb(255, 255, 255);
      padding: 10px;
      border: 1px solid #888;
      width: 90%;
      max-width: 500px;
      border-radius: 8px;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.76);
      margin: 10vh auto; /* Center the modal vertically and horizontally */
    }
    .close {
      color: #aaa;
      float: right;
      font-size: 28px;
      font-weight: bold;
      cursor: pointer;
    }
    .close:hover {
      color: black;
    }
    form label {
      display: block;
      margin-top: 10px;
    }
    form input[type="text"],
    form input[type="password"],
    form select { /* Added for dropdown */
      width: 100%;
      padding: 8px;
      margin-top: 4px;
      box-sizing: border-box;
    }
    form button {
      margin-top: 15px;
      padding: 10px;
      width: 100%;
      border: none;
      background-color: #0d47a1;
      color: white;
      font-weight: bold;
      border-radius: 4px;
      cursor: pointer;
    }
  </style>
</head>
<body>
  <?php
    // Include your header.php file here.
    // This will insert the header HTML, its inline styles, and its inline JavaScript.
    include 'header.php';
    ?>

  <div class="modal">
    <div class="modal-content">
      <span class="close" onclick="window.location.href='<?php echo url('index.php'); ?>'">&times;</span>
      <h2>Create Account</h2>

      <?php if ($error != ""): ?>
         <p style="color:red;"><?php echo htmlspecialchars($error); ?></p>
      <?php endif; ?>

      <?php if (!$success): ?>
      <form action="create_account.php" method="post">
         <label for="firstname">First Name*</label>
         <input type="text" name="firstname" id="firstname" required>

         <label for="middlename">Middle Name</label>
         <input type="text" name="middlename" id="middlename">

         <label for="lastname">Last Name*</label>
         <input type="text" name="lastname" id="lastname" required>

         <label for="position">Position</label>
         <input type="text" name="position" id="position">

         <label for="username">Username*</label>
         <input type="text" name="username" id="username" required>

         <label for="password">Password*</label>
         <input type="password" name="password" id="password" required>

         <label for="office">Office Name*</label>
         <select name="office" id="office" required>
             <option value="" disabled selected>Select an Office</option>
             <?php foreach ($officeList as $officeID => $officeName): ?>
                 <option value="<?php echo htmlspecialchars($officeName); ?>"><?php echo htmlspecialchars($officeName); ?></option>
             <?php endforeach; ?>
         </select>

         <label for="admin">Admin</label>
         <input type="checkbox" name="admin" id="admin">

         <button type="submit">Create Account</button>
      </form>
      <?php else: ?>
         <h3>Account Created Successfully!</h3>
         <p><strong>User ID:</strong> <?php echo htmlspecialchars($newAccount['userID']); ?></p>
         <p><strong>Username:</strong> <?php echo htmlspecialchars($newAccount['username']); ?></p>
         <p><strong>Name:</strong> <?php echo htmlspecialchars($newAccount['firstname'] . " " . $newAccount['middlename'] . " " . $newAccount['lastname']); ?></p>
         <p><strong>Office:</strong> <?php echo htmlspecialchars($newAccount['officeID'] . ' - ' . $newAccount['officename']); ?></p>
         <p><strong>Role:</strong> <?php echo ($newAccount['admin'] == 1) ? "Admin" : "User"; ?></p>
         <button onclick="window.location.href='<?php echo url('manage_accounts.php'); ?>'">Proceed to Manage Accounts</button>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>