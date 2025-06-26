<?php
// Start the session if it hasn't been started yet
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require 'config.php'; // Ensure this file exists and contains PDO connection

// Only admin users can access this page.
if (!isset($_SESSION['username']) || $_SESSION['admin'] != 1) {
    redirect('index.php');
    exit();
}

$editSuccess = "";
$deleteSuccess = "";
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

// Process deletion if a 'delete' GET parameter is provided.
if (isset($_GET['delete'])) {
    $deleteID = intval($_GET['delete']);
    try {
        $stmt = $pdo->prepare("DELETE FROM tbluser WHERE userID = ?");
        $stmt->execute([$deleteID]);
        $deleteSuccess = "Account deleted successfully.";
    } catch (PDOException $e) {
        $error = "Error deleting account: " . $e->getMessage();
    }
}

// Process editing if the form is submitted.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['editAccount'])) {
    $editUserID = intval($_POST['editUserID']);
    $firstname  = trim($_POST['firstname']);
    $middlename = trim($_POST['middlename'] ?? "");
    $lastname   = trim($_POST['lastname']);
    $position   = trim($_POST['position'] ?? "");
    $username   = trim($_POST['username']);
    $password   = trim($_POST['password']);    // If empty, do not update password.
    $adminFlag  = isset($_POST['admin']) ? 1 : 0;
    $officeName = trim($_POST['office']); // Now comes from a select dropdown

    if (empty($firstname) || empty($lastname) || empty($username) || empty($officeName)) {
        $error = "Please fill in all required fields for editing.";
    } else {
        try {
            // Extract the office ID from the selected option (format: "OSDS", "ADMIN", etc.)
            // Check if the office name contains a number prefix
            if (preg_match('/^(\d+)\s*-\s*/', $officeName, $matches)) {
                // If it has a number prefix like "1 - OSDS", extract the number
                $officeID = intval($matches[1]);
            } else {
                // If it doesn't have a number prefix, look it up by name
                $stmtOffice = $pdo->prepare("SELECT officeID FROM officeid WHERE officename = ?");
                $stmtOffice->execute([$officeName]);
                $office = $stmtOffice->fetch();
                if ($office) {
                    $officeID = $office['officeID'];
                } else {
                    // Default to office ID 1 if not found
                    $officeID = 1;
                }
            }
            // Update the account. If password is provided, update it; otherwise leave it unchanged.
            if (!empty($password)) {
                $stmtEdit = $pdo->prepare("UPDATE tbluser SET firstname = ?, middlename = ?, lastname = ?, position = ?, username = ?, password = ?, admin = ?, officeID = ? WHERE userID = ?");
                $stmtEdit->execute([$firstname, $middlename, $lastname, $position, $username, $password, $adminFlag, $officeID, $editUserID]);
            } else {
                $stmtEdit = $pdo->prepare("UPDATE tbluser SET firstname = ?, middlename = ?, lastname = ?, position = ?, username = ?, admin = ?, officeID = ? WHERE userID = ?");
                $stmtEdit->execute([$firstname, $middlename, $lastname, $position, $username, $adminFlag, $officeID, $editUserID]);
            }
            $editSuccess = "Account updated successfully.";
        } catch(PDOException $e) {
            $error = "Error updating account: " . $e->getMessage();
        }
    }
}

// Retrieve all accounts along with their office names and IDs.
$stmt = $pdo->query("SELECT u.*, o.officeID, o.officename FROM tbluser u LEFT JOIN officeid o ON u.officeID = o.officeID ORDER BY u.userID ASC");
$accounts = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manage Accounts - DepEd BAC Tracking System</title>
    <link rel="stylesheet" href="assets/css/manage_account.css">
    <link rel="stylesheet" href="assets/css/background.css">

</head>
<body>
    <?php
    // Include your header.php file here.
    // This will insert the header HTML, its inline styles, and its inline JavaScript.
    include 'header.php';
    ?>

    <div class="accounts-container">
        <a href="<?php echo url('index.php'); ?>" class="back-btn" style="position:absolute; top:20px; left:3vw;">&#8592; Back</a>
        <h2 style="margin-left:60px;">Manage Accounts</h2>
        <?php
            if ($deleteSuccess != "") { echo "<p class='msg success'>" . htmlspecialchars($deleteSuccess) . "</p>"; }
            if ($editSuccess != "") { echo "<p class='msg success'>" . htmlspecialchars($editSuccess) . "</p>"; }
            if ($error != "") { echo "<p class='msg'>" . htmlspecialchars($error) . "</p>"; }
        ?>
        <table>
            <thead>
                <tr>
                    <th>User ID</th>
                    <th>Name</th>
                    <th>Username</th>
                    <th>Role</th>
                    <th>Office ID and Name</th>
                    <th>Position</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($accounts as $account): ?>
                    <tr>
                        <td data-label="User ID"><?php echo htmlspecialchars($account['userID']); ?></td>
                        <td data-label="Name"><?php echo htmlspecialchars($account['firstname'] . " " . $account['middlename'] . " " . $account['lastname']); ?></td>
                        <td data-label="Username"><?php echo htmlspecialchars($account['username']); ?></td>
                        <td data-label="Role"><?php echo ($account['admin'] == 1) ? "Admin" : "User"; ?></td>
                        <td data-label="Office"><?php echo htmlspecialchars($account['officeID'] . ' - ' . ($account['officename'] ?? "")); ?></td>
                        <td data-label="Position"><?php echo htmlspecialchars($account['position'] ?? ""); ?></td>
                        <td data-label="Actions">
                            <div class="action-buttons">
                                <button class="edit-btn icon-btn" data-id="<?php echo $account['userID']; ?>">
                                    <img src="assets/images/Edit_icon.png" alt="Edit" class="action-icon" width:24px;height:24px;>
                                </button>
                                <button class="account-delete-btn icon-btn" data-id="<?php echo $account['userID']; ?>">
                                    <img src="assets/images/delete_icon.png" alt="Delete" class="action-icon" width:24px;height:24px;>
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div id="editModal" class="modal">
        <div class="modal-content">
            <span class="close" id="editClose">&times;</span>
            <h2>Edit Account</h2>
            <form id="editAccountForm" action="manage_accounts.php" method="post">
                <input type="hidden" name="editUserID" id="editUserID">
                <label for="editFirstname">First Name*</label>
                <input type="text" name="firstname" id="editFirstname" required>

                <label for="editMiddlename">Middle Name</label>
                <input type="text" name="middlename" id="editMiddlename">

                <label for="editLastname">Last Name*</label>
                <input type="text" name="lastname" id="editLastname" required>

                <label for="editPosition">Position</label>
                <input type="text" name="position" id="editPosition">

                <label for="editUsername">Username*</label>
                <input type="text" name="username" id="editUsername" required>

                <label for="editPassword">Password (leave blank to keep unchanged)</label>
                <input type="password" name="password" id="editPassword">

                <label for="editOffice">Office Name*</label>
                <select name="office" id="editOffice" required>
                    <?php foreach ($officeList as $officeID => $officeName): ?>
                        <option value="<?php echo htmlspecialchars($officeName); ?>"><?php echo htmlspecialchars($officeName); ?></option>
                    <?php endforeach; ?>
                </select>

                <label for="editAdmin">Admin</label>
                <input type="checkbox" name="admin" id="editAdmin">

                <button type="submit" name="editAccount">Save Changes</button>
            </form>
        </div>
    </div>

    <div id="deleteConfirmModal" class="modal">
        <div class="modal-content">
            <span class="close" id="deleteClose">&times;</span>
            <h2>Confirm Deletion</h2>
            <p>Are you sure you want to delete this account?</p>
            <button id="confirmDeleteBtn" class="delete-btn">Yes, Delete</button>
            <button id="cancelDeleteBtn" class="edit-btn">Cancel</button>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Edit button functionality
            document.querySelectorAll('.edit-btn').forEach(function(button) {
                button.addEventListener('click', function() {
                    const row = this.closest('tr');
                    const userID = row.querySelector('[data-label="User ID"]').textContent.trim();
                    const fullName = row.querySelector('[data-label="Name"]').textContent.trim();
                    let nameParts = fullName.split(" ");
                    const firstname = nameParts[0] || "";
                    const lastname = (nameParts.length > 1) ? nameParts[nameParts.length - 1] : "";
                    const middlename = (nameParts.length > 2) ? nameParts.slice(1, nameParts.length - 1).join(" ") : "";
                    const username = row.querySelector('[data-label="Username"]').textContent.trim();
                    const role = row.querySelector('[data-label="Role"]').textContent.trim();
                    const office = row.querySelector('[data-label="Office"]').textContent.trim();
                    const position = row.querySelector('[data-label="Position"]').textContent.trim();
                    
                    // Populate the form fields
                    document.getElementById('editUserID').value = userID;
                    document.getElementById('editFirstname').value = firstname;
                    document.getElementById('editMiddlename').value = middlename;
                    document.getElementById('editLastname').value = lastname;
                    document.getElementById('editUsername').value = username;
                    document.getElementById('editPassword').value = "";
                    document.getElementById('editAdmin').checked = (role === "Admin");
                    document.getElementById('editPosition').value = position;
                    
                    // Set the selected option for the office dropdown
                    const editOfficeSelect = document.getElementById('editOffice');
                    let foundOffice = false;
                    for (let i = 0; i < editOfficeSelect.options.length; i++) {
                        if (editOfficeSelect.options[i].value === office) {
                            editOfficeSelect.selectedIndex = i;
                            foundOffice = true;
                            break;
                        }
                    }
                    if (!foundOffice && office) {
                        let newOption = new Option(office, office, true, true);
                        editOfficeSelect.add(newOption);
                    }
                    
                    // Display the modal
                    document.getElementById('editModal').style.display = 'block';
                });
            });
            
            // Delete button functionality
            let currentDeleteUserID = null;
            document.querySelectorAll('.account-delete-btn').forEach(function(button) {
                button.addEventListener('click', function() {
                    currentDeleteUserID = this.dataset.id;
                    document.getElementById('deleteConfirmModal').style.display = 'block';
                });
            });
            
            // Confirm delete button
            document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
                if (currentDeleteUserID) {
                    window.location.href = `<?php echo url('manage_accounts.php'); ?>?delete=${currentDeleteUserID}`;
                }
                document.getElementById('deleteConfirmModal').style.display = 'none';
            });
            
            // Cancel delete button
            document.getElementById('cancelDeleteBtn').addEventListener('click', function() {
                document.getElementById('deleteConfirmModal').style.display = 'none';
                currentDeleteUserID = null;
            });
            
            // Close buttons
            document.getElementById('editClose').addEventListener('click', function() {
                document.getElementById('editModal').style.display = 'none';
            });
            
            document.getElementById('deleteClose').addEventListener('click', function() {
                document.getElementById('deleteConfirmModal').style.display = 'none';
                currentDeleteUserID = null;
            });
            
            // Close modals when clicking outside
            window.addEventListener('click', function(event) {
                const editModal = document.getElementById('editModal');
                const deleteConfirmModal = document.getElementById('deleteConfirmModal');
                
                if (event.target === editModal) {
                    editModal.style.display = 'none';
                }
                if (event.target === deleteConfirmModal) {
                    deleteConfirmModal.style.display = 'none';
                    currentDeleteUserID = null;
                }
            });
        });
    </script>
</body>
</html>