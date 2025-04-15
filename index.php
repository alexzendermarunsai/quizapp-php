<?php
// Start the session
session_start();

// --- Configuration ---
$json_file = 'questions_bank.json'; // Make sure this file exists and is readable

// --- Functions ---

/**
 * Loads quiz questions from the JSON file.
 * Returns an array of questions or false on failure.
 * @param string $filename
 * @return array|false
 */
function load_questions(string $filename) {
    // Check if the file exists and is readable
    if (!file_exists($filename) || !is_readable($filename)) {
        error_log("Error: Questions file '{$filename}' not found or not readable."); // Log error
        return false;
    }
    $json_data = file_get_contents($filename);
    if ($json_data === false) {
        error_log("Error: Could not read content from file '{$filename}'."); // Log error
        return false;
    }
    $questions = json_decode($json_data, true); // Use true for associative array
    // Check for JSON decoding errors
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("Error parsing JSON from '{$filename}': " . json_last_error_msg()); // Log error
        return false;
    }
    // Ensure the result is an array (even if empty)
    return is_array($questions) ? array_values($questions) : false; // array_values to ensure numeric indexes 0, 1, 2...
}

/**
 * Resets the quiz state.
 */
function reset_quiz(): void {
    // Unset specific session variables related to the quiz
    unset($_SESSION['current_question_index']);
    unset($_SESSION['score']);
    unset($_SESSION['results']);

    // Redirect to clean URL after resetting (remove query parameters)
    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
    exit; // Stop script execution immediately after sending the redirect header
}

// --- Main Logic ---

// 1. Load questions FIRST - Critical for determining quiz structure
$questions = load_questions($json_file);
if ($questions === false) {
    // Display a user-friendly error message and stop execution
    die("Error: Could not load or parse the questions bank file ('" . htmlspecialchars($json_file) . "'). Please ensure it exists, is readable, and contains valid JSON. Check server logs for more details.");
}
// 2. Define $total_questions IMMEDIATELY after successful load
$total_questions = count($questions);
// Handle case where the JSON file is valid but empty
if ($total_questions === 0) {
     die("Error: The questions bank file ('" . htmlspecialchars($json_file) . "') is empty or contains no valid questions.");
}

// 3. Handle Reset Action (includes exit, so do this before depending on session vars that might be reset)
if (isset($_GET['action']) && $_GET['action'] === 'reset') {
    reset_quiz(); // This function includes an exit() call
}

// 4. Initialize essential session variables if they don't exist (First visit or session expired)
if (!isset($_SESSION['current_question_index'])) {
    $_SESSION['current_question_index'] = 0;
}
if (!isset($_SESSION['score'])) {
    $_SESSION['score'] = 0;
}
if (!isset($_SESSION['results'])) {
    $_SESSION['results'] = []; // Stores results for each question index
}

// 5. Define $current_index based on session AFTER initialization and potential reset
$current_index = (int)$_SESSION['current_question_index'];
if ($current_index < 0 || $current_index > $total_questions) {
    $_SESSION['current_question_index'] = 0;
    $current_index = 0;
}

// 6. Handle Navigation (Previous/Next) using GET (needs $current_index)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    if ($_GET['action'] === 'next' && $current_index < $total_questions) {
        $_SESSION['current_question_index']++;
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    } elseif ($_GET['action'] === 'prev' && $current_index > 0) {
        $_SESSION['current_question_index']--;
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
}

// 7. Handle submitted answer (POST request)
$feedback_for_current_question = null;
// Check if the submission is for a *non-simulation* question
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['question_index']) && isset($questions[(int)$_POST['question_index']])) {

    $submitted_index = (int)$_POST['question_index'];
    $question_data = $questions[$submitted_index];

    // Proceed only if it's not a simulation and an answer was actually submitted
    if (isset($_POST['answer']) && !($question_data['is_simulation'] ?? false) && !empty($question_data['options'])) {
        $submitted_answer_raw = $_POST['answer']; // Could be string or array

        // Check submission is for the *correct* question and hasn't been answered yet
        if ($submitted_index === $current_index && !isset($_SESSION['results'][$submitted_index])) {

            $correct_answer_str = $question_data['correct_answer'] ?? null; // e.g., "B" or "B C" or null for simulation
            $explanation = $question_data['explanation'] ?? 'No explanation provided.';
            $is_correct = false;

            // Check if correct_answer exists before trying to use it
            if ($correct_answer_str !== null) {
                // Determine if it's multiple choice based on space in correct answer string (workaround)
                $is_multiple_choice = is_string($correct_answer_str) && strpos($correct_answer_str, ' ') !== false;

                if ($is_multiple_choice) {
                    // --- Handle Multiple Choice Answer ---
                    $correct_answer_array = explode(' ', $correct_answer_str);
                    sort($correct_answer_array); // Sort for consistent comparison

                    // Ensure submitted answer is an array, clean it up
                    $submitted_answer_array = is_array($submitted_answer_raw) ? array_values($submitted_answer_raw) : []; // Ensure it's an array
                    sort($submitted_answer_array); // Sort for comparison

                     // Check if the submitted answer exists in the options (basic validation)
                    $valid_submission = true;
                    if (empty($submitted_answer_array)) { // Cannot submit empty for multiple choice
                        $valid_submission = false;
                    } else {
                        foreach ($submitted_answer_array as $ans) {
                            if (!isset($question_data['options'][$ans])) {
                                 $valid_submission = false;
                                 break;
                            }
                        }
                    }


                    if ($valid_submission) {
                        // Compare sorted arrays
                        $is_correct = ($submitted_answer_array == $correct_answer_array);

                        if ($is_correct) {
                            $_SESSION['score']++; // Increment score only if ALL correct answers are selected and no incorrect ones
                        }

                        $_SESSION['results'][$submitted_index] = [
                            'correct' => $is_correct,
                            'your_answer' => $submitted_answer_array, // Store as array
                            'correct_answer' => $correct_answer_array, // Store as array
                            'explanation' => $explanation,
                            'question_text' => $question_data['question_text'],
                            'options' => $question_data['options']
                        ];
                         $feedback_for_current_question = $_SESSION['results'][$submitted_index];
                    } else {
                         error_log("Invalid or empty multiple choice answer submitted for index {$submitted_index}");
                         // Optionally set feedback indicating invalid submission
                         $feedback_for_current_question = ['correct' => false, 'your_answer' => $submitted_answer_array, 'correct_answer' => $correct_answer_array, 'explanation' => 'Invalid selection.', 'question_text' => $question_data['question_text'], 'options' => $question_data['options']];
                    }


                } else {
                    // --- Handle Single Choice Answer ---
                    $submitted_answer_str = is_string($submitted_answer_raw) ? $submitted_answer_raw : ''; // Ensure it's a string

                    // Check if the submitted answer exists in the options (basic validation)
                    if (isset($question_data['options'][$submitted_answer_str])) {
                        $is_correct = ($submitted_answer_str === $correct_answer_str);

                        if ($is_correct) {
                            $_SESSION['score']++;
                        }

                        $_SESSION['results'][$submitted_index] = [
                            'correct' => $is_correct,
                            'your_answer' => $submitted_answer_str, // Store as string
                            'correct_answer' => $correct_answer_str, // Store as string
                            'explanation' => $explanation,
                            'question_text' => $question_data['question_text'],
                            'options' => $question_data['options']
                        ];
                         $feedback_for_current_question = $_SESSION['results'][$submitted_index];
                    } else {
                         error_log("Invalid single choice answer '{$submitted_answer_str}' submitted for index {$submitted_index}");
                         // Optionally set feedback indicating invalid submission
                         $feedback_for_current_question = ['correct' => false, 'your_answer' => $submitted_answer_str, 'correct_answer' => $correct_answer_str, 'explanation' => 'Invalid selection.', 'question_text' => $question_data['question_text'], 'options' => $question_data['options']];
                    }
                }
            } else {
                // Handle case where correct_answer is null (should only be simulations, but defensive check)
                error_log("Attempted to process answer for question index {$submitted_index} which has null correct_answer.");
            }

            // Optional PRG redirect
            // header("Location: " . $_SERVER['PHP_SELF']);
            // exit;
        }
    }
    // If already answered, retrieve feedback from session
    elseif (isset($_SESSION['results'][$submitted_index])) {
         $feedback_for_current_question = $_SESSION['results'][$submitted_index];
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CompTIA Pentester+ PT0-003 Quiz APP</title>
    <link rel="stylesheet" href="style.css"> <?php /* Link to your CSS file */ ?>
</head>
<body>

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

        <?php // --- Popup Element (Initially Hidden) --- ?>
        <div id="qr-popup" class="popup">
            <button class="popup-close" title="Close">×</button> <?php // Simple close button ?>
            <h2>Thank You!</h2>
            <p>If you found this helpful, consider showing your appreciation or buy me a cup of coffee ☕:</p>
            <img id="qr-code-img" src="qr.jpeg" alt="Appreciation QR Code" width="150" height="150">
            <?php // !!! IMPORTANT: Replace "path/to/your/qr-code.png" with the actual path to your QR code image file !!! ?>
            <p style="font-size: 0.8em;">(Scan the code)</p>
        </div>

        <footer class="site-footer">
        <?php // --- Footer Text with integrated QR Link --- ?>
        <center>
        <p>
            CompTIA Pentester+ PT0-003 Quiz App - <?php echo date('Y'); ?><br>
            <a href="#" id="show-qr-popup-link" class="qr-link" title="Show Appreciation QR Code">(Like the App ?😍)</a>
        </p>
        </center>

    </footer>

    <?php // --- JavaScript for Popup --- ?>
    <script>
        // Get references to the elements
        const showPopupLink = document.getElementById('show-qr-popup-link'); // Use the new ID
        const qrPopup = document.getElementById('qr-popup');
        const closePopupButton = qrPopup.querySelector('.popup-close'); // Find close button inside popup

        // Event listener for the trigger link
        if (showPopupLink) { // Check if the link exists before adding listener
            showPopupLink.addEventListener('click', function(event) {
                event.preventDefault(); // Prevent default link behavior (jumping to #)
                qrPopup.style.display = 'block'; // Show the popup
            });
        }

        // Event listener for the close button
        if (closePopupButton) { // Check if the close button exists
            closePopupButton.addEventListener('click', function() {
                qrPopup.style.display = 'none'; // Hide the popup
            });
        }


        // Optional: Close popup if user clicks outside of it
        window.addEventListener('click', function(event) {
            // Check if the click is outside the popup and not on the trigger link itself
            if (showPopupLink && event.target !== qrPopup && !qrPopup.contains(event.target) && event.target !== showPopupLink) {
                 if (qrPopup.style.display === 'block') { // Only hide if it's currently visible
                     qrPopup.style.display = 'none';
                 }
            }
        });
    </script>

</body>
</html>