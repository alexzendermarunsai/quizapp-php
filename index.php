<?php
// Start the session
session_start();

// --- Configuration ---
$json_file = 'questions_bank.json'; // Ensure this file exists and is readable

// --- Utility Functions ---

/**
 * Loads and validates JSON data from a file.
 * @param string $filename
 * @return array|false
 */
function load_json_file(string $filename) {
    if (!file_exists($filename) || !is_readable($filename)) {
        error_log("Error: File '{$filename}' not found or not readable.");
        return false;
    }
    $data = file_get_contents($filename);
    if ($data === false) {
        error_log("Error: Could not read file '{$filename}'.");
        return false;
    }
    $decoded = json_decode($data, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("Error parsing JSON: " . json_last_error_msg());
        return false;
    }
    return is_array($decoded) ? $decoded : false;
}

/**
 * Resets the quiz session and redirects to the main page.
 */
function reset_quiz(): void {
    session_unset();
    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
    exit;
}

/**
 * Handles navigation actions (next/prev).
 * @param int $currentIndex
 * @param int $totalQuestions
 */
function handle_navigation(int &$currentIndex, int $totalQuestions): void {
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
        if ($_GET['action'] === 'next' && $currentIndex < $totalQuestions) {
            $_SESSION['current_question_index']++;
        } elseif ($_GET['action'] === 'prev' && $currentIndex > 0) {
            $_SESSION['current_question_index']--;
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
}

/**
 * Initializes session variables for the quiz.
 * @param int $totalQuestions
 */
function initialize_session(int $totalQuestions): void {
    if (!isset($_SESSION['current_question_index'])) {
        $_SESSION['current_question_index'] = 0;
    }
    if (!isset($_SESSION['score'])) {
        $_SESSION['score'] = 0;
    }
    if (!isset($_SESSION['results'])) {
        $_SESSION['results'] = [];
    }
    if ($_SESSION['current_question_index'] >= $totalQuestions) {
        $_SESSION['current_question_index'] = 0;
    }
}

/**
 * Sets the theme based on user selection.
 */
function set_theme(): void {
    if (isset($_GET['theme'])) {
        $_SESSION['theme'] = $_GET['theme'];
    }
    if (!isset($_SESSION['theme'])) {
        $_SESSION['theme'] = 'default-theme'; // Default theme
    }
}

set_theme();
$theme_class = htmlspecialchars($_SESSION['theme']);

// --- Main Logic ---

$questions = load_json_file($json_file);
if ($questions === false) {
    die("Error: Could not load or parse the questions bank file. Please check the server logs.");
}

$total_questions = count($questions);
if ($total_questions === 0) {
    die("Error: The questions bank file is empty.");
}

if (isset($_GET['action']) && $_GET['action'] === 'reset') {
    reset_quiz();
}

initialize_session($total_questions);
$current_index = (int)$_SESSION['current_question_index'];
handle_navigation($current_index, $total_questions);

// Ensure feedback is displayed after submitting an answer
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['question_index'])) {
    $submitted_index = (int)$_POST['question_index'];
    if (isset($questions[$submitted_index])) {
        $feedback_for_current_question = $_SESSION['results'][$submitted_index] ?? null;
    }
}

// Handle submitted answers
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['question_index'])) {
    $submitted_index = (int)$_POST['question_index'];
    if (isset($questions[$submitted_index])) {
        $current_question = $questions[$submitted_index];
        $submitted_answer = $_POST['answer'] ?? null;

        if ($submitted_answer !== null) {
            $correct_answer = $current_question['correct_answer'];
            $is_correct = $submitted_answer == $correct_answer;

            // Update session results
            $_SESSION['results'][$submitted_index] = [
                'correct' => $is_correct,
                'your_answer' => $submitted_answer,
                'correct_answer' => $correct_answer,
                'explanation' => $current_question['explanation'] ?? 'No explanation provided.'
            ];

            // Update score if correct
            if ($is_correct) {
                $_SESSION['score']++;
            }
        }
    }
}

// Add theme class to selected-correct and selected-incorrect dynamically
if (isset($feedback_to_display)) {
    $feedback_class = $feedback_to_display['correct'] ? 'selected-correct' : 'selected-incorrect';
    $theme_class = htmlspecialchars($_SESSION['theme']);
    $feedback_class .= ' ' . $theme_class;
}

// Render the quiz interface (HTML output remains unchanged)
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CompTIA Pentester+ PT0-003 Quiz APP</title>
    <link rel="stylesheet" href="style.css"> <?php /* Link to your CSS file */ ?>
</head>
<body class="<?php echo $theme_class; ?>">

    <div class="theme-selector">
        <a href="?theme=default-theme" class="theme-link">Default Theme</a>
        <a href="?theme=minimalist-theme" class="theme-link">Minimalist Theme</a>
        <a href="?theme=hacker-theme" class="theme-link">Hacker Theme</a>
    </div>

    <h1>CompTIA Pentester+ PT0-003 Quiz</h1>

    <?php // Display Score & Progress Info
    echo '<div class="score-info">Current Score: ' . htmlspecialchars($_SESSION['score']) . ' / ' . $total_questions . '</div>';
    ?>

    <?php // Check if the quiz is finished
    if ($current_index >= $total_questions): ?>
        <div class="results-container">
            <h2>Quiz Completed!</h2>
            <?php
            $final_score = $_SESSION['score'];
            $percentage = ($total_questions > 0) ? round(($final_score / $total_questions) * 100, 1) : 0;
            ?>
            <p>Your final score is: <?php echo htmlspecialchars($final_score); ?> out of <?php echo $total_questions; ?></p>
            <p>(<?php echo $percentage; ?>%)</p>

            <?php // Optional: Display a summary of answers
                if (!empty($_SESSION['results']) || $total_questions > 0) { // Show review section even if no answers yet, just list questions
                    echo "<h3>Review Your Answers:</h3>";
                    echo "<ol class='results-summary'>";
                    for ($i = 0; $i < $total_questions; $i++) {
                        if (isset($questions[$i]) && ($questions[$i]['is_simulation'] ?? false)) {
                             $q_num_ref = isset($questions[$i]['question_number']) ? " (#" . htmlspecialchars($questions[$i]['question_number']) . ")" : "";
                             $q_text_snippet = isset($questions[$i]['question_text']) ? htmlspecialchars(substr($questions[$i]['question_text'], 0, 50)) . "..." : "[No Text]";
                             echo "<li><strong>Question " . ($i + 1) . $q_num_ref . ":</strong> " . $q_text_snippet . " <span style='color: #666;'>(Simulation - Skipped)</span></li>";
                        } elseif (isset($_SESSION['results'][$i])) {
                            $result = $_SESSION['results'][$i];
                            $status = $result['correct'] ? 'Correct' : 'Incorrect';
                            $status_class = $result['correct'] ? 'correct' : 'incorrect';
                            $q_num_ref = isset($questions[$i]['question_number']) ? " (#" . htmlspecialchars($questions[$i]['question_number']) . ")" : "";

                            // Handle displaying single vs multiple answers
                            $your_answer_display = is_array($result['your_answer']) ? implode(', ', $result['your_answer']) : $result['your_answer'];
                            $correct_answer_display = is_array($result['correct_answer']) ? implode(', ', $result['correct_answer']) : $result['correct_answer'];
                             if (empty($your_answer_display)) $your_answer_display = "[No Answer Selected]"; // Handle empty submission display

                            echo "<li>";
                            echo "<strong>Question " . ($i + 1) . $q_num_ref . ":</strong> " . htmlspecialchars(substr($result['question_text'], 0, 50)) . "... "; // Show snippet
                            echo "<span class='{$status_class}'>({$status})</span> - ";
                            echo "You answered: " . htmlspecialchars($your_answer_display) . ". ";
                            if (!$result['correct']) {
                                echo "Correct was: " . htmlspecialchars($correct_answer_display) . ".";
                            }
                            echo "</li>";
                        } else {
                             // Question exists but wasn't answered (and wasn't a simulation)
                             $q_num_ref = isset($questions[$i]['question_number']) ? " (#" . htmlspecialchars($questions[$i]['question_number']) . ")" : "";
                             $q_text_snippet = isset($questions[$i]['question_text']) ? htmlspecialchars(substr($questions[$i]['question_text'], 0, 50)) . "..." : "[No Text]";
                             echo "<li><strong>Question " . ($i + 1) . $q_num_ref . ":</strong> " . $q_text_snippet . " <span style='color: orange;'>(Not Answered)</span></li>";
                         }
                    }
                    echo "</ol>";
                }
            ?>

            <a href="?action=reset" class="reset-link button">Take Quiz Again</a>
        </div>
        <?php // Navigation on results page: Only allow going back
        if ($total_questions > 0):
        ?>
        <div class="navigation results-nav">
             <a href="?action=prev" class="nav-button <?php echo ($current_index <= 0) ? 'disabled' : ''; ?>">Previous Question</a>
             <span> </span> <?php /* Spacer */ ?>
        </div>
        <?php endif; ?>

    <?php // Else, display the current question or simulation prompt
    else:
        if (!isset($questions[$current_index])) {
             die("Error: Question data missing for index {$current_index}. Please reset the quiz or contact the administrator.");
        }
        $current_question = $questions[$current_index];
        // Treat as simulation if flag is true OR if options/correct_answer are missing/null/empty
        $is_simulation = ($current_question['is_simulation'] ?? false)
                      || empty($current_question['options'])
                      || !isset($current_question['correct_answer'])
                      || $current_question['correct_answer'] === null;

        $has_been_answered = !$is_simulation && isset($_SESSION['results'][$current_index]);
        $feedback_to_display = $feedback_for_current_question ?? ($_SESSION['results'][$current_index] ?? null);

        // Determine if current non-simulation question is multiple choice
        $is_multiple_choice = !$is_simulation && is_string($current_question['correct_answer']) && strpos($current_question['correct_answer'], ' ') !== false;

        // Prepare correct answers array for highlighting logic (even for single answers)
        $correct_answer_array = [];
        if (!$is_simulation && isset($current_question['correct_answer']) && is_string($current_question['correct_answer'])) {
            if ($is_multiple_choice) {
                $correct_answer_array = explode(' ', $current_question['correct_answer']);
            } else {
                $correct_answer_array = [$current_question['correct_answer']]; // Treat single as array for consistency
            }
            sort($correct_answer_array); // Sort for consistent checking
        }

    ?>
        <div class="progress-info">
            <?php echo $is_simulation ? "Simulation" : "Question"; ?> <?php echo $current_index + 1; ?> of <?php echo $total_questions; ?>
        </div>

        <div class="question-container <?php echo $is_simulation ? 'simulation-question' : ''; ?>">

            <?php if ($is_simulation): // --- Display Simulation --- ?>
                 <p class="question-text"><?php echo htmlspecialchars($current_question['question_text'] ?? 'Simulation details missing.'); ?></p>
                 <?php // ADDED: Display Question Number Reference ?>
                 <?php if (!empty($current_question['question_number'])): ?>
                    <p class="question-reference">Reference: Question #: <?php echo htmlspecialchars($current_question['question_number']); ?></p>
                 <?php endif; ?>

                 <?php if (!empty($current_question['simulation_details'])): ?>
                    <div class="simulation-details">
                        <strong>Instructions:</strong>
                        <pre><?php echo htmlspecialchars($current_question['simulation_details']); ?></pre>
                    </div>
                 <?php endif; ?>
                 <p><em>This is a simulation question. Review the details and proceed to the next question.</em></p>
                 <?php // No form, no submit button for simulations ?>

            <?php else: // --- Display Normal Question (Single or Multiple Choice) --- ?>
                <form action="index.php" method="post" id="quiz-form">
                    <fieldset <?php echo $has_been_answered ? 'disabled' : ''; ?>>
                        <legend class="question-text"><?php echo htmlspecialchars($current_question['question_text']); ?></legend>
                         <?php // ADDED: Display Question Number Reference ?>
                         <?php if (!empty($current_question['question_number'])): ?>
                             <p class="question-reference">Reference: Question #: <?php echo htmlspecialchars($current_question['question_number']); ?></p>
                         <?php endif; ?>

                        <div class="options">
                            <?php foreach ($current_question['options'] as $key => $option_text):
                                $option_id = "option_" . $current_index . "_" . $key;
                                $input_type = $is_multiple_choice ? 'checkbox' : 'radio';
                                $input_name = $is_multiple_choice ? 'answer[]' : 'answer';
                                $is_checked = false;
                                $label_class = 'option-label';
                                $is_correct_option = in_array((string)$key, $correct_answer_array, true); // Check if this option is one of the correct answers (strict type comparison)

                                if ($feedback_to_display) { // If there's feedback (meaning it's answered)
                                     $label_class .= ' disabled';
                                     // Ensure 'your_answer' is treated as an array for checking existence
                                     $your_answers = is_array($feedback_to_display['your_answer']) ? $feedback_to_display['your_answer'] : [$feedback_to_display['your_answer']];
                                     // Check if this option key was one of the selected answers
                                     $is_checked = in_array((string)$key, $your_answers, true);

                                     if ($is_checked) { // Style the selected answer
                                         $is_selected_option_correct = in_array((string)$key, $correct_answer_array, true);
                                         // Style based on whether *this specific* selection was correct or incorrect
                                         $label_class .= $is_selected_option_correct ? ' selected-correct' : ' selected-incorrect';
                                     }
                                     // Highlight the *actual* correct answer(s) if the user got it wrong overall, AND this option wasn't checked by the user
                                     elseif (!$feedback_to_display['correct'] && $is_correct_option) {
                                        $label_class .= ' correct-answer-highlight';
                                     }
                                }
                            ?>
                                <label for="<?php echo $option_id; ?>" class="<?php echo trim($label_class); ?>">
                                    <input type="<?php echo $input_type; ?>"
                                           name="<?php echo $input_name; ?>"
                                           value="<?php echo htmlspecialchars($key); ?>"
                                           id="<?php echo $option_id; ?>"
                                           <?php echo $has_been_answered ? 'disabled' : ($input_type == 'radio' ? 'required' : ''); // Only radios required ?>
                                           <?php echo $is_checked ? 'checked' : ''; ?>
                                           >
                                    <?php echo htmlspecialchars($key . '. ' . $option_text); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
                    <input type="hidden" name="question_index" value="<?php echo $current_index; ?>">

                    <?php // Wrap the button in a div for centering ?>
                    <div class="submit-button-container">
                        <?php if (!$is_simulation): // Only show submit if not simulation ?>
                            <button type="submit" class="button" <?php echo $has_been_answered ? 'disabled' : ''; ?>>Submit Answer</button>
                        <?php endif; ?>
                    </div>
                    <?php // End button wrapper ?>

                </form>

                <?php // Display feedback if available (for non-simulations)
                // ... (rest of the feedback code) ...
                ?>

                <?php // Display feedback if available (for non-simulations)
                if ($feedback_to_display):
                    // Prepare display strings for answers (handle arrays)
                     $your_answer_display = is_array($feedback_to_display['your_answer']) ? implode(', ', $feedback_to_display['your_answer']) : $feedback_to_display['your_answer'];
                     $correct_answer_display = is_array($feedback_to_display['correct_answer']) ? implode(', ', $feedback_to_display['correct_answer']) : $feedback_to_display['correct_answer'];
                     if (empty($your_answer_display)) $your_answer_display = "[No Answer Selected]"; // Handle empty submission display

                ?>
                    <div class="feedback <?php echo $feedback_to_display['correct'] ? 'correct' : 'incorrect'; ?>">
                        <?php if ($feedback_to_display['correct']): ?>
                            <p><strong>Correct!</strong></p>
                        <?php else: ?>
                            <p><strong>Incorrect.</strong>
                               Your answer: <?php echo htmlspecialchars($your_answer_display); ?>. <br>
                               Correct answer<?php echo (is_array($feedback_to_display['correct_answer']) && count($feedback_to_display['correct_answer']) > 1) ? 's were' : ' was'; ?>: <?php echo htmlspecialchars($correct_answer_display); ?>.
                            </p>
                        <?php endif; ?>
                        <p><strong>Explanation:</strong></p>
                        <pre class="explanation"><?php echo htmlspecialchars($feedback_to_display['explanation'] ?? 'No explanation available.'); ?></pre>
                    </div>
                <?php endif; ?>

            <?php endif; // End of simulation vs normal question display ?>

        </div>

        <?php // Navigation Buttons ?>
        <div class="navigation">
            <a href="?action=prev" class="nav-button <?php echo ($current_index <= 0) ? 'disabled' : ''; ?>" <?php echo ($current_index <= 0) ? 'aria-disabled="true"' : ''; ?>>Previous</a>
            <?php
                $next_text = 'Next'; // Default
                if ($current_index >= $total_questions) { // Should only happen on results page, but check anyway
                     $next_text = 'Next'; // Or potentially hide/disable
                } elseif ($is_simulation) {
                    $next_text = ($current_index === $total_questions - 1) ? 'View Results' : 'Next Simulation/Question';
                } elseif ($current_index === $total_questions - 1) {
                    $next_text = 'View Results';
                } else {
                     $next_text = 'Next Question';
                }
            ?>
            <a href="?action=next" class="nav-button <?php echo ($current_index >= $total_questions) ? 'disabled' : ''; // Disable "Next" only when on the final results page itself ?>">
                 <?php echo $next_text; ?>
            </a>
        </div>

        <div style="text-align: center; margin-top: 20px;">
             <a href="?action=reset" class="reset-link reset-now">Reset Quiz Now</a>
        </div>

    <?php endif; // End of quiz display (question/simulation vs results) ?>

    <footer class="site-footer">
        <p>CompTIA Pentester+ PT0-003 Quiz App - <?php echo date('Y'); ?></p>
    </footer>

</body>
</html>