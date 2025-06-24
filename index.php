<?php
// config.php - Configuration file
define('GUESTS_FILE', 'guests.json');
define('DATA_DIR', 'data/');
define('BACKUP_DIR', 'backups/');
define('MAX_INPUT_LENGTH', 500);
define('MAX_COMMENT_LENGTH', 1000);
define('MAX_GUESTS_COUNT', 10);

// Create directories if they don't exist
$directories = [DATA_DIR, BACKUP_DIR];
foreach ($directories as $dir) {
    if (!file_exists($dir)) {
        if (!mkdir($dir, 0755, true)) {
            error_log("Failed to create directory: $dir");
            die("System error: Unable to create required directories");
        }
    }
}

// Enhanced security and validation functions
function sanitizeInput($input, $maxLength = MAX_INPUT_LENGTH) {
    if (!is_string($input)) {
        return '';
    }
    
    $input = trim($input);
    $input = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
    
    if (strlen($input) > $maxLength) {
        $input = substr($input, 0, $maxLength);
    }
    
    return $input;
}

function validateEmail($email) {
    $email = filter_var($email, FILTER_VALIDATE_EMAIL);
    return $email !== false ? $email : null;
}

function validatePhone($phone) {
    $phone = preg_replace('/[^0-9+\-\s\(\)]/', '', $phone);
    return strlen($phone) >= 10 && strlen($phone) <= 20 ? $phone : null;
}

function validateRequired($value, $fieldName) {
    $sanitized = sanitizeInput($value);
    if (empty($sanitized)) {
        throw new InvalidArgumentException("$fieldName is required");
    }
    return $sanitized;
}

function validateAttendance($attendance) {
    $validOptions = ['yes', 'no', 'maybe'];
    return in_array($attendance, $validOptions) ? $attendance : null;
}

function validateGuestCount($count) {
    $count = filter_var($count, FILTER_VALIDATE_INT);
    return ($count !== false && $count >= 0 && $count <= MAX_GUESTS_COUNT) ? $count : 0;
}

// Safe file operations with proper locking
function safeFileRead($filepath) {
    if (!file_exists($filepath)) {
        return null;
    }
    
    $handle = fopen($filepath, 'r');
    if (!$handle) {
        error_log("Failed to open file for reading: $filepath");
        return null;
    }
    
    if (flock($handle, LOCK_SH)) {
        $content = fread($handle, filesize($filepath));
        flock($handle, LOCK_UN);
        fclose($handle);
        return $content;
    }
    
    fclose($handle);
    error_log("Failed to acquire read lock: $filepath");
    return null;
}

function safeFileWrite($filepath, $content) {
    $handle = fopen($filepath, 'w');
    if (!$handle) {
        error_log("Failed to open file for writing: $filepath");
        return false;
    }
    
    if (flock($handle, LOCK_EX)) {
        $result = fwrite($handle, $content);
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
        return $result !== false;
    }
    
    fclose($handle);
    error_log("Failed to acquire write lock: $filepath");
    return false;
}

function createBackup() {
    $sourceFile = DATA_DIR . GUESTS_FILE;
    if (!file_exists($sourceFile)) {
        return true; // No file to backup
    }
    
    $backupFile = BACKUP_DIR . 'guests_backup_' . date('Y-m-d_H-i-s') . '.json';
    $content = safeFileRead($sourceFile);
    
    if ($content !== null) {
        return safeFileWrite($backupFile, $content);
    }
    
    return false;
}

// Improved data handling functions
function getGuestsFromFile() {
    $filepath = DATA_DIR . GUESTS_FILE;
    $content = safeFileRead($filepath);
    
    if ($content === null) {
        return [];
    }
    
    $guests = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("JSON decode error: " . json_last_error_msg());
        return [];
    }
    
    return is_array($guests) ? $guests : [];
}

function saveGuestsToFile($guests) {
    $filepath = DATA_DIR . GUESTS_FILE;
    
    // Create backup before saving
    createBackup();
    
    $jsonContent = json_encode($guests, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($jsonContent === false) {
        error_log("JSON encode error: " . json_last_error_msg());
        return false;
    }
    
    return safeFileWrite($filepath, $jsonContent);
}

function validateGuestData($data) {
    try {
        $validatedData = [
            'fullName' => validateRequired($data['fullName'] ?? '', 'Full Name'),
            'email' => validateEmail($data['email'] ?? ''),
            'phone' => sanitizeInput($data['phone'] ?? ''),
            'company' => validateRequired($data['company'] ?? '', 'Company'),
            'jobTitle' => validateRequired($data['jobTitle'] ?? '', 'Job Title'),
            'companyAddress' => validateRequired($data['companyAddress'] ?? '', 'Company Address'),
            'attendance' => validateAttendance($data['attendance'] ?? ''),
            'guests' => validateGuestCount($data['guests'] ?? 0),
            'comments' => sanitizeInput($data['comments'] ?? '', MAX_COMMENT_LENGTH),
            'updates' => !empty($data['updates']),
            'submitted' => date('Y-m-d H:i:s'),
            'id' => uniqid('guest_', true)
        ];
        
        if (!$validatedData['email']) {
            throw new InvalidArgumentException('Valid email address is required');
        }
        
        if (!$validatedData['attendance']) {
            throw new InvalidArgumentException('Attendance selection is required');
        }
        
        // Validate phone if provided
        if (!empty($validatedData['phone'])) {
            $validatedPhone = validatePhone($validatedData['phone']);
            if (!$validatedPhone) {
                throw new InvalidArgumentException('Invalid phone number format');
            }
            $validatedData['phone'] = $validatedPhone;
        }
        
        return $validatedData;
        
    } catch (Exception $e) {
        throw new InvalidArgumentException('Validation error: ' . $e->getMessage());
    }
}

function guestExists($email) {
    $guests = getGuestsFromFile();
    $email = strtolower(trim($email));
    
    foreach ($guests as $guest) {
        if (isset($guest['email']) && strtolower($guest['email']) === $email) {
            return true;
        }
    }
    return false;
}

function addGuest($guestData) {
    try {
        $validatedData = validateGuestData($guestData);
        
        if (guestExists($validatedData['email'])) {
            throw new InvalidArgumentException('Guest with this email already exists');
        }
        
        $guests = getGuestsFromFile();
        $guests[] = $validatedData;
        
        return saveGuestsToFile($guests);
        
    } catch (Exception $e) {
        error_log("Error adding guest: " . $e->getMessage());
        throw $e;
    }
}

function updateGuest($email, $guestData) {
    try {
        $validatedData = validateGuestData($guestData);
        $guests = getGuestsFromFile();
        $email = strtolower(trim($email));
        $updated = false;
        
        foreach ($guests as &$guest) {
            if (isset($guest['email']) && strtolower($guest['email']) === $email) {
                $validatedData['submitted'] = $guest['submitted'] ?? date('Y-m-d H:i:s');
                $validatedData['updated'] = date('Y-m-d H:i:s');
                $validatedData['id'] = $guest['id'] ?? uniqid('guest_', true);
                $guest = $validatedData;
                $updated = true;
                break;
            }
        }
        
        if ($updated) {
            return saveGuestsToFile($guests);
        }
        
        return false;
        
    } catch (Exception $e) {
        error_log("Error updating guest: " . $e->getMessage());
        throw $e;
    }
}

// Handle AJAX requests with proper error handling
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        switch ($_POST['action']) {
            case 'check_guest':
                $email = $_POST['email'] ?? '';
                if (empty($email)) {
                    throw new InvalidArgumentException('Email is required');
                }
                
                $validEmail = validateEmail($email);
                if (!$validEmail) {
                    throw new InvalidArgumentException('Invalid email format');
                }
                
                echo json_encode(['exists' => guestExists($validEmail)]);
                break;
                
            case 'add_guest':
                $result = addGuest($_POST);
                echo json_encode([
                    'success' => true, 
                    'message' => 'Guest added successfully'
                ]);
                break;
                
            case 'update_guest':
                $email = $_POST['email'] ?? '';
                if (empty($email)) {
                    throw new InvalidArgumentException('Email is required for update');
                }
                
                $result = updateGuest($email, $_POST);
                echo json_encode([
                    'success' => $result, 
                    'message' => $result ? 'Guest updated successfully' : 'Failed to update guest'
                ]);
                break;
                
            case 'get_guests':
                $guests = getGuestsFromFile();
                echo json_encode(['guests' => $guests]);
                break;
                
            default:
                throw new InvalidArgumentException('Invalid action');
        }
        
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false, 
            'message' => $e->getMessage()
        ]);
    }
    
    exit;
}

// Get guests for display
$guests = getGuestsFromFile();
$guestCount = count($guests);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Event RSVP - Please Confirm Your Attendance</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            padding: 40px;
            max-width: 500px;
            width: 100%;
            animation: slideUp 0.6s ease-out;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
        }

        .header h1 {
            color: #333;
            margin-bottom: 10px;
            font-size: 2.2em;
            font-weight: 300;
        }

        .header p {
            color: #666;
            font-size: 1.1em;
        }

        .stats {
            text-align: center;
            color: #667eea;
            font-size: 0.9em;
            margin-bottom: 20px;
            font-weight: 500;
        }

        .form-group {
            margin-bottom: 25px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
            font-size: 1.1em;
        }

        .required {
            color: #e74c3c;
        }

        input, select, textarea {
            width: 100%;
            padding: 15px;
            border: 2px solid #e1e5e9;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.8);
        }

        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
            transform: translateY(-2px);
        }

        textarea {
            resize: vertical;
            min-height: 100px;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .checkbox-group input[type="checkbox"] {
            width: auto;
            transform: scale(1.2);
        }

        .submit-btn {
            width: 100%;
            padding: 18px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 1.1em;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .submit-btn:hover:not(:disabled) {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.3);
        }

        .submit-btn:active {
            transform: translateY(-1px);
        }

        .submit-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .error {
            color: #e74c3c;
            font-size: 0.9em;
            margin-top: 5px;
            display: none;
            background: rgba(231, 76, 60, 0.1);
            padding: 8px;
            border-radius: 5px;
            border-left: 3px solid #e74c3c;
        }

        .success, .info {
            padding: 15px;
            border-radius: 10px;
            text-align: center;
            margin-top: 20px;
            display: none;
            animation: fadeIn 0.5s ease;
        }

        .success {
            background: #2ecc71;
            color: white;
        }

        .info {
            background: #3498db;
            color: white;
        }

        .error-message {
            background: #e74c3c;
            color: white;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .loading {
            display: none;
            text-align: center;
            color: #667eea;
            margin-top: 10px;
        }

        .spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-right: 10px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .char-count {
            font-size: 0.8em;
            color: #666;
            text-align: right;
            margin-top: 5px;
        }

        .char-count.warning {
            color: #f39c12;
        }

        .char-count.error {
            color: #e74c3c;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🎉 Event RSVP</h1>
            <p>Please confirm your attendance</p>
            <?php if ($guestCount > 0): ?>
                <div class="stats"><?php echo $guestCount; ?> guest<?php echo $guestCount !== 1 ? 's' : ''; ?> registered so far</div>
            <?php endif; ?>
        </div>

        <form id="rsvpForm">
            <div class="form-group">
                <label for="fullName">Full Name <span class="required">*</span></label>
                <input type="text" id="fullName" name="fullName" required maxlength="<?php echo MAX_INPUT_LENGTH; ?>">
                <div class="error" id="nameError"></div>
            </div>

            <div class="form-group">
                <label for="email">Email Address <span class="required">*</span></label>
                <input type="email" id="email" name="email" required maxlength="<?php echo MAX_INPUT_LENGTH; ?>">
                <div class="error" id="emailError"></div>
            </div>

            <div class="form-group">
                <label for="phone">Phone Number</label>
                <input type="tel" id="phone" name="phone" maxlength="20" placeholder="+1 (555) 123-4567">
                <div class="error" id="phoneError"></div>
            </div>

            <div class="form-group">
                <label for="company">Company/Enterprise <span class="required">*</span></label>
                <input type="text" id="company" name="company" required maxlength="<?php echo MAX_INPUT_LENGTH; ?>">
                <div class="error" id="companyError"></div>
            </div>

            <div class="form-group">
                <label for="jobTitle">Job Title/Position <span class="required">*</span></label>
                <input type="text" id="jobTitle" name="jobTitle" required maxlength="<?php echo MAX_INPUT_LENGTH; ?>">
                <div class="error" id="jobTitleError"></div>
            </div>

            <div class="form-group">
                <label for="companyAddress">Company Address <span class="required">*</span></label>
                <textarea id="companyAddress" name="companyAddress" required maxlength="<?php echo MAX_INPUT_LENGTH; ?>" placeholder="Street address, city, state/province, postal code, country"></textarea>
                <div class="char-count" id="addressCharCount">0/<?php echo MAX_INPUT_LENGTH; ?></div>
                <div class="error" id="companyAddressError"></div>
            </div>

            <div class="form-group">
                <label for="attendance">Will you attend? <span class="required">*</span></label>
                <select id="attendance" name="attendance" required>
                    <option value="">Please select...</option>
                    <option value="yes">Yes, I'll be there!</option>
                    <option value="no">Sorry, I can't make it</option>
                    <option value="maybe">Maybe - I'll try to come</option>
                </select>
                <div class="error" id="attendanceError"></div>
            </div>

            <div class="form-group">
                <label for="guests">Number of Additional Guests</label>
                <select id="guests" name="guests">
                    <option value="0">Just me</option>
                    <option value="1">+1 guest</option>
                    <option value="2">+2 guests</option>
                    <option value="3">+3 guests</option>
                    <option value="4">+4 guests</option>
                    <option value="5">+5 guests</option>
                    <option value="6">+6 guests</option>
                    <option value="7">+7 guests</option>
                    <option value="8">+8 guests</option>
                    <option value="9">+9 guests</option>
                    <option value="10">+10 guests</option>
                </select>
            </div>

            <div class="form-group">
                <label for="comments">Additional Comments</label>
                <textarea id="comments" name="comments" maxlength="<?php echo MAX_COMMENT_LENGTH; ?>" placeholder="Anything else you'd like us to know?"></textarea>
                <div class="char-count" id="commentsCharCount">0/<?php echo MAX_COMMENT_LENGTH; ?></div>
            </div>

            <div class="form-group checkbox-group">
                <input type="checkbox" id="updates" name="updates">
                <label for="updates">Send me updates about this event</label>
            </div>

            <button type="submit" class="submit-btn" id="submitBtn">Submit RSVP</button>
            <div class="loading" id="loading">
                <div class="spinner"></div>
                Processing your RSVP...
            </div>
        </form>

        <div class="success" id="successMessage"></div>
        <div class="info" id="infoMessage"></div>
        <div class="info error-message" id="errorMessage"></div>
    </div>

    <script>
        // Constants
        const MAX_INPUT_LENGTH = <?php echo MAX_INPUT_LENGTH; ?>;
        const MAX_COMMENT_LENGTH = <?php echo MAX_COMMENT_LENGTH; ?>;

        // Character count functionality
        function setupCharacterCount(textareaId, counterId, maxLength) {
            const textarea = document.getElementById(textareaId);
            const counter = document.getElementById(counterId);
            
            if (textarea && counter) {
                textarea.addEventListener('input', function() {
                    const currentLength = this.value.length;
                    counter.textContent = currentLength + '/' + maxLength;
                    
                    // Update styling based on character count
                    counter.className = 'char-count';
                    if (currentLength > maxLength * 0.9) {
                        counter.classList.add('warning');
                    }
                    if (currentLength >= maxLength) {
                        counter.classList.add('error');
                    }
                });
            }
        }

        // Initialize character counters
        setupCharacterCount('companyAddress', 'addressCharCount', MAX_INPUT_LENGTH);
        setupCharacterCount('comments', 'commentsCharCount', MAX_COMMENT_LENGTH);

        // Enhanced form validation
        function validateForm(formData) {
            let isValid = true;
            const errors = {};
            
            // Clear previous errors
            document.querySelectorAll('.error').forEach(error => {
                error.style.display = 'none';
            });

            // Validate name
            const name = formData.get('fullName').trim();
            if (!name) {
                errors.nameError = 'Please enter your full name';
                isValid = false;
            } else if (name.length < 2) {
                errors.nameError = 'Name must be at least 2 characters long';
                isValid = false;
            } else if (name.length > MAX_INPUT_LENGTH) {
                errors.nameError = 'Name is too long';
                isValid = false;
            }

            // Validate email
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            const email = formData.get('email').trim();
            if (!email) {
                errors.emailError = 'Please enter your email address';
                isValid = false;
            } else if (!emailRegex.test(email)) {
                errors.emailError = 'Please enter a valid email address';
                isValid = false;
            } else if (email.length > MAX_INPUT_LENGTH) {
                errors.emailError = 'Email address is too long';
                isValid = false;
            }

            // Validate phone (if provided)
            const phone = formData.get('phone').trim();
            if (phone) {
                const phoneRegex = /^[\+]?[\d\s\-\(\)]{10,20}$/;
                if (!phoneRegex.test(phone)) {
                    errors.phoneError = 'Please enter a valid phone number';
                    isValid = false;
                }
            }

            // Validate company
            const company = formData.get('company').trim();
            if (!company) {
                errors.companyError = 'Please enter your company name';
                isValid = false;
            } else if (company.length > MAX_INPUT_LENGTH) {
                errors.companyError = 'Company name is too long';
                isValid = false;
            }

            // Validate job title
            const jobTitle = formData.get('jobTitle').trim();
            if (!jobTitle) {
                errors.jobTitleError = 'Please enter your job title';
                isValid = false;
            } else if (jobTitle.length > MAX_INPUT_LENGTH) {
                errors.jobTitleError = 'Job title is too long';
                isValid = false;
            }

            // Validate company address
            const companyAddress = formData.get('companyAddress').trim();
            if (!companyAddress) {
                errors.companyAddressError = 'Please enter your company address';
                isValid = false;
            } else if (companyAddress.length > MAX_INPUT_LENGTH) {
                errors.companyAddressError = 'Company address is too long';
                isValid = false;
            }

            // Validate attendance
            const attendance = formData.get('attendance');
            if (!attendance) {
                errors.attendanceError = 'Please select your attendance status';
                isValid = false;
            } else if (!['yes', 'no', 'maybe'].includes(attendance)) {
                errors.attendanceError = 'Please select a valid attendance option';
                isValid = false;
            }

            // Validate comments length
            const comments = formData.get('comments').trim();
            if (comments.length > MAX_COMMENT_LENGTH) {
                errors.commentsError = 'Comments are too long';
                isValid = false;
            }

            // Display errors
            Object.keys(errors).forEach(errorId => {
                const errorElement = document.getElementById(errorId);
                if (errorElement) {
                    errorElement.textContent = errors[errorId];
                    errorElement.style.display = 'block';
                }
            });

            return isValid;
        }

        // Show loading state
        function showLoading(show) {
            const submitBtn = document.getElementById('submitBtn');
            const loading = document.getElementById('loading');
            
            if (show) {
                submitBtn.disabled = true;
                loading.style.display = 'block';
            } else {
                submitBtn.disabled = false;
                loading.style.display = 'none';
            }
        }

        // Show message with better error handling
        function showMessage(message, type = 'success') {
            // Hide all message types first
            ['successMessage', 'infoMessage', 'errorMessage'].forEach(id => {
                document.getElementById(id).style.display = 'none';
            });
            
            const messageDiv = document.getElementById(type + 'Message');
            if (messageDiv) {
                messageDiv.innerHTML = message;
                messageDiv.style.display = 'block';
                
                // Scroll to message
                messageDiv.scrollIntoView({ behavior: 'smooth' });
                
                // Auto-hide success messages after 5 seconds
                if (type === 'success') {
                    setTimeout(() => {
                        messageDiv.style.display = 'none';
                    }, 5000);
                }
            }
        }

        // Enhanced AJAX with better error handling
        function makeRequest(url, data) {
            return fetch(url, {
                method: 'POST',
                body: data,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            }).then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            });
        }

        // Handle form submission with comprehensive error handling
        document.getElementById('rsvpForm').addEventListener('submit', function(e) {
            e.preventDefault();

            const formData = new FormData(this);
            
            if (!validateForm(formData)) {
                return;
            }

            showLoading(true);

            // First check if guest exists
            const checkData = new FormData();
            checkData.append('action', 'check_guest');
            checkData.append('email', formData.get('email'));

            makeRequest('', checkData)
                .then(data => {
                    if (data.exists) {
                        // Guest exists, ask if they want to update
                        if (confirm('A guest with this email already exists. Do you want to update their information?')) {
                            formData.append('action', 'update_guest');
                            return makeRequest('', formData);
                        } else {
                            showLoading(false);
                            return null;
                        }
                    } else {
                        // New guest, add them
                        formData.append('action', 'add_guest');
                        return makeRequest('', formData);
                    }
                })
                .then(data => {
                    showLoading(false);
                    
                    if (data) {
                        if (data.success) {
                            showMessage('🎊 Thank you for your RSVP! Your response has been recorded successfully.', 'success');
                            document.getElementById('rsvpForm').reset();
                            
                            // Reset character counters
                            document.getElementById('addressCharCount').textContent = '0/' + MAX_INPUT_LENGTH;
                            document.getElementById('commentsCharCount').textContent = '0/' + MAX_COMMENT_LENGTH;
                            
                            // Reload page after 3 seconds to update guest count
                            setTimeout(() => {
                                window.location.reload();
                            }, 3000);
                        } else {
                            showMessage('❌ Error: ' + (data.message || 'Unknown error occurred'), 'error');
                        }
                    }
                })
                .catch(error => {
                    showLoading(false);
                    console.error('Error:', error);
                    
                    let errorMessage = 'An unexpected error occurred. Please try again.';
                    if (error.message.includes('HTTP error')) {
                        errorMessage = 'Server error occurred. Please try again later.';
                    } else if (error.message.includes('Failed to fetch')) {
                        errorMessage = 'Network error. Please check your connection and try again.';
                    }
                    
                    showMessage('❌ ' + errorMessage, 'error');
                });
        });

        // Input sanitization on client side (additional layer)
        document.querySelectorAll('input[type="text"], input[type="email"], input[type="tel"], textarea').forEach(input => {
            input.addEventListener('input', function() {
                // Remove potentially dangerous characters
                this.value = this.value.replace(/[<>]/g, '');
            });
        });

        // Auto-save draft functionality (optional enhancement)
        let draftTimer;
        function saveDraft() {
            const formData = new FormData(document.getElementById('rsvpForm'));
            const draft = {};
            for (let [key, value] of formData.entries()) {
                draft[key] = value;
            }
            try {
                sessionStorage.setItem('rsvp_draft', JSON.stringify(draft));
            } catch (e) {
                // SessionStorage not available or full
                console.warn('Could not save draft:', e);
            }
        }

        function loadDraft() {
            try {
                const draft = sessionStorage.getItem('rsvp_draft');
                if (draft) {
                    const data = JSON.parse(draft);
                    Object.keys(data).forEach(key => {
                        const field = document.querySelector(`[name="${key}"]`);
                        if (field) {
                            if (field.type === 'checkbox') {
                                field.checked = data[key] === 'on';
                            } else {
                                field.value = data[key];
                            }
                        }
                    });
                    
                    // Update character counters
                    const addressField = document.getElementById('companyAddress');
                    const commentsField = document.getElementById('comments');
                    if (addressField && addressField.value) {
                        document.getElementById('addressCharCount').textContent = addressField.value.length + '/' + MAX_INPUT_LENGTH;
                    }
                    if (commentsField && commentsField.value) {
                        document.getElementById('commentsCharCount').textContent = commentsField.value.length + '/' + MAX_COMMENT_LENGTH;
                    }
                }
            } catch (e) {
                console.warn('Could not load draft:', e);
            }
        }

        // Auto-save on form changes
        document.getElementById('rsvpForm').addEventListener('input', function() {
            clearTimeout(draftTimer);
            draftTimer = setTimeout(saveDraft, 1000); // Save draft after 1 second of inactivity
        });

        // Load draft on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadDraft();
        });

        // Clear draft on successful submission
        function clearDraft() {
            try {
                sessionStorage.removeItem('rsvp_draft');
            } catch (e) {
                console.warn('Could not clear draft:', e);
            }
        }

        // Enhanced phone number formatting
        document.getElementById('phone').addEventListener('input', function() {
            let value = this.value.replace(/\D/g, ''); // Remove all non-digits
            
            if (value.length > 0) {
                if (value.length <= 3) {
                    value = value;
                } else if (value.length <= 6) {
                    value = value.substring(0, 3) + '-' + value.substring(3);
                } else if (value.length <= 10) {
                    value = value.substring(0, 3) + '-' + value.substring(3, 6) + '-' + value.substring(6);
                } else {
                    // For international numbers, just add dashes every 3-4 digits
                    value = value.substring(0, 10);
                    value = value.substring(0, 3) + '-' + value.substring(3, 6) + '-' + value.substring(6);
                }
            }
            
            this.value = value;
        });

        // Prevent form submission with Enter key in text inputs (except textarea)
        document.querySelectorAll('input[type="text"], input[type="email"], input[type="tel"]').forEach(input => {
            input.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    // Move to next field
                    const fields = Array.from(document.querySelectorAll('input, select, textarea'));
                    const currentIndex = fields.indexOf(this);
                    if (currentIndex < fields.length - 1) {
                        fields[currentIndex + 1].focus();
                    }
                }
            });
        });

        // Add visual feedback for form validation
        document.querySelectorAll('input[required], select[required], textarea[required]').forEach(field => {
            field.addEventListener('blur', function() {
                if (this.value.trim()) {
                    this.style.borderColor = '#2ecc71';
                } else {
                    this.style.borderColor = '#e74c3c';
                }
            });
            
            field.addEventListener('focus', function() {
                this.style.borderColor = '#667eea';
            });
        });

        // Accessibility improvements
        document.addEventListener('keydown', function(e) {
            // Escape key closes any visible messages
            if (e.key === 'Escape') {
                document.querySelectorAll('.success[style*="block"], .info[style*="block"]').forEach(msg => {
                    msg.style.display = 'none';
                });
            }
        });

        // Add tooltips for better UX
        const tooltips = {
            'fullName': 'Enter your complete first and last name',
            'email': 'We\'ll use this to send you event updates if requested',
            'phone': 'Optional - for urgent event communications only',
            'company': 'The organization you work for',
            'jobTitle': 'Your current position or role',
            'companyAddress': 'Full business address including city and country',
            'guests': 'How many additional people will you bring?',
            'comments': 'Any dietary restrictions, accessibility needs, or special requests'
        };

        Object.keys(tooltips).forEach(fieldId => {
            const field = document.getElementById(fieldId);
            if (field) {
                field.title = tooltips[fieldId];
            }
        });
    </script>
</body>
</html>