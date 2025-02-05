<?php
class IssueController {
    private $issueModel;
    private $projectModel;
    private $db = null;

    public function __construct($db) {
        $this->db = $db;
        $this->issueModel = new Issue($db);
        $this->projectModel = new Project($db);
    }

    public function view($id) {
        $issue = $this->issueModel->getIssueById($id);
        if (!$issue) {
            throw new Exception("Issue not found");
        }
        
        // Get project details for breadcrumb
        $project = $this->projectModel->getProjectById($issue['PROJECT']);
        $linkedIssues = $this->issueModel->getLinkedIssues($issue['ID']);
        $history = $this->issueModel->getIssueHistory($id);
        $linkTypes = $this->issueModel->getAllLinkTypes();
        
        include 'views/issues/view.php';
    }
    
    public function list($projectId) {
        $project = $this->projectModel->getProjectById($projectId);
        if (!$project) {
            throw new Exception("Project not found");
        }
        
        $issues = $this->issueModel->getProjectIssuesWithSubcomponents($projectId);
        include 'views/issues/list.php';
    }

    public function search() {
        $searchTerm = $_GET['q'] ?? '';
        $projectId = $_GET['project'] ?? null;
        
        if ($searchTerm || $projectId) {
            $issues = $this->issueModel->searchIssues($searchTerm, $projectId);
        } else {
            $issues = [];
        }
        
        $projects = $this->projectModel->getAllProjects();
        include 'views/issues/search.php';
    }

    public function addComment($id) {
        if (!isset($_POST['comment']) || empty(trim($_POST['comment']))) {
            throw new Exception("Comment cannot be empty");
        }

        $comment = trim($_POST['comment']);
        $this->issueModel->addHistoryEntry($id, $comment, User::getCurrentUser());

        // Redirect back to issue view
        header("Location: index.php?page=issues&action=view&id=" . $id);
        exit;
    }

    public function edit($id) {
        $issue = $this->issueModel->getIssueById($id);
        if (!$issue) {
            throw new Exception("Issue not found");
        }

        $userModel = new User($this->db);
        $users = $userModel->getAllUsers();
        $priorities = $this->issueModel->getAllPriorities();
        $issueTypes = $this->issueModel->getAllIssueTypes();
        
        include 'views/issues/edit.php';
    }

    public function update($id) {
        $issue = $this->issueModel->getIssueById($id);
        if (!$issue) {
            throw new Exception("Issue not found");
        }

        $changes = [
            'summary' => ['old' => $issue['SUMMARY'], 'new' => $_POST['summary']],
            'description' => ['old' => $issue['DESCRIPTION'], 'new' => $_POST['description']],
            'assignee' => ['old' => $issue['ASSIGNEE'], 'new' => $_POST['assignee']],
            'reporter' => ['old' => $issue['REPORTER'], 'new' => $_POST['reporter']],
            'priority' => ['old' => $issue['PRIORITY'], 'new' => $_POST['priority']],
            'issuetype' => ['old' => $issue['ISSUETYPE'], 'new' => $_POST['issuetype']],
        ];

        $data = array_merge($_POST, ['changes' => $changes]);
        $this->issueModel->updateIssue($id, $data);
        
        header("Location: index.php?page=issues&action=view&id=" . $id);
        exit;
    }

    public function addLink($id) {
        if (!isset($_POST['linkedIssueId']) || !isset($_POST['linkType'])) {
            throw new Exception("Missing required fields");
        }

        $this->issueModel->addIssueLink(
            $id,
            $_POST['linkedIssueId'],
            $_POST['linkType']
        );

        header("Location: index.php?page=issues&action=view&id=" . $id);
        exit;
    }

    public function deleteLink($issueId, $linkId) {
        if (!$linkId) {
            return ['success' => false, 'error' => 'Link ID is required'];
        }
        
        try {
            $stmt = $this->db->prepare("DELETE FROM ISSUELINK WHERE ID = :linkId AND (SOURCE = :issueId OR DESTINATION = :issueId)");
            $result = $stmt->execute([
                ':linkId' => $linkId,
                ':issueId' => $issueId
            ]);
            
            if ($stmt->rowCount() > 0) {
                return ['success' => true];
            }
            return ['success' => false, 'error' => 'Link not found'];
        } catch (PDOException $e) {
            error_log($e->getMessage());
            return ['success' => false, 'error' => 'Database error while deleting link'];
        }
    }

    public function autocompleteIssues() {
        $term = $_GET['term'] ?? '';
        $projectId = $_GET['projectId'] ?? null;
        
        $issues = $this->issueModel->searchIssuesForAutocomplete($term, $projectId);
        
        header('Content-Type: application/json');
        echo json_encode($issues);
        exit;
    }

    public function create() {
        header('Content-Type: application/json');
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo json_encode(['success' => false, 'message' => 'Invalid JSON input']);
            exit;
        }
        // Validate required field: TITLE (SUMMARY)
        if (empty($data['SUMMARY'])) {
            echo json_encode(['success' => false, 'message' => 'Title (SUMMARY) is required']);
            exit;
        }
        // Map uppercase keys from AJAX into lower-case keys expected by createIssue
        $mappedData = [
            'projectId'   => $data['projectId'] ?? null,
            'summary'     => $data['SUMMARY'],
            'description' => $data['DESCRIPTION'] ?? '',  // allow empty description
            'issuetype'   => $data['ISSUETYPE'] ?? 'Task',
            'priority'    => $data['PRIORITY'] ?? 'Medium',
            'reporter'    => $data['REPORTER'] ?? '',
            'assignee'    => $data['ASSIGNEE'] ?? null,
            'STATUS_ID'   => $data['STATUS_ID'] ?? null
        ];
        
        $issueId = $this->issueModel->createIssue($mappedData);
        if ($issueId) {
            echo json_encode(['success' => true, 'issueId' => $issueId]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error creating task']);
        }
        exit;
    }

    public function store() {
        if (!isset($_POST['projectId'])) {
            throw new Exception("Project ID is required");
        }

        $issueId = $this->issueModel->createIssue($_POST);
        
        header("Location: index.php?page=issues&action=view&id=" . $issueId);
        exit;
    }

    public function delete($id) {
        try {
            $result = $this->issueModel->deleteIssue($id);
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    public function board($id) {
        $project = $this->projectModel->getProjectById($id);
        if (!$project) {
            throw new Exception("Project not found");
        }
        
        // Get all possible statuses
        $allStatuses = $this->issueModel->getAllStatuses();
        
        // Get all issues for the project
        $issues = $this->issueModel->getIssuesForBoard($id);
        
        // Get all users for assignment
        $userModel = new User($this->db);
        $users = $userModel->getAllUsers();
        
        // Initialize all status columns
        $boardColumns = [];
        foreach ($allStatuses as $status) {
            $boardColumns[$status['ID']] = [];  // Changed from PNAME to ID
        }
        
        // Fill in the issues
        foreach ($issues as $issue) {
            $statusId = $issue['STATUS'] ?? 'Open';  // Changed from status to statusId
            $boardColumns[$statusId][] = $issue;
        }
        
        include 'views/projects/board.php';
    }

    public function updateStatus() {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($data['issueId']) || !isset($data['statusId'])) {
            echo json_encode(['success' => false, 'message' => 'Missing required fields']);
            return;
        }

        try {
            $stmt = $this->db->prepare("UPDATE JIRAISSUE SET ISSUESTATUS = ? WHERE ID = ?");
            $stmt->execute([$data['statusId'], $data['issueId']]);
            
            // The issue stays in the sprint - we're just updating its status
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
?>
