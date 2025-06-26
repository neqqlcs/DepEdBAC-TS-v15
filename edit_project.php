<?php
// Start the session if it hasn't been started yet
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require 'config.php'; // Ensure this file properly connects to your database using PDO
require_once 'url_helper.php';

// Check that the user is logged in.
if (!isset($_SESSION['username'])) {
    redirect('login.php');
}

// Get the projectID from GET parameters.
$projectID = isset($_GET['projectID']) ? intval($_GET['projectID']) : 0;
if ($projectID <= 0) {
    die("Invalid Project ID");
}

// Define the ordered list of stages. This should be consistent across index.php and edit_project.php
$stagesOrder = [
    'Purchase Request',
    'RFQ 1',
    'RFQ 2',
    'RFQ 3',
    'Abstract of Quotation',
    'Purchase Order',
    'Notice of Award',
    'Notice to Proceed'
];

// Permission Variables - define early for consistent use
$isAdmin = ($_SESSION['admin'] == 1);
$isProjectCreator = false; // Initialize, will be set after fetching project details


// --- Define the Office List (fetched dynamically) ---
$officeList = [];
try {
    $stmtOffice = $pdo->query("SELECT officeID, officename FROM officeid ORDER BY officename");
    while ($row = $stmtOffice->fetch(PDO::FETCH_ASSOC)) {
        $officeList[$row['officeID']] = $row['officename'];
    }
} catch (PDOException $e) {
    error_log("Error fetching office list: " . $e->getMessage());
    die("Could not retrieve office list. Please try again later.");
}

// --- Get the logged-in user's office details ---
$loggedInUserOfficeID = null;
$loggedInUserOfficeName = "N/A";
if (isset($_SESSION['userID'])) {
    try {
        $stmtUserOffice = $pdo->prepare("SELECT u.officeID, o.officename FROM tbluser u LEFT JOIN officeid o ON u.officeID = o.officeID WHERE u.userID = ?");
        $stmtUserOffice->execute([$_SESSION['userID']]);
        $userOfficeData = $stmtUserOffice->fetch(PDO::FETCH_ASSOC);
        if ($userOfficeData) {
            $loggedInUserOfficeID = $userOfficeData['officeID'];
            $loggedInUserOfficeName = htmlspecialchars($userOfficeData['officeID'] . ' - ' . ($userOfficeData['officename'] ?? 'N/A'));
        }
    } catch (PDOException $e) {
        error_log("Error fetching logged-in user office details: " . $e->getMessage());
        // Gracefully handle if user's office cannot be fetched
    }
}


// --- Function to fetch project details ---
function fetchProjectDetails($pdo, $projectID) {
    $stmt = $pdo->prepare("SELECT p.*, u.firstname AS creator_firstname, u.lastname AS creator_lastname, o.officename
                            FROM tblproject p
                            LEFT JOIN tbluser u ON p.userID = u.userID
                            LEFT JOIN officeid o ON u.officeID = o.officeID
                            WHERE p.projectID = ?");
    $stmt->execute([$projectID]);
    return $stmt->fetch();
}

// --- Function to fetch project stages ---
function fetchProjectStages($pdo, $projectID, $stagesOrder) {
    // This query now expects officeID to be present in tblproject_stages
    $stmt2 = $pdo->prepare("SELECT * FROM tblproject_stages
                             WHERE projectID = ?
                             ORDER BY FIELD(stageName, 'Purchase Request','RFQ 1','RFQ 2','RFQ 3','Abstract of Quotation','Purchase Order','Notice of Award','Notice to Proceed')");
    $stmt2->execute([$projectID]);
    $stages = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    // If no stages exist, create records for every stage.
    if (empty($stages)) {
        foreach ($stagesOrder as $stageName) {
            $insertCreatedAt = null;
            if ($stageName === 'Purchase Request') {
                $insertCreatedAt = date("Y-m-d H:i:s");
            }
            // Initialize officeID as NULL when creating new stages
            $stmtInsert = $pdo->prepare("INSERT INTO tblproject_stages (projectID, stageName, officeID, createdAt) VALUES (?, ?, ?, ?)");
            $stmtInsert->execute([$projectID, $stageName, null, $insertCreatedAt]);
        }
        // Re-fetch stages after creation
        $stmt2->execute([$projectID]);
        $stages = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    }
    return $stages;
}

// --- Initial Data Fetch ---
$project = fetchProjectDetails($pdo, $projectID);
if (!$project) {
    die("Project not found");
}
$isProjectCreator = ($project['userID'] == $_SESSION['userID']);

$stages = fetchProjectStages($pdo, $projectID, $stagesOrder);

// Map stages by stageName for easy access and find the last submitted stage.
$stagesMap = [];
$noticeToProceedSubmitted = false;
$lastSubmittedStageIndex = -1;

foreach ($stages as $index => $s) {
    $stagesMap[$s['stageName']] = $s;
    if ($s['isSubmitted'] == 1) {
        $stageIndexInOrder = array_search($s['stageName'], $stagesOrder);
        if ($stageIndexInOrder !== false && $stageIndexInOrder > $lastSubmittedStageIndex) {
            $lastSubmittedStageIndex = $stageIndexInOrder;
        }
    }
    if ($s['stageName'] === 'Notice to Proceed' && $s['isSubmitted'] == 1) {
        $noticeToProceedSubmitted = true;
    }
}
$lastSubmittedStageName = ($lastSubmittedStageIndex !== -1) ? $stagesOrder[$lastSubmittedStageIndex] : null;


// Process Project Header update (available ONLY for admins).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_project_header'])) {
    if ($isAdmin) {
        $prNumber = trim($_POST['prNumber']);
        $projectDetails = trim($_POST['projectDetails']);
        if (empty($prNumber) || empty($projectDetails)) {
            $errorHeader = "PR Number and Project Details are required.";
        } else {
            $stmtUpdate = $pdo->prepare("UPDATE tblproject
                                             SET prNumber = ?, projectDetails = ?, editedAt = CURRENT_TIMESTAMP, editedBy = ?, lastAccessedAt = CURRENT_TIMESTAMP, lastAccessedBy = ?
                                             WHERE projectID = ?");
            $stmtUpdate->execute([$prNumber, $projectDetails, $_SESSION['userID'], $_SESSION['userID'], $projectID]);
            
            $successHeader = "Project details updated successfully.";
            $project = fetchProjectDetails($pdo, $projectID);
            $stages = fetchProjectStages($pdo, $projectID, $stagesOrder);
        }
    } else {
        $errorHeader = "You do not have permission to update project details.";
    }
}

// Process individual stage submission.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_stage'])) {
    $stageName = $_POST['stageName'];
    $safeStage = str_replace(' ', '_', $stageName);

    $currentStageDataForPost = $stagesMap[$stageName] ?? null;
    $currentIsSubmittedInDB = ($currentStageDataForPost && $currentStageDataForPost['isSubmitted'] == 1);

    $formCreated = isset($_POST["created_$safeStage"]) && !empty($_POST["created_$safeStage"]) ? $_POST["created_$safeStage"] : null;
    $approvedAt = isset($_POST['approvedAt']) && !empty($_POST['approvedAt']) ? $_POST['approvedAt'] : null;
    $remark = isset($_POST['remark']) ? trim($_POST['remark']) : "";

    // Determine if this is a "Submit" or "Unsubmit" action
    $isSubmittedVal = 1; // Default to submit
    if ($isAdmin && $currentIsSubmittedInDB && $stageName === $lastSubmittedStageName) {
        // If admin is unsubmitting, and it's the last submitted stage, set isSubmitted to 0
        $isSubmittedVal = 0;
    }

    // --- Validation Logic ---
    $validationFailed = false;
    if ($isSubmittedVal == 1) { // Only validate on "Submit" action
        // 'Approved' and 'Remark' are always required for submission
        if (empty($approvedAt)) {
            $validationFailed = true;
        }
        // 'Created' is required for admin submission (except PR)
        if ($isAdmin && $stageName !== 'Purchase Request' && empty($formCreated)) {
            $validationFailed = true;
        }
        // No validation needed for 'Office' as it's automatically filled by user's officeID on backend
    }

    if ($validationFailed) {
        $stageError = "All required fields (Approved and Remark" . ($isAdmin && $stageName !== 'Purchase Request' ? ", Created" : "") . ") must be filled for stage '$stageName' to be submitted.";
    } else {
        // Determine the office ID to save: it's the logged-in user's office ID for submission
        // This is the core change: It will always use the submitting user's office ID.
        $officeIDToSave = $loggedInUserOfficeID;

        // Prepare createdAt for update:
        $currentCreatedAtInDB = $currentStageDataForPost['createdAt'] ?? null;
        $actualCreatedAt = $currentCreatedAtInDB;

        if ($isAdmin) {
            if ($stageName !== 'Purchase Request' && !empty($formCreated)) {
                $actualCreatedAt = $formCreated;
            } else if ($isSubmittedVal == 1 && empty($currentCreatedAtInDB) && $stageName !== 'Purchase Request') {
                $actualCreatedAt = date("Y-m-d H:i:s");
            }
        } else {
            if ($isSubmittedVal == 1 && empty($currentCreatedAtInDB)) {
                $actualCreatedAt = date("Y-m-d H:i:s");
            }
        }

        // Convert datetime-local values ("Y-m-d\TH:i") to MySQL datetime ("Y-m-d H:i:s").
        $created_dt = $actualCreatedAt ? date("Y-m-d H:i:s", strtotime($actualCreatedAt)) : null;
        
        // If unsubmitting, clear approvedAt, officeID, and remarks
        if ($isSubmittedVal == 0) {
            $approved_dt = null;
            $officeIDToSave = null; // Set officeID to null on unsubmit
            $remark = "";
        } else {
            $approved_dt = $approvedAt ? date("Y-m-d H:i:s", strtotime($approvedAt)) : null;
        }

        // Updated SQL to use officeID
        $stmtStageUpdate = $pdo->prepare("UPDATE tblproject_stages
                                               SET createdAt = ?, approvedAt = ?, officeID = ?, remarks = ?, isSubmitted = ?
                                               WHERE projectID = ? AND stageName = ?");
        $stmtStageUpdate->execute([$created_dt, $approved_dt, $officeIDToSave, $remark, $isSubmittedVal, $projectID, $stageName]);
        $stageSuccess = "Stage '$stageName' updated successfully.";

        // If this is a "Submit" action, auto-update the next stage's createdAt if empty.
        if ($isSubmittedVal == 1) {
            $index = array_search($stageName, $stagesOrder);
            if ($index !== false && $index < count($stagesOrder) - 1) {
                $nextStageName = $stagesOrder[$index + 1];
                if (!(isset($stagesMap[$nextStageName]) && !empty($stagesMap[$nextStageName]['createdAt']))) {
                    $now = date("Y-m-d H:i:s");
                    $stmtNext = $pdo->prepare("UPDATE tblproject_stages SET createdAt = ? WHERE projectID = ? AND stageName = ?");
                    $stmtNext->execute([$now, $projectID, $nextStageName]);
                }
            }
        }

        // Update editedAt/editedBy for tblproject
        $pdo->prepare("UPDATE tblproject SET editedAt = CURRENT_TIMESTAMP, editedBy = ?, lastAccessedAt = CURRENT_TIMESTAMP, lastAccessedBy = ? WHERE projectID = ?")
            ->execute([$_SESSION['userID'], $_SESSION['userID'], $projectID]);

        // Re-fetch ALL data immediately after stage update/submission
        $project = fetchProjectDetails($pdo, $projectID);
        $stages = fetchProjectStages($pdo, $projectID, $stagesOrder);

        // Re-map stages after re-fetching to ensure latest status is used for rendering
        $stagesMap = [];
        $noticeToProceedSubmitted = false;
        $lastSubmittedStageIndex = -1;
        foreach ($stages as $index => $s) {
            $stagesMap[$s['stageName']] = $s;
            if ($s['isSubmitted'] == 1) {
                $stageIndexInOrder = array_search($s['stageName'], $stagesOrder);
                if ($stageIndexInOrder !== false && $stageIndexInOrder > $lastSubmittedStageIndex) {
                    $lastSubmittedStageIndex = $stageIndexInOrder;
                }
            }
            if ($s['stageName'] === 'Notice to Proceed' && $s['isSubmitted'] == 1) {
                $noticeToProceedSubmitted = true;
            }
        }
        $lastSubmittedStageName = ($lastSubmittedStageIndex !== -1) ? $stagesOrder[$lastSubmittedStageIndex] : null;
    }
}

// --- Pre-fetch names for display: Edited By ---
$editedByName = "N/A";
if (!empty($project['editedBy'])) {
    $stmtUser = $pdo->prepare("SELECT firstname, lastname FROM tbluser WHERE userID = ?");
    $stmtUser->execute([$project['editedBy']]);
    $user = $stmtUser->fetch();
    if ($user) {
        $editedByName = htmlspecialchars($user['firstname'] . " " . $user['lastname']);
    }
}

// --- Determine the "Next Unsubmitted Stage" for strict sequential access ---
// This is used throughout the page for determining which stage can be submitted
$firstUnsubmittedStageName = null;
foreach ($stagesOrder as $stage) {
    if (isset($stagesMap[$stage]) && $stagesMap[$stage]['isSubmitted'] == 0) {
        $firstUnsubmittedStageName = $stage;
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit Project - DepEd BAC Tracking System</title>
    <link rel="stylesheet" href="assets/css/background.css">
    <link rel="stylesheet" href="assets/css/edit_project.css">
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const stageForms = document.querySelectorAll('.stage-form');
        
        // Highlight the current active stage for better visibility
        const highlightActiveStage = function() {
            const firstUnsubmittedStageName = <?php echo json_encode($firstUnsubmittedStageName); ?>;
            if (firstUnsubmittedStageName) {
                document.querySelectorAll(`tr[data-stage="${firstUnsubmittedStageName}"]`).forEach(row => {
                    row.style.backgroundColor = '#f8f9fa';
                    row.style.boxShadow = '0 0 5px rgba(0,0,0,0.1)';
                });
                
                document.querySelectorAll(`.stage-card h4`).forEach(heading => {
                    if (heading.textContent === firstUnsubmittedStageName) {
                        heading.closest('.stage-card').style.backgroundColor = '#f8f9fa';
                        heading.closest('.stage-card').style.boxShadow = '0 0 8px rgba(0,0,0,0.15)';
                    }
                });
            }
        };
        
        highlightActiveStage();

        // Improved form validation
        stageForms.forEach(form => {
            form.addEventListener('submit', function(event) {
                const stageNameInput = form.querySelector('input[name="stageName"]');
                const stageName = stageNameInput ? stageNameInput.value : '';
                const safeStage = stageName.replace(/ /g, '_');

                const createdField = form.querySelector(`input[name="created_${safeStage}"]`);
                const approvedAtField = form.querySelector('input[name="approvedAt"]');
                const remarkField = form.querySelector('input[name="remark"]');
                const submitButton = form.querySelector('button[name="submit_stage"]');
                
                const buttonText = submitButton ? submitButton.textContent.trim() : '';

                if (buttonText === 'Submit' && !submitButton.disabled) {
                    let isValid = true;
                    let errorMessages = [];

                    // Validate 'Approved' field
                    if (!approvedAtField || !approvedAtField.value) {
                        isValid = false;
                        errorMessages.push("Approved Date/Time is required");
                    }
                    
                    // Validate 'Remark' field
                    if (!remarkField || !remarkField.value.trim()) {
                        isValid = false;
                        errorMessages.push("Remarks are required");
                    }

                    // Validate 'Created' field for admins and non-PR stages
                    const isAdmin = <?php echo json_encode($isAdmin); ?>;
                    const isPurchaseRequest = (stageName === 'Purchase Request');
                    const currentCreatedValue = createdField ? createdField.value : '';

                    if (isAdmin && !isPurchaseRequest && !currentCreatedValue) {
                        isValid = false;
                        errorMessages.push("Created Date/Time is required for this stage");
                    }

                    if (!isValid) {
                        event.preventDefault();
                        alert("Please fix the following errors:\n• " + errorMessages.join("\n• "));
                    }
                }
            });
        });
        
        // Add tooltips for better usability
        const addTooltip = function(element, text) {
            element.title = text;
            element.style.cursor = 'help';
        };
        
        document.querySelectorAll('th').forEach(th => {
            if (th.textContent === 'Created') {
                addTooltip(th, 'When the document was created');
            } else if (th.textContent === 'Approved') {
                addTooltip(th, 'When the document was approved');
            } else if (th.textContent === 'Office') {
                addTooltip(th, 'Office responsible for this stage');
            }
        });
    });
    </script>
</head>
<body class="<?php echo $isAdmin ? 'admin-view' : 'user-view'; ?>">
    <?php
    include 'header.php'; // Uncomment if you have a header.php file
    ?>
    
    <div class="dashboard-container">
        <a href="<?php echo url('index.php'); ?>" class="back-btn">&larr; Back to Dashboard</a>

        <h1>Edit Project</h1>

        <?php
            if (isset($errorHeader)) { echo "<p style='color:red;'>$errorHeader</p>"; }
            if (isset($successHeader)) { echo "<p style='color:green;'>$successHeader</p>"; }
            if (isset($stageError)) { echo "<p style='color:red;'>$stageError</p>"; }
        ?>

        <div class="project-header">
            <label for="prNumber">PR Number:</label>
            <?php if ($isAdmin): ?>
                <form action="edit_project.php?projectID=<?php echo $projectID; ?>" method="post" style="margin-bottom:10px;">
                    <input type="text" name="prNumber" id="prNumber" value="<?php echo htmlspecialchars($project['prNumber']); ?>" required>
            <?php else: ?>
                <div class="readonly-field"><?php echo htmlspecialchars($project['prNumber']); ?></div>
            <?php endif; ?>

            <label for="projectDetails">Project Details:</label>
            <?php if ($isAdmin): ?>
                <textarea name="projectDetails" id="projectDetails" rows="3" required><?php echo htmlspecialchars($project['projectDetails']); ?></textarea>
            <?php else: ?>
                <div class="readonly-field"><?php echo htmlspecialchars($project['projectDetails']); ?></div>
            <?php endif; ?>

            <label>Created By:</label> <p><?php echo htmlspecialchars($project['creator_firstname'] . " " . $project['creator_lastname'] . " | Office: " . ($project['officename'] ?? 'N/A')); ?></p>

            <label>Date Created:</label>
            <p><?php echo date("m-d-Y h:i A", strtotime($project['createdAt'])); ?></p>

            <label>Last Updated:</label> <p>
                <?php
                $lastUpdatedInfo = "Not Available";
                $mostRecentTimestamp = null;
                $mostRecentUserId = null;

                $editedTs = !empty($project['editedAt']) ? strtotime($project['editedAt']) : 0;
                $lastAccessedTs = !empty($project['lastAccessedAt']) ? strtotime($project['lastAccessedAt']) : 0;

                if ($editedTs > 0 && ($editedTs >= $lastAccessedTs || $lastAccessedTs === 0)) {
                    $mostRecentTimestamp = $editedTs;
                    $mostRecentUserId = $project['editedBy'];
                } else if ($lastAccessedTs > 0) {
                    $mostRecentTimestamp = $lastAccessedTs;
                    $mostRecentUserId = $project['lastAccessedBy'];
                }
                
                $lastUpdatedUserFullName = "N/A";
                if (!empty($mostRecentUserId)) {
                    $stmtUser = $pdo->prepare("SELECT firstname, lastname FROM tbluser WHERE userID = ?");
                    $stmtUser->execute([$mostRecentUserId]);
                    $user = $stmtUser->fetch();
                    if ($user) {
                        $lastUpdatedUserFullName = htmlspecialchars($user['firstname'] . " " . $user['lastname']);
                    }
                }
                
                if ($lastUpdatedUserFullName !== "N/A" && $mostRecentTimestamp) {
                    $lastUpdatedInfo = $lastUpdatedUserFullName . ", on " . date("m-d-Y h:i A", $mostRecentTimestamp);
                }
                echo $lastUpdatedInfo;
                ?>
            </p>
            <?php if ($isAdmin): ?>
                <button type="submit" name="update_project_header" class="update-project-details-btn">
                    <span>Update Project Details</span>
                </button>
            </form>
            <?php endif; ?>
        </div>

        <h3>Project Stages</h3>
        <?php
            $projectStatusClass = $noticeToProceedSubmitted ? 'finished' : 'in-progress';
            $projectStatusText = 'Status: ' . ($noticeToProceedSubmitted ? 'Finished' : 'In Progress');
            echo '<div class="project-status ' . $projectStatusClass . '">' . $projectStatusText . '</div>';
        ?>
        <?php if (isset($stageSuccess)) { echo "<p style='color:green;'>$stageSuccess</p>"; } ?>
        <div class="table-wrapper">
            <table id="stagesTable">
                <thead>
                    <tr>
                        <th style="width: 15%;">Stage</th>
                        <th style="width: 20%;">Created</th>
                        <th style="width: 20%;">Approved</th>
                        <th style="width: 15%;">Office</th>
                        <th style="width: 15%;">Remark</th>
                        <th style="width: 15%;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    // Using $firstUnsubmittedStageName already determined above
                    foreach ($stagesOrder as $index => $stage):
                        $safeStage = str_replace(' ', '_', $stage);
                        $currentStageData = $stagesMap[$stage] ?? null;

                        $currentSubmitted = ($currentStageData && $currentStageData['isSubmitted'] == 1);

                        $value_created = ($currentStageData && !empty($currentStageData['createdAt']))
                                                 ? date("Y-m-d\TH:i", strtotime($currentStageData['createdAt'])) : "";
                        $value_approved = ($currentStageData && !empty($currentStageData['approvedAt']))
                                                  ? date("Y-m-d\TH:i", strtotime($currentStageData['approvedAt'])) : "";
                        
                        // Get the office ID stored for THIS specific stage from the database
                        $value_office_id_for_stage = ($currentStageData && isset($currentStageData['officeID']))
                                                ? (int)$currentStageData['officeID'] : null;
                        
                        $value_remark = ($currentStageData && !empty($currentStageData['remarks']))
                                                ? htmlspecialchars($currentStageData['remarks']) : "";

                        $isLastProcessedStage = ($stage === $lastSubmittedStageName);

                        $disableFields = true;
                        $disableCreatedField = true;

                        if ($stage === $firstUnsubmittedStageName) {
                            $disableFields = false;
                            if ($isAdmin && $stage !== 'Purchase Request') {
                                $disableCreatedField = false;
                            }
                            if ($stage === 'Purchase Request' && !empty($value_created)) {
                                $disableCreatedField = true;
                            }
                        }
                        
                        if ($currentSubmitted) {
                            $disableFields = true;
                            $disableCreatedField = true;
                        }
                        if ($stage !== $firstUnsubmittedStageName && !$currentSubmitted) {
                             $disableFields = true;
                             $disableCreatedField = true;
                        }

                        // Determine the office name to display
                        $displayOfficeName = "N/A"; // Default display if no office is found or applicable

                        // Case 1: Stage has an officeID already saved in the database
                        if ($value_office_id_for_stage !== null && isset($officeList[$value_office_id_for_stage])) {
                            $displayOfficeName = htmlspecialchars($value_office_id_for_stage . ' - ' . $officeList[$value_office_id_for_stage]);
                        }
                        // Case 2: Stage is the 'firstUnsubmittedStageName' (the current active stage)
                        // AND it does not yet have an officeID saved in the database.
                        // In this case, it should show the logged-in user's office.
                        elseif ($stage === $firstUnsubmittedStageName && $value_office_id_for_stage === null && $loggedInUserOfficeName !== "N/A") {
                            // Already formatted with ID in $loggedInUserOfficeName
                            $displayOfficeName = $loggedInUserOfficeName;
                        }
                    ?>
                    <form action="<?php echo url('edit_project.php', ['projectID' => $projectID]); ?>" method="post" class="stage-form">
                        <tr data-stage="<?php echo htmlspecialchars($stage); ?>">
                            <td><?php echo htmlspecialchars($stage); ?></td>
                            <td>
                                <input type="datetime-local" name="created_<?php echo $safeStage; ?>"
                                        value="<?php echo $value_created; ?>"
                                        <?php if ($disableCreatedField) echo "disabled"; ?>
                                        <?php if (!$disableCreatedField && $stage !== 'Purchase Request') echo "required"; ?>>
                            </td>
                            <td>
                                <input type="datetime-local" name="approvedAt"
                                        value="<?php echo $value_approved; ?>"
                                        <?php if ($disableFields) echo "disabled"; ?>
                                        <?php if (!$disableFields) echo "required"; ?>>
                            </td>
                            <td>
                                <!-- Office field is now display-only (no input or select element) -->
                                <div class="readonly-office-field">
                                    <?php echo $displayOfficeName; ?>
                                </div>
                                <!-- This hidden input is NO LONGER NEEDED as the backend directly uses $loggedInUserOfficeID -->
                                <!-- <input type="hidden" name="officeID" value="<?php //echo htmlspecialchars($loggedInUserOfficeID); ?>"> -->
                            </td>
                            <td>
                                <input type="text" name="remark"
                                        value="<?php echo $value_remark; ?>"
                                        <?php if ($disableFields) echo "disabled"; ?>
                                        <?php if (!$disableFields) echo "required"; ?>>
                            </td>
                            <td>
                                <input type="hidden" name="stageName" value="<?php echo htmlspecialchars($stage); ?>">
                                <div style="margin-top:10px;">
                                    <?php
                                    if ($currentSubmitted) {
                                        if ($isAdmin && $isLastProcessedStage) {
                                            echo '<button type="submit" name="submit_stage" class="submit-stage-btn unsubmit-btn">Unsubmit</button>';
                                        } else {
                                            echo '<button type="button" class="submit-stage-btn" disabled>Finished</button>';
                                        }
                                    } else {
                                        if ($stage === $firstUnsubmittedStageName) {
                                            echo '<button type="submit" name="submit_stage" class="submit-stage-btn">Submit</button>';
                                        } else {
                                            echo '<button type="button" class="submit-stage-btn" disabled>Pending</button>';
                                        }
                                    }
                                    ?>
                                </div>
                            </td>
                        </tr>
                    </form>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="card-view">
            <?php foreach ($stagesOrder as $index => $stage):
                $safeStage = str_replace(' ', '_', $stage);
                $currentStageData = $stagesMap[$stage] ?? null;
                $currentSubmitted = ($currentStageData && $currentStageData['isSubmitted'] == 1);

                $value_created = ($currentStageData && !empty($currentStageData['createdAt'])) ? date("Y-m-d\TH:i", strtotime($currentStageData['createdAt'])) : "";
                $value_approved = ($currentStageData && !empty($currentStageData['approvedAt'])) ? date("Y-m-d\TH:i", strtotime($currentStageData['approvedAt'])) : "";
                
                $value_office_id_for_stage = ($currentStageData && isset($currentStageData['officeID']))
                                        ? (int)$currentStageData['officeID'] : null;
                
                $value_remark = ($currentStageData && !empty($currentStageData['remarks'])) ? htmlspecialchars($currentStageData['remarks']) : "";

                $isLastProcessedStage = ($stage === $lastSubmittedStageName);

                $disableFields = true;
                $disableCreatedField = true;

                if ($stage === $firstUnsubmittedStageName) {
                    $disableFields = false;
                    if ($isAdmin && $stage !== 'Purchase Request') {
                        $disableCreatedField = false;
                    }
                    if ($stage === 'Purchase Request' && !empty($value_created)) {
                        $disableCreatedField = true;
                    }
                }
                
                if ($currentSubmitted) {
                    $disableFields = true;
                    $disableCreatedField = true;
                }
                if ($stage !== $firstUnsubmittedStageName && !$currentSubmitted) {
                    $disableFields = true;
                    $disableCreatedField = true;
                }

                $displayOfficeName = "N/A";
                if ($value_office_id_for_stage !== null && isset($officeList[$value_office_id_for_stage])) {
                    $displayOfficeName = htmlspecialchars($value_office_id_for_stage . ' - ' . $officeList[$value_office_id_for_stage]);
                } elseif (!$disableFields && $value_office_id_for_stage === null && $loggedInUserOfficeName !== "N/A") {
                    // Already formatted with ID in $loggedInUserOfficeName
                    $displayOfficeName = $loggedInUserOfficeName;
                }
            ?>
            <form action="<?php echo url('edit_project.php', ['projectID' => $projectID]); ?>" method="post" class="stage-form">
                <div class="stage-card">
                    <h4><?php echo htmlspecialchars($stage); ?></h4>

                    <label>Created At:</label>
                    <input type="datetime-local" name="created_<?php echo $safeStage; ?>" value="<?php echo $value_created; ?>"
                        <?php if ($disableCreatedField) echo "disabled"; ?>
                        <?php if (!$disableCreatedField && $stage !== 'Purchase Request') echo "required"; ?>>

                    <label>Approved At:</label>
                    <input type="datetime-local" name="approvedAt" value="<?php echo $value_approved; ?>"
                        <?php if ($disableFields) echo "disabled"; ?>
                        <?php if (!$disableFields) echo "required"; ?>>

                    <label>Office:</label>
                    <div class="readonly-office-field">
                        <?php echo $displayOfficeName; ?>
                    </div>

                    <label>Remark:</label>
                    <input type="text" name="remark" value="<?php echo $value_remark; ?>"
                        <?php if ($disableFields) echo "disabled"; ?>
                        <?php if (!$disableFields) echo "required"; ?>>

                    <input type="hidden" name="stageName" value="<?php echo htmlspecialchars($stage); ?>">
                    <div style="margin-top:10px;">
                        <?php
                        if ($currentSubmitted) {
                            if ($isAdmin && $isLastProcessedStage) {
                                echo '<button type="submit" name="submit_stage" class="submit-stage-btn unsubmit-btn">Unsubmit</button>';
                            } else {
                                echo '<button type="button" class="submit-stage-btn" disabled>Finished</button>';
                            }
                        } else {
                            if ($stage === $firstUnsubmittedStageName) {
                                echo '<button type="submit" name="submit_stage" class="submit-stage-btn">Submit</button>';
                            } else {
                                echo '<button type="button" class="submit-stage-btn" disabled>Pending</button>';
                            }
                        }
                        ?>
                    </div>
                </div>
            </form>
            <?php endforeach; ?>
        </div>

        <?php if ($noticeToProceedSubmitted): ?>
            <div class="completion-message">
                <p>All stages are completed! This project is now finished.</p>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>